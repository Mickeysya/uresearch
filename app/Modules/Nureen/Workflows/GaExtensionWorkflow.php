<?php

namespace App\Modules\Nureen\Workflows;

use App\Modules\Core\Contracts\DecidesOneAtATime;
use App\Modules\Core\Contracts\DescribesApplicationDetails;
use App\Modules\Core\Contracts\Defaults\DescribesApplicationDetailsDefaults;
use App\Modules\Nureen\Support\ApplicationDetailsContent;
use App\Modules\Core\Contracts\DescribesApplicationProgress;
use App\Modules\Core\Contracts\SuppliesOverdueStatus;
use App\Modules\Nureen\Support\TrackingDescriptions;
use App\Modules\Core\Contracts\SuppliesDecisionDates;
use App\Modules\Core\Contracts\ProvidesLinks;
use App\Modules\Core\Contracts\SuppliesCalendarEvents;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Nureen\Workflows\Concerns\ExpectsDecisionByCharter;
use App\Modules\Nureen\Models\GaExtensionDetail;
use Illuminate\Support\Str;
use App\Modules\Core\Models\User;
use Carbon\CarbonInterface;

/**
 * Graduate Assistantship extension requests.
 *
 * Two chains, chosen per application at submission time and never revisited
 * afterwards -- see GaExtensionDetail::final_approver_snapshot:
 *   'cgs'             DEFAULT, confirmed against CGS's UResearch process.
 *                      Supervisor endorses, Non-Executive CGS gives the
 *                      final decision. The Senior Director no longer acts
 *                      here; see GaExtensionController::decide().
 *   'senior_director' The original chain: Supervisor, then CGS Staff as an
 *                      intermediate review, then the Senior Director's
 *                      final approval.
 *
 * Both chains keep the same three Stage keys (supervisor, cgs_verify,
 * senior_director) that existed before this split -- only which ones are
 * actually on an application's chain, and whether cgs_verify is
 * intermediate or final, changes. That is what lets an application already
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
class GaExtensionWorkflow implements WorkflowModule, SuppliesCalendarEvents, DecidesOneAtATime, ProvidesLinks, SuppliesDecisionDates, SuppliesOverdueStatus, DescribesApplicationProgress, DescribesApplicationDetails
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
     * Without one: every stage EITHER chain can use, as Core's contract
     * asks -- ModuleRegistry::queuesForRole() builds the sidebar and gates
     * the queue pages from this, so a stage missing here is a stage nobody
     * can reach. Returning only the live config's chain was a bug: in the
     * default 'cgs' mode it dropped 'senior_director', and every older
     * application already sitting there became undecidable. The live chain
     * comes first, then any stage only the other chain has.
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

        foreach ($this->chainFor($live === 'cgs' ? 'senior_director' : 'cgs') as $stage) {
            if (! in_array($stage->key, $keys, true)) {
                $stages[] = $stage;
            }
        }

        // 'cgs_verify' is the final decision on a 'cgs' application and an
        // intermediate review on a 'senior_director' one, and both kinds
        // share one queue -- so the queue's own title must not claim either.
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
        $supervisorStage = new Stage(
            key: 'supervisor',
            label: 'Lecturer/Supervisor',
            role: Role::SUPERVISOR,
            decision: 'endorsed',
            queueTitle: 'Pending My Endorsement',
        );

        if ($mode === 'senior_director') {
            return [
                $supervisorStage,
                new Stage(
                    key: 'cgs_verify',
                    label: 'CGS Staff',
                    role: Role::NON_EXEC_CGS,
                    decision: 'reviewed',
                    queueTitle: 'Pending My Verification',
                ),
                new Stage(
                    key: 'senior_director',
                    label: 'Senior Director CGS',
                    role: Role::SENIOR_DIRECTOR_CGS,
                    decision: 'approved',
                    queueTitle: 'Final Approval',
                ),
            ];
        }

        return [
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

    /**
     * 'cgs' or 'senior_director' -- the application's own snapshot when it
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
        $this->details ??= new \WeakMap();

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

        return 'Extend to '.$detail->requested_new_end_date->format('j M Y')
            .', '.Str::limit($detail->reason_for_extension, 70);
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
     */
    public function links(User $user): array
    {
        return match ($user->role) {
            Role::STUDENT => [['label' => 'Expected Decision Dates', 'route' => 'nureen.expected-dates']],
            // Requests no account can act on -- Support\StuckSupervisorStages.
            Role::NON_EXEC_CGS => [['label' => 'Stuck Reviews', 'route' => 'nureen.stuck-reviews']],
            default => [],
        };
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
