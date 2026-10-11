<?php

namespace App\Modules\Norhanis\Workflows;

use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Norhanis\Models\ClaimsDetail;

/**
 * Student Claims — journal publication claims only.
 *
 * Confirmed with CGS: no expense-item breakdown and no cash advance, which
 * both belonged to travel reimbursement and are out of scope here. The
 * student enters total_claims directly; approvers check it against the
 * attached proof of payment. External Claims were also explicitly excluded
 * from scope after confirming with the Academic Executive that they are
 * handled outside the student portal — this module only ever produces
 * `claims_student` applications.
 *
 * One fixed chain, no conditional routing: Supervisor -> Chair -> Non-Exec
 * CGS -> Manager CGS. Manager CGS has the final say; payment processing by
 * the Project Director happens after approval and outside this app, so it
 * is not modelled as a Stage.
 */
class ClaimsWorkflow implements WorkflowModule
{
    public function key(): string
    {
        return 'claims_student';
    }

    public function label(): string
    {
        return 'Student Claims';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage('supervisor', 'Lecturer/Supervisor', Role::SUPERVISOR, 'endorsed'),
            new Stage('chair', 'Chair of Department', Role::CHAIR, 'endorsed'),
            new Stage('cgs_review', 'Non-Executive CGS', Role::NON_EXEC_CGS, 'reviewed'),
            new Stage('manager', 'Manager CGS', Role::MANAGER_CGS, 'approved'),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = ClaimsDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return 'Student Claims application';
        }

        return 'Journal claim · RM '.number_format($detail->total_claims, 2).' · '.$detail->bank_name;
    }

    public function createRoute(): ?string
    {
        return 'claims.create';
    }

    public function queueRoute(): string
    {
        return 'claims.queue';
    }
}
