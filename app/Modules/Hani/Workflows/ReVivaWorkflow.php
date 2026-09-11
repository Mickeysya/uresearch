<?php

namespace App\Modules\Hani\Workflows;

use App\Modules\Core\Contracts\ProvidesLinks;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Hani\Models\ReVivaDetail;

/**
 * Re-examination monitoring.
 *
 * A discrete stepper, not a fake progress bar: the four stages below are
 * administrative tracking events the Academic Executive advances, all one
 * role, all 'approve' in engine terms -- there is nothing to reject mid
 * stepper, so the queue view never renders a reject button even though the
 * route technically accepts one.
 *
 * The 5-level outcome (see ReVivaDetail) is recorded separately, after the
 * chain reaches STATUS_APPROVED, and never touches applications.status or
 * current_stage -- that would violate the one rule this whole engine exists
 * to enforce. A level-4 loop-back is therefore not a loop in this Stage
 * graph at all: it is CGS Staff logging a new Application the next time the
 * student's re-corrected thesis reaches them, linked to the previous cycle
 * by ReVivaDetail::previous_cycle_id. See ReVivaController::create().
 *
 * Logged by CGS Staff, not filed by the student -- so unlike every other
 * module, createRoute() is null (it never belongs in a student's "New
 * Application" list) and the create screen is reached instead via the
 * CGS-only link below, the same mechanism the Examiner Pool admin screen
 * uses to appear for that role.
 */
class ReVivaWorkflow implements WorkflowModule, ProvidesLinks
{
    public function key(): string
    {
        return 're_viva';
    }

    public function label(): string
    {
        return 'Re-viva Monitoring';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage('report_sent', 'Report Sent', Role::ACADEMIC_EXEC, 'sent',
                queueTitle: 'Reports to Send'),
            new Stage('under_panel_review', 'Under Panel Review', Role::ACADEMIC_EXEC, 'reviewed',
                queueTitle: 'Awaiting Panel Review'),
            new Stage('report_received', 'Report Received', Role::ACADEMIC_EXEC, 'received',
                queueTitle: 'Awaiting Report'),
            new Stage('consolidation_scheduled', 'Consolidation Scheduled', Role::ACADEMIC_EXEC, 'consolidated',
                queueTitle: 'Awaiting Consolidation'),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = ReVivaDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return 'Re-viva monitoring';
        }

        return "Cycle {$detail->cycle_number} — resubmitted ".$detail->resubmission_at->format('j M Y');
    }

    public function createRoute(): ?string
    {
        return null;
    }

    public function queueRoute(): string
    {
        return 'reviva.queue';
    }

    /**
     * Two roles need a link here that queuesForRole() (stage-derived) cannot
     * produce: CGS Staff logs a new cycle but owns no stage in this chain;
     * the Academic Executive's outcome-recording step happens after an
     * application leaves the chain, not on any stage in it.
     */
    public function links(User $user): array
    {
        return match ($user->role) {
            Role::NON_EXEC_CGS => [
                ['label' => 'Log Re-viva Submission', 'route' => 'reviva.create'],
            ],
            Role::ACADEMIC_EXEC => [
                ['label' => 'Re-viva Outcomes', 'route' => 'reviva.outcomes.index'],
            ],
            default => [],
        };
    }
}
