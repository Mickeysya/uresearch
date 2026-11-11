<?php

namespace App\Modules\Norhanis\Workflows;

use App\Modules\Core\Contracts\SuppliesCalendarEvents;
use App\Modules\Core\Contracts\ProvidesLinks;
use App\Modules\Core\Contracts\DecidesOneAtATime;
use App\Modules\Core\Contracts\DescribesApplicationProgress;
use App\Modules\Core\Contracts\DescribesApplicationDetails;
use App\Modules\Core\Contracts\Defaults\DescribesApplicationDetailsDefaults;
use App\Modules\Core\Contracts\SuppliesDecisionDates;
use App\Modules\Core\Contracts\SuppliesOverdueStatus;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Http\Controllers\ApplicationOverviewController;
use App\Modules\Norhanis\Models\PublicationDetail;
use Carbon\CarbonInterface;
use App\Modules\Norhanis\Support\Drafts;
use App\Modules\Norhanis\Support\SplitMemo;
use App\Modules\Norhanis\Support\ClaimablePublications;
use App\Modules\Norhanis\Notifications\ClaimNextStep;
use App\Modules\Norhanis\Notifications\LetterOfUndertakingNotice;
use App\Modules\Norhanis\Workflows\Concerns\ExpectsDecisionByCharter;
use App\Modules\Norhanis\Workflows\Concerns\NamesStageActions;
use App\Modules\Norhanis\Support\StageAction;

/**
 * Conference/journal funding applications and Letters of Undertaking.
 *
 * One fixed chain, no conditional routing: Supervisor -> Chair -> Non-Exec
 * CGS -> Senior Director CGS. Senior Director CGS has the final say.
 */
class PublicationWorkflow implements WorkflowModule, ProvidesLinks, SuppliesCalendarEvents, DecidesOneAtATime, SuppliesDecisionDates, SuppliesOverdueStatus, DescribesApplicationProgress, DescribesApplicationDetails
{
    use DescribesApplicationDetailsDefaults;

    /** expectedDecisionDate() and decisionIsOverdue(): see the trait. */
    use ExpectsDecisionByCharter;
    use NamesStageActions {
        describeProgress as protected describeStageProgress;
    }

    /**
     * What each approver does: only the Senior Director CGS, the last,
     * approves. The one place this module's wording lives; see
     * Support\StageAction.
     */
    public const ACTIONS = [
        'supervisor' => StageAction::Endorse,
        'chair' => StageAction::Endorse,
        'cgs_review' => StageAction::Review,
        'senior_director' => StageAction::Approve,
    ];

    public function key(): string
    {
        return 'publication';
    }

    public function label(): string
    {
        return 'Publication';
    }

    public function stages(?Application $application = null): array
    {
        return [
            $this->actionStage('supervisor', 'Lecturer/Supervisor', Role::SUPERVISOR),
            $this->actionStage('chair', 'Chair of Department', Role::CHAIR),
            $this->actionStage('cgs_review', 'Non-Executive CGS', Role::NON_EXEC_CGS),
            $this->actionStage('senior_director', 'Senior Director CGS', Role::SENIOR_DIRECTOR_CGS),
        ];
    }

    /**
     * The student's View details page: what each stage does next (see
     * NamesStageActions), and once a journal is approved, what its cost
     * centre leaves for the student, matching the follow-up email
     * (Support\PublicationFollowUp). Split Memo: send CGS the signed memo.
     * LOU: nothing, CGS pays directly. Pay and Claims: "Start your claim",
     * until a claim is in.
     */
    public function describeProgress(Application $application): array
    {
        $described = $this->describeStageProgress($application);

        if ($application->status !== Application::STATUS_APPROVED) {
            return $described;
        }

        $detail = PublicationDetail::where('application_id', $application->id)->first();

        if (SplitMemo::applies($application, $detail)) {
            $described['next'] = 'Because this application is funded by CGS and a grant, you must submit a signed memo to CGS.'
                .' The memo template was emailed to you. '.SplitMemo::INSTRUCTION.' '.SplitMemo::contact();
        } elseif ($detail?->usesCostCentre(PublicationDetail::LETTER_OF_UNDERTAKING)) {
            $described['next'] = LetterOfUndertakingNotice::LINE;
        } elseif ($detail?->usesCostCentre(PublicationDetail::PAY_AND_CLAIMS)) {
            // The claim is the student's to file, until one is in.
            if (ClaimablePublications::isClaimed($application->id)) {
                $described['next'] = 'Your claim for this publication has been submitted. Follow it under Student Claims.';
            } else {
                $described['next'] = ClaimNextStep::LINE;
                $described['needs_student'] = true;
                $described['actions'] = [[
                    'label' => 'Start your claim',
                    'url' => ClaimablePublications::claimUrl($application->id),
                    'primary' => true,
                ]];
            }
        }

        return $described;
    }

    /**
     * Core's View details page, "Your application": what the student filed,
     * labelled by the request type, with the Travel Details for a
     * conference only. The rest of the contract keeps Core's defaults.
     */
    public function details(Application $application): array
    {
        $detail = PublicationDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return [];
        }

        return [
            'Type of Request' => $detail->requestTypeLabel(),
            'Title of Paper' => $detail->title_of_paper,
            $detail->titleLabel() => $detail->title_of_conference_journal,
            'Organizer / Publisher' => $detail->organizer_publisher,
            $detail->feeLabelText() => $detail->currency_type.' '.number_format((float) $detail->conference_journal_fee, 2),
            'Cost Centre to be Utilized' => $detail->costCentreLabel(),
        ] + $detail->travelDetailRows();
    }

    public function summary(Application $application): string
    {
        $detail = PublicationDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return Drafts::summary($application, 'Publication application', 'title_of_paper', 'title_of_conference_journal');
        }

        return $detail->title_of_paper.', '.$detail->title_of_conference_journal;
    }

    /** "Publication Applications": everyone who has applied, for those who approve publications. */
    public function links(User $user): array
    {
        return ApplicationOverviewController::link($this, $user);
    }

    public function createRoute(): ?string
    {
        return 'publication.create';
    }

    public function queueRoute(): string
    {
        return 'publication.queue';
    }

    /** The student's own conference travel start, for applications still live. */
    public function calendarEvents(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($user->role !== Role::STUDENT) {
            return [];
        }

        $details = PublicationDetail::query()
            ->whereIn('application_id', Application::query()
                ->where('module_type', $this->key())
                ->where('student_id', $user->id)
                ->whereIn('status', [Application::STATUS_PENDING, Application::STATUS_APPROVED])
                ->select('id'))
            ->where('type_of_request', PublicationDetail::CONFERENCE)
            ->whereNotNull('travel_start_date')
            ->get();

        $events = [];

        foreach ($details as $detail) {
            if (! $detail->travel_start_date?->betweenIncluded($from, $to)) {
                continue;
            }

            $events[] = [
                'date' => $detail->travel_start_date,
                'title' => 'Conference travel begins',
                'tone' => 'info',
                'meta' => $detail->title_of_conference_journal ?: $detail->title_of_paper,
                'url' => route('applications.show', $detail->application_id),
            ];
        }

        return $events;
    }
}
