<?php

namespace App\Modules\Hani\Workflows;

use App\Modules\Core\Contracts\ProvidesLinks;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Hani\Models\ExaminerNomination;

/**
 * Examiner nominations, filed by a supervisor about one of their candidates.
 *
 * Touchpoint 1 of the conflict-detection design (individual eligibility at
 * nomination time) is enforced in the controller against Examiner::scopeEligible.
 * Touchpoint 2 (cross-department duplicates found when CGS merges department
 * lists into a faculty list) is not built yet -- it belongs to a second stage
 * on this chain once the compilation screen exists.
 */
class ExaminerNominationWorkflow implements WorkflowModule, ProvidesLinks
{
    public function key(): string
    {
        return 'examiner_nomination';
    }

    public function label(): string
    {
        return 'Examiner Nomination';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage(
                key: 'academic_exec',
                label: 'Academic Executive',
                role: Role::ACADEMIC_EXEC,
                decision: 'approved',
                queueTitle: 'Pending My Approval',
            ),
        ];
    }

    public function summary(Application $application): string
    {
        $nomination = ExaminerNomination::with('mainExaminer')
            ->where('application_id', $application->id)
            ->first();

        if (! $nomination?->mainExaminer) {
            return 'Examiner nomination';
        }

        return 'Main examiner: '.$nomination->mainExaminer->name
            .' ('.ucfirst($nomination->mainExaminer->type).')';
    }

    public function createRoute(): ?string
    {
        // Filed by supervisors, not students, so it never appears under a
        // student's "New Application" list.
        return null;
    }

    public function queueRoute(): string
    {
        return 'examiner-nomination.queue';
    }

    /**
     * Three roles need a link here that the stage-derived sidebar cannot
     * produce on its own: supervisors file nominations but own no stage in
     * this chain; CGS maintains the examiner pool day-to-day but never
     * decides a nomination; the Academic Executive owns the one stage this
     * chain does have, but its two follow-up screens -- closing the
     * lifecycle and the touchpoint-2 compilation view -- both happen after
     * or beside that stage, not on it.
     */
    public function links(User $user): array
    {
        return match ($user->role) {
            Role::SUPERVISOR => [
                ['label' => 'Nominate Examiners', 'route' => 'examiner-nomination.create'],
            ],
            Role::NON_EXEC_CGS => [
                ['label' => 'Examiner Pool', 'route' => 'examiner-admin.index'],
            ],
            Role::ACADEMIC_EXEC => [
                ['label' => 'Pending Evaluation', 'route' => 'examiner-nomination.pending-evaluation'],
                ['label' => 'Conflict Detection', 'route' => 'conflict-detection.index'],
            ],
            default => [],
        };
    }
}
