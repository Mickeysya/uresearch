<?php

namespace App\Modules\Norhanis\Http\Controllers\Concerns;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Support\ApproverNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Core's approve/reject action, plus an email to whoever the application
 * moved on to. Core already emails the student; see ApproverNotifier.
 *
 * Also keeps the supervisor stage to the student's OWN supervisor. Core's
 * engine checks only the role (any supervisor), and its queue is role-wide;
 * the team has not yet agreed a Core-wide fix ("Scope approver queues to the
 * right people" in TODO.md), so this module scopes itself, the same way
 * Nureen's Supervision does. Because decide() here does more than call the
 * engine, each workflow implements DecidesOneAtATime, so Core's bulk decide
 * cannot skip the check.
 */
trait ApprovesAndNotifies
{
    use ApprovesApplications {
        decide as protected decideInCore;
        queueFor as protected queueForInCore;
    }

    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        if (! $this->isOwnSupervisee($request->user(), $application)) {
            return back()->with('error', "Only the student's own supervisor can decide this application.");
        }

        $before = [$application->status, $application->current_stage];

        $response = $this->decideInCore($request, $application, $engine);

        // Only when the decision went through: a refused or repeated one
        // leaves the row as it was, and the next approver was told already.
        $application->refresh();

        if ([$application->status, $application->current_stage] !== $before) {
            app(ApproverNotifier::class)->arrived($application);
        }

        return $response;
    }

    /** Core's queue, narrowed for a supervisor to their own supervisees -- before the count and the page. */
    protected function queueFor(Request $request, WorkflowEngine $engine, array $with = [], ?callable $scope = null): array
    {
        $user = $request->user();

        return $this->queueForInCore($request, $engine, $with, function ($query, $stage) use ($user, $scope) {
            if ($user->role === Role::SUPERVISOR) {
                $query->whereHas('student', fn ($student) => $student->where('supervisor_id', $user->id));
            }

            if ($scope) {
                $scope($query, $stage);
            }
        });
    }

    /** True for anyone but a supervisor; a supervisor only for their own student. */
    protected function isOwnSupervisee(User $user, Application $application): bool
    {
        return $user->role !== Role::SUPERVISOR
            || ($application->student?->supervisor_id !== null && $application->student->supervisor_id === $user->id);
    }
}
