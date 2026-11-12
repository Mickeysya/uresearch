<?php

namespace App\Modules\Nureen\Workflows;

use App\Modules\Core\Contracts\DecidesOneAtATime;
use App\Modules\Core\Contracts\Defaults\DescribesApplicationDetailsDefaults;
use App\Modules\Core\Contracts\DescribesApplicationDetails;
use App\Modules\Core\Contracts\DescribesApplicationProgress;
use App\Modules\Core\Contracts\ProvidesLinks;
use App\Modules\Core\Contracts\SuppliesCalendarEvents;
use App\Modules\Core\Contracts\SuppliesDecisionDates;
use App\Modules\Core\Contracts\SuppliesOverdueStatus;
use App\Modules\Core\Contracts\SuppliesReminderCount;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Support\ApplicationDetailsContent;
use App\Modules\Nureen\Support\TrackingDescriptions;
use App\Modules\Nureen\Workflows\Concerns\ExpectsDecisionByCharter;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Graduate Assistantship extension requests.
 *
 * Three chains, chosen per application at submission time and never
 * revisited afterwards -- see GaExtensionDetail::final_approver_snapshot:
 *   'ga_application'  DEFAULT since 2026-11-12, confirmed by Puan Akmar
 *                      (Senior Executive CGS): the same chain as Haziq's GA
 *                      Application minus its Research Centre interview --
 *                      Admin verifies, CGS Staff reviews, the Supervisor
 *                      endorses, the Senior Director gives the final
 *                      decision.
 *   'cgs'             The previous default (until 2026-11-12): Admin,
 *                      Supervisor, then Non-Executive CGS's final decision;
 *                      the Senior Director is only informed. Kept for
 *                      applications submitted under it.
 *   'senior_director' The original chain: Admin, Supervisor, CGS Staff as
 *                      an intermediate review, then the Senior Director.
 *                      Kept for applications submitted under it.
 *
 * Every chain uses the same Stage keys (admin_verify, supervisor,
 * cgs_verify, senior_director) -- only which ones are on an application's
 * chain, in which order, and whether cgs_verify is intermediate or final,
 * changes. That is what lets an application already
 * sitting at any of these keys stay decidable exactly where it is no
 * matter which chain is live in config when it is next acted on.
 *
 * Ported from the legacy ga_extension_* pages, which stored their position in
 * current_stage as 'supervisor_approved' / 'cgs_verified' while keeping status
 * at 'pending' throughout. That vocabulary did not match the other modules, so
 * the shared tracking page rendered every GA application as a Claims record
 * stuck on step 1. Declaring the chain here puts it on the same footing as
 * everything else.
 */
class GaExtensionWorkflow implements DecidesOneAtATime, DescribesApplicationDetails, DescribesApplicationProgress, ProvidesLinks, SuppliesCalendarEvents, SuppliesDecisionDates, SuppliesOverdueStatus, SuppliesReminderCount, WorkflowModule
{
    /** The student's details page -- Core\Contracts\DescribesApplicationDetails; see Support\ApplicationDetailsContent. */
    use DescribesApplicationDetailsDefaults;

    public function details(Application $application): array
    {
        return app(ApplicationDetailsContent::class)->gaExtension($application);
    }

    public function timelineEvents(Application $application): array
    {
        return app(ApplicationDetailsContent::class)->reminders($application);
    }

    public function stageTargetDays(Application $application, string $stageKey): ?int
    {
        return app(ApplicationDetailsContent::class)->target($application, $stageKey);
    }

    public function waitingFor(Application $application): ?string
    {
        return app(ApplicationDetailsContent::class)->waitingFor($application);
    }

    /** Track My Applications tab (config/tracking.php) and card icon. */
    public const CATEGORY = 'assistantships';

    public const ICON = 'clock';

    /** Track My Applications: Core\Contracts\DescribesApplicationProgress -- see Support\TrackingDescriptions. */
    public function describeProgress(Application $application): array
    {
        return app(TrackingDescriptions::class)->gaExtension($application);
    }

    /** expectedDecisionDate(): the Client Charter date, for the student dashboard. */
    use ExpectsDecisionByCharter;

    public function key(): string
    {
        return 'ga_extension';
    }

    public function label(): string
    {
        return 'GA Extension';
    }

    /**
     * With an application: the chain it was submitted under (its snapshot).
     *
     * Without one: every stage ANY chain can use, as Core's contract
     * asks -- ModuleRegistry::queuesForRole() builds the sidebar and gates
     * the queue pages from this, so a stage missing here is a stage nobody
     * can reach. Returning only the live config's chain was a bug: in the
     * default 'cgs' mode it dropped 'senior_director', and every older
     * application already sitting there became undecidable. The live chain
     * comes first, then any stage only another chain has.
     */
    public function stages(?Application $application = null): array
    {
        if ($application === null) {
            return $this->allStages();
        }

        return $this->chainFor($this->modeFor($application));
    }

