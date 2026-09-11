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
 * graph at all: it is the student filing a new Application the next time
 * they upload a re-corrected thesis, linked to the previous cycle by
 * ReVivaDetail::previous_cycle_id. See ReVivaController::create().
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
        return 'reviva.create';
    }

    public function queueRoute(): string
    {
        return 'reviva.queue';
    }

    /**
     * The outcome-recording step happens after an application leaves the
     * stage chain, so queuesForRole() (stage-derived) never surfaces it.
     */
    public function links(User $user): array
    {
        if ($user->role !== Role::ACADEMIC_EXEC) {
            return [];
        }

        return [
            ['label' => 'Re-viva Outcomes', 'route' => 'reviva.outcomes.index'],
        ];
    }
}
