<?php

namespace App\Modules\Norhanis\Workflows;

use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Norhanis\Models\RpdAppealDetail;

/**
 * RPD Appeal / Extension -- a student who needs more time on their Research
 * Proposal Defence candidacy asks for it here.
 *
 * One fixed chain: Supervisor -> Chair -> Non-Exec CGS -> Dean of PGR. The
 * Dean's approval is the moment that matters most in this module: it is the
 * only place candidacies.deadline is ever recalculated (see
 * RpdAppealController::decide()), which is what the scope document calls
 * "updating the masterlist" -- the candidacies row IS the masterlist entry.
 *
 * The student requests a number of months (1-6, agreed with CGS); the
 * Dean's approval extends the current deadline by that many months -- see
 * extendCandidacy() on the controller.
 */
class RpdAppealWorkflow implements WorkflowModule
{
    public function key(): string
    {
        return 'rpd_appeal';
    }

    public function label(): string
    {
        return 'RPD Appeal / Extension';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage('supervisor', 'Lecturer/Supervisor', Role::SUPERVISOR, 'endorsed'),
            new Stage('chair', 'Chair of Department', Role::CHAIR, 'endorsed'),
            new Stage('cgs_review', 'Non-Executive CGS', Role::NON_EXEC_CGS, 'reviewed'),
            new Stage('dean', 'Dean of PGR', Role::DEAN_PGR, 'approved'),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = RpdAppealDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return 'RPD Appeal / Extension request';
        }

        $months = $detail->requested_extension_months;

        return "Requested extension: {$months} ".($months === 1 ? 'month' : 'months');
    }

    public function createRoute(): ?string
    {
        return 'rpd-appeal.create';
    }

    public function queueRoute(): string
    {
        return 'rpd-appeal.queue';
    }
}
