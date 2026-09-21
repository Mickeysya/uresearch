<?php

namespace App\Modules\Norhanis\Workflows;

use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Norhanis\Models\RpdDismissalDetail;

/**
 * Dismissal for exceeded candidacy — Dean → Faculty.
 *
 * norhanis.md Module 4, path 3: "Non-Exec CGS initiates dismissal. Routes to
 * Dean (Endorsement) → Faculty (Final Approval). Once approved, Registry issues
 * the official notification."
 *
 * Registry is NOT a stage: it decides nothing. Its termination email fires as a
 * post-approval action once Faculty approves (see RpdDismissalController::decide()),
 * the same resolution as Project Director on Claims.
 *
 * Non-Exec CGS is deliberately NOT a stage. They author the case, they do not
 * approve it — the same shape as Hani's re_viva, where CGS opens the record and
 * the Academic Executive advances it. Putting the initiator in the chain would
 * mean CGS approving their own submission at step one.
 *
 * createRoute() returns null because no student can file this. That keeps it
 * out of the student sidebar; the CGS entry point is the masterlist.
 */
class RpdDismissalWorkflow implements WorkflowModule
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
            new Stage(
                key: 'dean',
                label: 'Dean of PGR',
                role: Role::DEAN_PGR,
                decision: 'endorsed',
                queueTitle: 'Dismissals Pending My Endorsement',
            ),
            new Stage(
                key: 'faculty',
                label: 'Faculty',
                role: Role::FACULTY,
                decision: 'approved',
                queueTitle: 'Dismissals Pending Final Approval',
            ),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = RpdDismissalDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return 'RPD dismissal';
        }

        return 'Deadline missed '.$detail->deadline_missed_on->format('j M Y');
    }

    /** Nobody files this against themselves. */
    public function createRoute(): ?string
    {
        return null;
    }

    public function queueRoute(): string
    {
        return 'rpd-dismissal.queue';
    }
}
