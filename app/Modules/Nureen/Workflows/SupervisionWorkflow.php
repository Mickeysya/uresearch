<?php

namespace App\Modules\Nureen\Workflows;

use App\Modules\Core\Contracts\DecidesOneAtATime;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Nureen\Models\SupervisionDetail;

/**
 * Supervisor (Main or Co-) appointment requests.
 *
 * Two chains, chosen per application at submission time and never revisited
 * afterwards -- see SupervisionDetail::cgs_stage_snapshot:
 *   'automatic' DEFAULT, confirmed against CGS's UResearch process (0
 *                Client Charter days for the CGS step). The named supervisor
 *                endorses, then the Chair of Department approves -- and
 *                that approval is final: the appointment letter is issued
 *                immediately. CGS keeps control AFTER issuance instead of
 *                before it; see SupervisionController::decide()/revokeLetter().
 *   'manual'     The cgs_review stage still exists after the Chair: Non-
 *                Executive CGS makes its own approve/reject decision, and
 *                the letter is generated only then -- closer to the
 *                original behaviour, before the Chair of Department stage
 *                existed at all.
 *
 * Either way, the Chair (department-scoped, like Academic Executive --
 * see Role::isDepartmentScoped()) approving a Main Supervisor appointment is
 * what makes the student officially supervised; see
 * SupervisionController::decide(), which sets users.supervisor_id there
 * rather than in this class, since that write is a side effect of a
 * decision, not part of the chain itself.
 */
class SupervisionWorkflow implements WorkflowModule, DecidesOneAtATime
{
    public function key(): string
    {
        return 'supervision';
    }

    public function label(): string
    {
        return 'Supervision Request';
    }

    public function stages(?Application $application = null): array
    {
        $mode = $this->modeFor($application);

        $supervisorStage = new Stage(
            key: 'supervisor',
            label: 'Lecturer/Supervisor',
            role: Role::SUPERVISOR,
            decision: 'endorsed',
            queueTitle: 'Pending My Endorsement',
        );

        if ($mode === 'manual') {
            return [
                $supervisorStage,
                new Stage(
                    key: 'chair',
                    label: 'Chair of Department',
                    role: Role::CHAIR,
                    decision: 'endorsed',
                    queueTitle: 'Pending Department Approval',
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

        return [
            $supervisorStage,
            new Stage(
                key: 'chair',
                label: 'Chair of Department',
                role: Role::CHAIR,
                decision: 'approved',
                queueTitle: 'Pending Department Approval',
            ),
        ];
    }

    /**
     * 'automatic' or 'manual' -- the application's own snapshot when it has
     * one, otherwise the live config (true for a brand new application
     * mid-submit, before SupervisionController::store() has written one).
     */
    public function modeFor(?Application $application): string
    {
        if ($application) {
            $detail = SupervisionDetail::where('application_id', $application->id)->first();

            if ($detail?->cgs_stage_snapshot) {
                return $detail->cgs_stage_snapshot;
            }
        }

        return config('nureen.supervision.cgs_stage');
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
