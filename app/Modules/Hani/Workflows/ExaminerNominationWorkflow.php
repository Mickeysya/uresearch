<?php

namespace App\Modules\Hani\Workflows;

use App\Modules\Core\Contracts\ProvidesLinks;
use App\Modules\Core\Contracts\DecidesOneAtATime;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Hani\Models\ExaminerNomination;

/**
 * Examiner nominations, filed by a supervisor about one of their candidates.
 *
 * THE CHAIN, as agreed 2026-09-22. It is longer than a nomination sounds,
 * because what travels along it stops being one nomination after the first
 * stage: the Academic Executive clears their own department's, CGS merges
 * every department's into one faculty list, and everyone above that is
 * signing off the list rather than the row.
 *
 *   supervisor files      internal main + backup, external main + backup
 *     -> academic_exec    their own department only, department-scoped
 *     -> cgs_compile      Senior Executive CGS merges and checks for clashes
 *     -> senior_director  verifies, or sends it back to the department
 *     -> dean             approves, or sends it back to the department
 *     -> cgs_final        Senior Executive CGS holds the finalised report
 *     -> cgs_release      Senior Executive CGS releases it to CGS operations
 *                         (recorded in the same click as cgs_final)
 *
 * Touchpoint 1 of the conflict-detection design (individual eligibility at
 * nomination time) is enforced in the controller against
 * Examiner::scopeEligible. Touchpoint 2 (cross-department duplicates, only
 * visible once department lists are merged) is what the cgs_compile stage is
 * for -- ConflictDetectionController::index() is the screen, now reachable
 * from that stage as well as from the Academic Executive's sidebar.
 *
 * The two "send it back" paths are WorkflowEngine::returnTo(), not
 * rejections: the list goes back to academic_exec and replays forward, so
 * the candidate keeps one application and one audit trail no matter how many
 * times it loops. See ExaminerNominationController::returnToDepartment().
 *
 * Three stages share the Senior Executive CGS role (cgs_compile, cgs_final
 * and cgs_release). That is fine and deliberate -- ModuleRegistry::queuesForRole()
 * returns both, and the queue screen picks between them with ?stage=.
 */
class ExaminerNominationWorkflow implements WorkflowModule, DecidesOneAtATime, ProvidesLinks
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
            new Stage(
                key: 'cgs_compile',
                label: 'CGS Compilation',
                role: Role::SENIOR_EXEC_CGS,
                decision: 'compiled',
                queueTitle: 'Nominations to Compile',
            ),
            new Stage(
                key: 'senior_director',
                label: 'Senior Director CGS',
                role: Role::SENIOR_DIRECTOR_CGS,
                decision: 'verified',
                queueTitle: 'Lists to Verify',
            ),
            new Stage(
                key: 'dean',
                label: 'Dean of PGR',
                role: Role::DEAN_PGR,
                decision: 'approved',
                queueTitle: 'Pending My Approval',
            ),
            new Stage(
                key: 'cgs_final',
                label: 'CGS Final Report',
                role: Role::SENIOR_EXEC_CGS,
                decision: 'finalised',
                queueTitle: 'Reports to Finalise',
            ),
            // Senior Executive CGS since 2026-11-03: Puan Waheeda finalises
            // AND releases the list, and Non-Executive CGS is told on release
            // (ExaminersReleased) rather than owning a stage. The key is
            // unchanged -- it is stored in applications.current_stage -- and
            // at cgs_final one Approve records both stages; see
            // ExaminerNominationController::finaliseAndRelease().
            new Stage(
                key: 'cgs_release',
                label: 'Release to CGS operations',
                role: Role::SENIOR_EXEC_CGS,
                decision: 'released',
                queueTitle: 'Final Lists to Release',
            ),
        ];
    }

    public function summary(Application $application): string
    {
        // The candidate sees this row on their own tracking page, and a
        // candidate must never learn who their examiners are. Status only.
        if (auth()->user()?->isStudent()) {
            return match ($application->status) {
                Application::STATUS_APPROVED => 'Examiners appointed',
                Application::STATUS_REJECTED => 'Examiner nomination not approved',
                default => 'Examiner nomination in progress',
            };
        }

        $nomination = ExaminerNomination::with('internalMain', 'externalMain')
            ->where('application_id', $application->id)
            ->first();

        // The two mains are the panel; the backups only matter once one of
        // them falls through, and a queue row has no space for four names.
        $mains = array_filter([$nomination?->internalMain, $nomination?->externalMain]);

        if ($mains === []) {
            return 'Examiner nomination';
        }

        return implode(', ', array_map(
            fn ($examiner) => $examiner->name.' ('.$examiner->typeLabel().')',
            $mains
        ));
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
     * Links the stage-derived sidebar cannot produce on its own: the
     * supervisor files nominations but owns no stage; the Senior Executive
     * CGS maintains the examiner pool day-to-day; the Academic Executive's two follow-up
     * screens happen after or beside their stage rather than on it; and the
     * compiled report is a screen everyone from the Academic Executive
     * upwards reads, at whatever point in the chain they are.
     */
    public function links(User $user): array
    {
        // The compiled list is the same screen for everyone above the
        // department -- what each of them may do with it is decided by their
        // stage, not by a separate page each.
        $report = ['label' => 'Examiner Report', 'route' => 'examiner-nomination.report'];
        $dashboard = ['label' => 'Examiner Dashboard', 'route' => 'examiner-dashboard'];

        return match ($user->role) {
            Role::SUPERVISOR => [
                ['label' => 'Nominate Examiners', 'route' => 'examiner-nomination.create'],
            ],
            // The released list, and the pool read-only apart from examiner
            // emails. The appointment letters are Jason's module.
            Role::NON_EXEC_CGS => [
                ['label' => 'Examiner Pool', 'route' => 'examiner-admin.index'],
                $report,
            ],
            // Puan Waheeda keeps the pool as well as the list.
            Role::SENIOR_EXEC_CGS => [
                ['label' => 'Examiner Pool', 'route' => 'examiner-admin.index'],
                ['label' => 'Conflict Detection', 'route' => 'conflict-detection.index'],
                $report,
                $dashboard,
            ],
            Role::SENIOR_DIRECTOR_CGS, Role::DEAN_PGR => [
                $report,
                $dashboard,
            ],
            Role::ACADEMIC_EXEC => [
                ['label' => 'Pending Evaluation', 'route' => 'examiner-nomination.pending-evaluation'],
                ['label' => 'Conflict Detection', 'route' => 'conflict-detection.index'],
                $report,
                $dashboard,
            ],
            default => [],
        };
    }
}
