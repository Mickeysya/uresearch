<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
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
    public function __construct(protected WorkflowEngine $engine) {}

    /**
     * Pending applications of this module type that have not moved in at
     * least $days, and have not already been reminded since the cutoff --
     * so a 24-hour-interval schedule never double-reminds inside one
     * stall.
     *
     * @return Collection<int, Application>
     */
    public function findStalled(string $moduleType, int $days): Collection
    {
        $cutoff = now()->subDays($days);

        return Application::query()
            ->where('module_type', $moduleType)
            ->where('status', Application::STATUS_PENDING)
            ->where('updated_at', '<=', $cutoff)
            ->get()
            ->filter(function (Application $application) use ($cutoff) {
                $reminder = ApplicationReminder::where('application_id', $application->id)->first();

                return ! $reminder
                    || ! $reminder->last_reminded_at
                    || $reminder->last_reminded_at->lt($cutoff);
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

        $query = User::where('role', $stage->role);

        if (Role::isDepartmentScoped($stage->role)) {
            $query->where('department', $application->student?->department);
        }

        return $query->get();
    }

    /**
     * Records that a reminder just went out, resetting the count rather
     * than incrementing it if the application has moved to a different
     * stage since the last reminder -- a count from a stage it has since
     * left is not "still stalled here", it is stale.
     */
    public function recordReminder(Application $application, ?string $stageKey): ApplicationReminder
    {
        $reminder = ApplicationReminder::firstOrCreate(['application_id' => $application->id]);

        $reminder->update([
            'reminder_count' => $reminder->reminded_stage_key === $stageKey ? $reminder->reminder_count + 1 : 1,
            'reminded_stage_key' => $stageKey,
            'last_reminded_at' => now(),
        ]);

        return $reminder;
    }
}
