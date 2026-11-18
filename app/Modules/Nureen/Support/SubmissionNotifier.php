<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\CgsAssignment;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Notifications\ApplicationAwaitingYou;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Emails whoever acts first when one of this module's applications is
 * submitted (or handed to a new supervisor by CGS): what is waiting, the
 * Client Charter due date for that stage, and a link to their queue.
 *
 *   GA Extension, Certification  the supervisor named on the detail row
 *   Supervision                  the requested supervisor
 *   Attendance Appeal            every Non-Executive CGS account
 *
 * Kept in this module: Core's WorkflowEngine::submit() notifies nobody, and
 * changing that would change every teammate's module. A recipient whose
 * account cannot act at that stage is skipped (Support\StuckSupervisorStages
 * shows CGS those).
 */
class SubmissionNotifier
{
    public function __construct(
        protected WorkflowEngine $engine,
        protected ClientCharter $charter,
        protected ModuleRegistry $registry,
    ) {}

    public function notify(Application $application, ?string $reassignedReason = null): void
    {
        $stage = $this->engine->currentStage($application);

        if (! $stage) {
            return;
        }

        $recipients = ($application->module_type === 'ga_extension'
            ? $this->gaExtensionRecipients($application, $stage)
            : $this->recipients($application))->filter(fn (User $u) => $u->role === $stage->role);

        if ($recipients->isEmpty()) {
            return;
        }

        $clock = $this->charter->stageClock($application);
        $module = $this->registry->get($application->module_type);

        Notification::send($recipients, new ApplicationAwaitingYou(
            $application,
            $module->label(),
            $stage,
            route($module->queueRoute()),
            $clock && $clock['on_charter'] ? $clock['due_date'] : null,
            $clock && $clock['on_charter'] ? $clock['charter_days'] : null,
            $reassignedReason,
        ));
    }

    /**
     * GA Extension's next actor, stage by stage -- since 2026-11-12 its
     * default chain (Puan Akmar's confirmation: Haziq's GA Application
     * chain minus the interview) starts with Admin, so "whoever acts first"
     * is no longer the named supervisor:
     *   admin_verify     every Admin
     *   cgs_verify       the officer GA Extension is assigned to
     *                    (config/cgs_assignment.php), every CGS if open
     *   supervisor       the supervisor this application names
     *   senior_director  every Senior Director CGS
     *
     * @return Collection<int, User>
     */
    protected function gaExtensionRecipients(Application $application, Stage $stage): Collection
    {
        return match ($stage->role) {
            Role::NON_EXEC_CGS => app(CgsAssignment::class)->recipients($application->module_type),
            Role::SUPERVISOR => User::whereKey(GaExtensionDetail::where('application_id', $application->id)->value('supervisor_id'))->get(),
            default => User::where('role', $stage->role)->get(),
        };
    }

    /** @return Collection<int, User> */
    protected function recipients(Application $application)
    {
        $id = match ($application->module_type) {
            'ga_extension' => GaExtensionDetail::where('application_id', $application->id)->value('supervisor_id'),
            'ga_certification' => GaCertificationDetail::where('application_id', $application->id)->value('supervisor_id'),
            'supervision' => SupervisionDetail::where('application_id', $application->id)->value('requested_supervisor_id'),
            default => null,
        };

        // Attendance Appeal starts at CGS: its assigned officer alone, or
        // every Non-Executive CGS if the module is open (config/cgs_assignment.php).
        if ($application->module_type === 'attendance_appeal') {
            return app(CgsAssignment::class)->recipients($application->module_type);
        }

        return User::whereKey($id)->get();
    }
}
