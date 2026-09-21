<?php

namespace App\Modules\Norhanis\Workflows;

use App\Modules\Core\Contracts\ProvidesLinks;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Norhanis\Models\RpdDismissalDetail;

/**
 * RPD Dismissal -- for candidates who blew past their RPD deadline without
 * appealing. Non-Exec CGS initiates this, not the student, which is why
 * createRoute() returns null: there is no "New Application" form for it, only
 * a list of overdue candidacies CGS picks from (see RpdDismissalController).
 *
 * Chain: Dean of PGR (endorsement) -> Faculty (final approval). Registry is
 * NOT a stage here -- same category of question as Claims' Project Director,
 * resolved the same way: Registry doesn't decide anything, it issues the
 * termination notice as a post-approval action once Faculty approves (see
 * RpdDismissalController::decide()), the way PD's payment processing happens
 * after Manager CGS approves in Claims. Modelling Registry as a third Stage
 * would mean it could reject or stall a decision that was already final.
 */
class RpdDismissalWorkflow implements WorkflowModule, ProvidesLinks
{
    public function key(): string
    {
        return 'rpd_dismissal';
    }

    public function label(): string
    {
        return 'RPD Dismissal';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage('dean', 'Dean of PGR', Role::DEAN_PGR, 'endorsed'),
            new Stage('faculty', 'Faculty', Role::FACULTY, 'terminated', queueTitle: 'Pending Final Decision'),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = RpdDismissalDetail::where('application_id', $application->id)->first();

        return $detail ? 'RPD Dismissal — '.$detail->reason : 'RPD Dismissal case';
    }

    public function createRoute(): ?string
    {
        // CGS-initiated from the overdue-candidacies list, not self-submitted
        // by a student -- see RpdDismissalController::create().
        return null;
    }

    public function queueRoute(): string
    {
        return 'rpd-dismissal.queue';
    }

    /**
     * Non-Exec CGS owns no stage in this chain -- they initiate the case, they
     * do not decide it -- so the stage-derived sidebar would never show them
     * a way to reach the overdue-candidacies list without this.
     */
    public function links(User $user): array
    {
        return match ($user->role) {
            Role::NON_EXEC_CGS => [
                ['label' => 'Overdue RPD Candidacies', 'route' => 'rpd-dismissal.create'],
                ['label' => 'Upcoming RPD Reminders', 'route' => 'rpd-reminders.index'],
                ['label' => 'Record Failed RPD Attempt', 'route' => 'candidacy.failed-attempt.create'],
            ],
            default => [],
        };
    }
}
