<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\CgsAssignment;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\ApplicationReminder;
use Illuminate\Support\Collection;

/**
 * The generic half of "remind whoever needs to act" -- shared by GA
 * Extension's and Certification's remind-stalled commands. Supervision
 * keeps its own command: the real recipient of its 'supervisor' stage is
 * one named person, not everyone holding the Supervisor role, which this
 * class has no way to know and should not guess at.
 */
class StalledApplicationReminder
{
    public function __construct(
        protected WorkflowEngine $engine,
        protected ClientCharter $charter,
    ) {}

    /**
     * Pending applications of this module type whose current stage's
     * reviewer is due a reminder -- see ClientCharter::dueForReminder():
     * first on the working day the stage goes past its Client Charter (or
     * its stalled_days fallback, for a stage with no charter), then once
     * per further full period, so a daily schedule never double-reminds
     * inside one stall. $overrideDays (a command's --days) replaces the
     * charter for every stage.
     *
     * @return Collection<int, Application>
     */
    public function findStalled(string $moduleType, ?int $overrideDays = null): Collection
    {
        return Application::query()
            ->where('module_type', $moduleType)
            ->where('status', Application::STATUS_PENDING)
            ->with('history')
            ->get()
            ->filter(function (Application $application) use ($overrideDays) {
                $reminder = ApplicationReminder::where('application_id', $application->id)->first();

                return $this->charter->dueForReminder(
                    $application,
                    $reminder?->last_reminded_at,
                    $reminder?->reminded_stage_key,
                    $overrideDays,
                );
            })
            ->values();
    }

    /**
     * Everyone holding the application's current stage's role --
     * department-scoped the same way the engine itself scopes decide() and
     * returnTo(), for the day a stalled module's stage role is Chair or
     * Academic Executive.
     *
     * @return Collection<int, User>
     */
    public function recipientsForCurrentStage(Application $application): Collection
    {
        $stage = $this->engine->currentStage($application);

        if (! $stage) {
            return collect();
        }

        // A CGS stage's reminder goes to the module's assigned officer
        // alone (config/cgs_assignment.php), every CGS when it is open.
        if ($stage->role === Role::NON_EXEC_CGS) {
            return app(CgsAssignment::class)->recipients($application->module_type);
        }

        $query = User::where('role', $stage->role);

        if (Role::isDepartmentScoped($stage->role)) {
            $query->where('department', $application->student?->department);
        }

        return $query->get();
    }

    /**
     * Records that a reminder just went out, resetting the count rather
     * than incrementing it if the last one was about a different stage or
     * an earlier visit to this one (before a return-to-supervisor) -- a
     * count from a stall that has since ended is stale, not "still stalled
     * here".
     */
    public function recordReminder(Application $application, array $clock): ApplicationReminder
    {
        $reminder = ApplicationReminder::firstOrCreate(['application_id' => $application->id]);

        $sameStall = $this->charter->remindedDuringThisStage($clock, $reminder->last_reminded_at, $reminder->reminded_stage_key);

        $reminder->update([
            'reminder_count' => $sameStall ? $reminder->reminder_count + 1 : 1,
            'reminded_stage_key' => $clock['stage']->key,
            'last_reminded_at' => now(),
        ]);

        return $reminder;
    }
}
