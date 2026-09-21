<?php

namespace App\Modules\Jason\Workflows;

use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Jason\Models\HardboundAppealDetail;

/**
 * Appeal against a rejected hardbound submission.
 *
 * The candidate files the appeal with a memo and a written justification,
 * the Non-Executive CGS compiles the Dean PFR report from that memo and the
 * original submission, and the Senior Executive CGS rules on it.
 *
 * An upheld appeal is meant to reopen the original submission. It does not
 * rewrite it: `applications.status` belongs to WorkflowEngine, and the
 * original's rejection is a decision on the record, not a mistake to erase.
 * Instead an approved appeal unlocks the resubmission form for the
 * submission it names -- see HardboundSubmissionController::resubmittableDetail().
 */
class HardboundAppealWorkflow implements WorkflowModule
{
    public function key(): string
    {
        return 'hardbound_appeal';
    }

    public function label(): string
    {
        return 'Appeal Hardbound Submission';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage(
                key: 'cgs_review',
                label: 'Non-Executive CGS',
                role: Role::NON_EXEC_CGS,
                decision: 'reviewed',
                queueTitle: 'Appeals to Compile',
            ),
            new Stage(
                key: 'cgs_approve',
                label: 'Senior Executive CGS',
                role: Role::SENIOR_EXEC_CGS,
                decision: 'approved',
                queueTitle: 'Appeals for Ruling',
            ),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = HardboundAppealDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return 'Hardbound submission appeal';
        }

        return 'Appeal against submission #'.$detail->hardbound_application_id;
    }

    public function createRoute(): ?string
    {
        return 'hardbound-appeal.create';
    }

    public function queueRoute(): string
    {
        return 'hardbound-appeal.queue';
    }
}