    /** @return array<int, Stage> */
    protected function allStages(): array
    {
        $live = config('nureen.ga_extension.final_approver');
        $stages = $this->chainFor($live);
        $keys = array_map(fn (Stage $s) => $s->key, $stages);

        foreach (array_diff(self::MODES, [$live]) as $mode) {
            foreach ($this->chainFor($mode) as $stage) {
                if (! in_array($stage->key, $keys, true)) {
                    $stages[] = $stage;
                    $keys[] = $stage->key;
                }
            }
        }

        // 'cgs_verify' is the final decision on a 'cgs' application and an
        // intermediate review on the other two, and all of them share one
        // queue -- so the queue's own title must not claim either.
        // Per-application labels still come from chainFor().
        return array_map(fn (Stage $s) => $s->key === 'cgs_verify'
            ? new Stage(
                key: 'cgs_verify',
                label: 'Non-Executive CGS',
                role: $s->role,
                decision: $s->decision,
                queueTitle: 'Pending My Review',
            )
            : $s, $stages);
    }

    /** @return array<int, Stage> */
    protected function chainFor(string $mode): array
    {
        // Added 2026-11-10 to align with how GRA/GA's own extension flow
        // already works: an Admin stage verifying the request's paperwork
        // before it reaches a Supervisor, same role and same "never
        // rejects outright" rule as GraApplicationController/
        // GaApplicationController's own admin_verify -- see
        // GaExtensionController::decide().
        $adminStage = new Stage(
            key: 'admin_verify',
            label: 'Admin',
            role: Role::ADMIN,
            decision: 'verified',
            queueTitle: 'Pending Document Verification',
        );

        $supervisorStage = new Stage(
            key: 'supervisor',
            label: 'Lecturer/Supervisor',
            role: Role::SUPERVISOR,
            decision: 'endorsed',
            queueTitle: 'Pending My Endorsement',
        );

        $cgsReview = new Stage(
            key: 'cgs_verify',
            label: 'CGS Staff',
            role: Role::NON_EXEC_CGS,
            decision: 'reviewed',
            queueTitle: 'Pending My Verification',
        );

        $seniorDirector = new Stage(
            key: 'senior_director',
            label: 'Senior Director CGS',
            role: Role::SENIOR_DIRECTOR_CGS,
            decision: 'approved',
            queueTitle: 'Final Approval',
        );

        // Haziq's GA Application chain minus its Research Centre interview:
        // Admin, CGS, Supervisor, Senior Director. Confirmed by Puan Akmar
        // (Senior Executive CGS), 12 Nov 2026. Read from his workflow's
        // order, not copied blind -- see GaExtensionChainTest.
        if ($mode === 'ga_application') {
            return [$adminStage, $cgsReview, $supervisorStage, $seniorDirector];
        }

        if ($mode === 'senior_director') {
            return [$adminStage, $supervisorStage, $cgsReview, $seniorDirector];
        }

        return [
            $adminStage,
            $supervisorStage,
            new Stage(
                key: 'cgs_verify',
                label: 'Non-Executive CGS',
                role: Role::NON_EXEC_CGS,
                decision: 'approved',
                queueTitle: 'Final Approval',
            ),
        ];
    }

    /** Every final_approver_snapshot value a GA Extension can carry, newest first. */
    public const MODES = ['ga_application', 'cgs', 'senior_director'];

    /**
     * The earlier stages an application can be sent back to from where it
     * is now, as stage key => button label; empty when its stage has no
     * return. A reason is always required (WorkflowEngine::returnTo()).
     *
     *   'ga_application'  CGS -> Admin; Senior Director -> CGS or Supervisor
     *                      (confirmed by Puan Akmar, 12 Nov 2026 -- Haziq's
     *                      GA Application itself has no stage returns, only
     *                      correction requests to the student).
     *   'cgs' / 'senior_director'  CGS -> Supervisor, unchanged.
     *
     * @return array<string, string>
     */
    public function returnTargets(Application $application): array
    {
        $stage = $application->status === Application::STATUS_PENDING ? $application->current_stage : null;

        return match (true) {
            $this->modeFor($application) === 'ga_application' && $stage === 'cgs_verify' => ['admin_verify' => 'Return to Admin'],
            $this->modeFor($application) === 'ga_application' && $stage === 'senior_director' => ['cgs_verify' => 'Return to CGS', 'supervisor' => 'Return to supervisor'],
            $this->modeFor($application) !== 'ga_application' && $stage === 'cgs_verify' => ['supervisor' => 'Return to supervisor'],
            default => [],
        };
    }

