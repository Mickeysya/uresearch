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
 * Three chains, chosen per application at submission time and never
 * revisited afterwards -- see SupervisionDetail::cgs_stage_snapshot:
 *   'endorse'    DEFAULT. Supervisor, Chair of Department, then Non-
 *                Executive CGS previews and endorses the letter at
 *                cgs_review (0 Client Charter days: same working day).
 *                Endorsing issues the letter in the endorser's own name,
 *                so a letter never names an endorser who did not act.
 *   'automatic'  The Chair of Department's approval is final and the
 *                letter is issued at once, stating it was issued by CGS
 *                following the Chair's approval -- no CGS endorser is
 *                named. CGS keeps control AFTER issuance (revocation).
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
    /** Every config('nureen.supervision.cgs_stage') value a snapshot can hold. */
    public const MODES = ['endorse', 'automatic', 'manual'];

    public function key(): string
    {
        return 'supervision';
    }

    public function label(): string
    {
        return 'Supervision Request';
    }

    /**
     * With an application: the chain it was submitted under (its snapshot).
     *
     * Without one: every stage EITHER chain can use, as Core's contract
     * asks -- ModuleRegistry::queuesForRole() builds the sidebar and gates
     * the queue pages from this. Returning only the live config's chain
     * was a bug: in the default 'automatic' mode it dropped 'cgs_review',
     * so Non-Executive CGS could no longer reach the older 'manual'
     * applications already waiting there.
     */
    public function stages(?Application $application = null): array
    {
        if ($application === null) {
            $live = config('nureen.supervision.cgs_stage');
            $stages = $this->chainFor($live);
            $keys = array_map(fn (Stage $s) => $s->key, $stages);

            foreach (array_diff(self::MODES, [$live]) as $mode) {
                foreach ($this->chainFor($mode) as $stage) {
                    if (! in_array($stage->key, $keys, true)) {
                        $stages[] = $stage;
                        $keys[] = $stage->key;
                    }
                }
            }

            return $stages;
        }

        return $this->chainFor($this->modeFor($application));
    }

    /** @return array<int, Stage> */
    protected function chainFor(string $mode): array
    {
        $supervisorStage = new Stage(
            key: 'supervisor',
            label: 'Lecturer/Supervisor',
            role: Role::SUPERVISOR,
            decision: 'endorsed',
            queueTitle: 'Pending My Endorsement',
        );

        if ($mode === 'endorse') {
            return [
                $supervisorStage,
                new Stage(
                    key: 'chair',
                    label: 'Chair of Department',
                    role: Role::CHAIR,
                    decision: 'endorsed',
                    queueTitle: 'Pending Department Approval',
                ),
                // Same Stage key as 'manual' mode's eligibility review, so
                // an application sitting at cgs_review is found the same way
                // whichever of the two it was submitted under. Endorsing
                // here is what issues the letter -- see
                // SupervisionController::decide()/previewLetter().
                new Stage(
                    key: 'cgs_review',
                    label: 'Non-Executive CGS',
                    role: Role::NON_EXEC_CGS,
                    decision: 'endorsed',
                    queueTitle: 'Pending Letter Endorsement',
                ),
            ];
        }

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
     * 'endorse', 'automatic' or 'manual' -- the application's own snapshot when it has
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
