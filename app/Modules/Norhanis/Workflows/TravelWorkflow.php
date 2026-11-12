<?php

namespace App\Modules\Norhanis\Workflows;

use App\Modules\Core\Contracts\ProvidesLinks;
use App\Modules\Core\Contracts\SuppliesCalendarEvents;
use App\Modules\Core\Contracts\DecidesOneAtATime;
use App\Modules\Core\Contracts\DescribesApplicationProgress;
use App\Modules\Core\Contracts\SuppliesDecisionDates;
use App\Modules\Core\Contracts\SuppliesOverdueStatus;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Norhanis\Http\Controllers\ApplicationOverviewController;
use App\Modules\Norhanis\Models\TravelDetail;
use Carbon\CarbonInterface;
use App\Modules\Norhanis\Support\Drafts;
use App\Modules\Norhanis\Workflows\Concerns\ExpectsDecisionByCharter;
use App\Modules\Norhanis\Workflows\Concerns\NamesStageActions;
use App\Modules\Norhanis\Support\StageAction;

/**
 * Travel requests.
 *
 * Both routes run Supervisor -> Chair -> Non-Executive CGS; what differs is
 * who has the final say: the Senior Director CGS for local travel, the Dean
 * of PGR for international (as agreed with CGS).
 *
 * In the legacy app that branch lived inside travel_chair_approval.php, which
 * queried is_international and picked the next stage by hand. Expressing it as
 * a chain instead means the student's stepper shows the correct number of
 * steps from the moment they submit, and nothing has to be kept in sync.
 */
class TravelWorkflow implements WorkflowModule, ProvidesLinks, SuppliesCalendarEvents, DecidesOneAtATime, SuppliesDecisionDates, SuppliesOverdueStatus, DescribesApplicationProgress
{
    /** expectedDecisionDate() and decisionIsOverdue(): see the trait. */
    use ExpectsDecisionByCharter;
    use NamesStageActions;

    /**
     * What each approver does: only the final approver approves -- the
     * Senior Director CGS for local travel, the Dean of PGR for
     * international. The one place this module's wording lives; see
     * Support\StageAction.
     */
    public const ACTIONS = [
        'supervisor' => StageAction::Endorse,
        'chair' => StageAction::Endorse,
        'cgs_review' => StageAction::Review,
        'dean' => StageAction::Approve,
        'senior_director' => StageAction::Approve,
    ];

    /** @var array<int, bool> memoised per application, keyed by id */
    protected array $internationalCache = [];

    public function key(): string
    {
        return 'travel';
    }

    public function label(): string
    {
        return 'Travel';
    }

    public function stages(?Application $application = null): array
    {
        // null means "every stage this module can use": both final stages,
        // so the Dean and the Senior Director each get a travel queue.
        if ($application === null) {
            return [...$this->route(international: true), $this->seniorDirector()];
        }

        return $this->route(international: $this->isInternational($application));
    }

    /**
     * The two routes on their own, for a reader that needs to know which
     * stages follow which: in stages(null) the Senior Director comes after
     * the Dean, yet no application passes through both.
     *
     * @return array<string, array<int, Stage>>
     */
    public function routes(): array
    {
        return ['local' => $this->route(international: false), 'international' => $this->route(international: true)];
    }

    /** @return array<int, Stage> */
    protected function route(bool $international): array
    {
        return [
            $this->actionStage('supervisor', 'Lecturer/Supervisor', Role::SUPERVISOR),
            $this->actionStage('chair', 'Chair of Department', Role::CHAIR),
            $this->actionStage('cgs_review', 'Non-Executive CGS', Role::NON_EXEC_CGS),
            $international
                ? $this->actionStage('dean', 'Dean of PGR', Role::DEAN_PGR)
                : $this->seniorDirector(),
        ];
    }

    protected function seniorDirector(): Stage
    {
        return $this->actionStage('senior_director', 'Senior Director CGS', Role::SENIOR_DIRECTOR_CGS);
    }

    public function summary(Application $application): string
    {
        $detail = $this->detail($application);

        if (! $detail) {
            // A saved draft has no detail row yet: name it from what was typed.
            return Drafts::summary($application, 'Travel application', 'reason_for_travel', 'destination_address');
        }

        return $detail->reason_for_travel.', '.$detail->destination_address;
    }

    public function createRoute(): ?string
    {
        return 'travel.create';
    }

    public function queueRoute(): string
    {
        return 'travel.queue';
    }

    /** "Travel Applications": everyone who has applied, for those who approve travel. */
    public function links(User $user): array
    {
        // The TA Hours link lives on ClaimsWorkflow, as in UCampus's Research Claims.
        return ApplicationOverviewController::link($this, $user);
    }

    protected function isInternational(Application $application): bool
    {
        if (! $application->exists) {
            // The registry probes with a blank Application to build the
            // sidebar; the local chain is the right default there.
            return false;
        }

        return $this->internationalCache[$application->id] ??=
            (bool) TravelDetail::where('application_id', $application->id)->value('is_international');
    }

    protected function detail(Application $application): ?TravelDetail
    {
        return TravelDetail::where('application_id', $application->id)->first();
    }

    /**
     * The student's own travel windows.
     *
     * Only their own, and only applications that are still live -- a rejected
     * trip from March is not a thing anyone needs to see coming.
     */
    public function calendarEvents(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($user->role !== Role::STUDENT) {
            return [];
        }

        $details = TravelDetail::query()
            ->whereIn('application_id', Application::query()
                ->where('module_type', $this->key())
                ->where('student_id', $user->id)
                ->whereIn('status', [Application::STATUS_PENDING, Application::STATUS_APPROVED])
                ->select('id'))
            ->whereDate('travel_end_date', '>=', $from)
            ->whereDate('travel_start_date', '<=', $to)
            ->get();

        $events = [];

        foreach ($details as $detail) {
            $label = $detail->is_international ? 'International travel' : 'Travel';

            // Start and end are separate entries rather than one spanning bar:
            // a month grid cannot draw a span without a lane system, and the
            // two dates are what the student actually needs to act on.
            foreach ([['travel_start_date', 'departs'], ['travel_end_date', 'returns']] as [$field, $verb]) {
                if (! $detail->$field->betweenIncluded($from, $to)) {
                    continue;
                }

                $events[] = [
                    'date' => $detail->$field,
                    'title' => $label.': '.$verb,
                    'tone' => 'info',
                    'meta' => $detail->destination_address,
                    'url' => route('applications.show', $detail->application_id),
                ];
            }
        }

        return $events;
    }
}