    /**
     * 'ga_application', 'cgs' or 'senior_director' -- the application's own snapshot when it
     * has one, otherwise the live config (true for a brand new application
     * mid-submit, which has no snapshot yet because GaExtensionController::
     * store() has not written one until after stages() first runs).
     */
    public function modeFor(?Application $application): string
    {
        if ($application) {
            $detail = $this->detailFor($application);

            if ($detail?->final_approver_snapshot) {
                return $detail->final_approver_snapshot;
            }
        }

        return config('nureen.ga_extension.final_approver');
    }

    /**
     * The application's detail row, read once per Application object: the
     * stored snapshot never changes once written, and Track My Applications
     * asks for one card's chain (and summary) several times. A row not
     * written yet -- mid-submit -- is never cached.
     */
    protected function detailFor(Application $application): ?GaExtensionDetail
    {
        $this->details ??= new \WeakMap;

        if (isset($this->details[$application])) {
            return $this->details[$application];
        }

        $detail = GaExtensionDetail::where('application_id', $application->id)->first();

        if ($detail) {
            $this->details[$application] = $detail;
        }

        return $detail;
    }

    /** @var \WeakMap<Application, GaExtensionDetail>|null see detailFor() */
    private ?\WeakMap $details = null;

    public function summary(Application $application): string
    {
        $detail = $this->detailFor($application);

        if (! $detail) {
            return 'GA extension request';
        }

        // A saved draft may not have these yet (they are nullable for drafts).
        $parts = array_filter([
            $detail->requested_new_end_date ? 'Extend to '.$detail->requested_new_end_date->format('j M Y') : null,
            $detail->reason_for_extension ? Str::limit($detail->reason_for_extension, 70) : null,
        ]);

        return $parts === [] ? 'GA extension request' : implode(', ', $parts);
    }

    public function createRoute(): ?string
    {
        return 'ga-extension.create';
    }

    public function queueRoute(): string
    {
        return 'ga-extension.queue';
    }

    /**
     * The student's Expected Decision Dates page, which covers all three of
     * Nureen's approval modules (GA Extension, Supervision, Certification)
     * -- declared here only because a link has to belong to one module.
     * See Http\Controllers\ExpectedDecisionController.
     *
     * "GA Extension" (student) was missing entirely until now -- a
     * student had no way to find the apply form without already knowing
     * the URL. Confirmed explicitly 2026-11-10: "kat actions student
     * mana my ga extention."
     *
     * The approver side deliberately does NOT get its own "GA Extension"
     * sidebar entry, even though the student side does -- confirmed
     * explicitly the same day, right after adding one: "buaag ga
     * extentions dekat actions, buat je kat page ga boleh filter
     * application or extention, tapi taknak ada both [as separate
     * things]." An approver reaches GA Extension's own queue (with its
     * own Pending by Stage chart) as a tab on "GA Applicants" instead --
     * see ga_application/index.blade.php and ga_extension/queue.blade.php.
     */
    public function links(User $user): array
    {
        if ($user->isStudent()) {
            return [
                ['label' => 'GA Extension', 'route' => 'ga-extension.create'],
                ['label' => 'Expected Decision Dates', 'route' => 'nureen.expected-dates'],
                // Saved GA Extension and Certification drafts -- Support\Drafts.
                ['label' => 'My Drafts', 'route' => 'nureen.drafts'],
            ];
        }

        $links = [];

        // Requests no account can act on -- Support\StuckSupervisorStages.
        if ($user->role === Role::NON_EXEC_CGS) {
            $links[] = ['label' => 'Stuck Reviews', 'route' => 'nureen.stuck-reviews'];
        }

        return $links;
    }

    /**
     * When the student's assistantship actually runs out.
     *
     * The requested new end date is shown only once it has been approved --
     * before that it is a request, not a date, and putting it on a calendar
     * would tell the student they have an extension they have not been given.
     */
    public function calendarEvents(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($user->role !== Role::STUDENT) {
            return [];
        }

        $applications = Application::query()
            ->where('module_type', $this->key())
            ->where('student_id', $user->id)
            ->whereIn('status', [Application::STATUS_PENDING, Application::STATUS_APPROVED])
            ->get()
            ->keyBy('id');

        $details = GaExtensionDetail::whereIn('application_id', $applications->keys())->get();

        $events = [];

        foreach ($details as $detail) {
            $approved = $applications[$detail->application_id]->status === Application::STATUS_APPROVED;
            $date = $approved ? $detail->requested_new_end_date : $detail->current_end_date;

            if (! $date?->betweenIncluded($from, $to)) {
                continue;
            }

            $events[] = [
                'date' => $date,
                'title' => $approved ? 'GA appointment ends (extended)' : 'GA appointment ends',
                'tone' => 'warn',
                'meta' => $approved ? null : 'Extension still under review',
                'url' => route('applications.show', $detail->application_id),
            ];
        }

        return $events;
    }
}
