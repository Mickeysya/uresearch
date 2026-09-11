<?php

namespace App\Modules\Nureen\Workflows;

use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Nureen\Models\SupervisionDetail;

/**
 * Supervisor appointment requests.
 *
 * Two stages: the named supervisor endorses, then CGS runs an eligibility
 * review. CGS's approval is what makes the student officially supervised --
 * see SupervisionController::decide(), which sets users.supervisor_id there
 * rather than in this class, since that write is a side effect of a
 * decision, not part of the chain itself.
 */
class SupervisionWorkflow implements WorkflowModule
{
    public function key(): string
    {
        return 'supervision';
    }

    public function label(): string
    {
        return 'Supervision';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage(
                key: 'supervisor',
                label: 'Lecturer/Supervisor',
                role: Role::SUPERVISOR,
                decision: 'endorsed',
                queueTitle: 'Pending My Endorsement',
            ),
            new Stage(
                key: 'cgs_review',
                label: 'Non-Executive CGS',
                role: Role::NON_EXEC_CGS,
                decision: 'approved',
                queueTitle: 'Pending Eligibility Review',
            ),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = SupervisionDetail::with('requestedSupervisor')->where('application_id', $application->id)->first();

        if (! $detail) {
            return 'Supervisor appointment request';
        }

        return 'Requesting '.$detail->requestedSupervisor->name.' as supervisor';
    }

    public function createRoute(): ?string
    {
        return 'supervision.create';
    }

    public function queueRoute(): string
    {
        return 'supervision.queue';
    }
}
