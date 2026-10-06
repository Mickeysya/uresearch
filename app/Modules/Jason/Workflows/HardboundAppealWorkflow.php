<?php

namespace App\Modules\Jason\Workflows;

use App\Modules\Core\Contracts\DecidesOneAtATime;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Jason\Models\HardboundAppealDetail;

/**
 * Appeal Hardbound Submission: a request to extend the hardbound thesis
 * submission deadline.
 *
 * A candidate who cannot submit on time fills in the extension memo the
 * portal provides, giving the reason and the date they are asking for.
 *
 * The memo is addressed to the Dean of Postgraduate and Research and routed
 * "Through" the Supervisor and the HOD/Chair, which is the chain the portal
 * walks: each of them endorses in turn, and their uploaded signature and the
 * date they endorsed are stamped into the memo -- the same mechanism the
 * Confirmation of Correction uses, and the same signature on file. The
 * Non-Executive CGS then reviews the memo and acknowledges it.
 *
 * The chain ENDS at that acknowledgement, and deliberately: CGS takes the
 * decision offline and emails the candidate personally, so the portal would
 * be guessing if it claimed an outcome. What the last stage records is
 * "memo received and under consideration", which is why its decision verb is
 * `received` rather than `approved`, and why the candidate's notice says to
 * wait for CGS rather than announcing an extension.
 *
 * REPLACED, 2026-09-23. This module was first built as an appeal against a
 * rejected submission, which is what docs/scope/jason.md §3 describes: a
 * Dean PFR report compiled by CGS and a ruling by the Senior Executive. CGS
 * means an extension request by it. The scope document is the older
 * understanding; this is the workshop one.
 */
class HardboundAppealWorkflow implements WorkflowModule, DecidesOneAtATime
{
    public function key(): string
    {
        // Stored in rows, so permanent -- it stays `hardbound_appeal` even
        // though the module now means an extension request. Labels are
        // display-only and say what it actually is.
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
                key: 'supervisor',
                label: 'Supervisor',
                role: Role::SUPERVISOR,
                decision: 'endorsed',
                queueTitle: 'Extension Memos to Endorse',
            ),
            new Stage(
                key: 'chair',
                label: 'HOD / Chair of Department',
                role: Role::CHAIR,
                decision: 'endorsed',
                queueTitle: 'Extension Memos to Endorse',
            ),
            new Stage(
                key: 'cgs_review',
                label: 'Non-Executive CGS',
                role: Role::NON_EXEC_CGS,
                // Not `approved`: receiving the memo is not granting the
                // extension. CGS decides offline and emails the candidate.
                decision: 'received',
                queueTitle: 'Extension Memos to Review',
            ),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = HardboundAppealDetail::where('application_id', $application->id)->first();

        if (! $detail?->requested_until) {
            return 'Hardbound submission extension request';
        }

        $summary = 'Extension requested until '.$detail->requested_until->format('j M Y');

        // The badge says "approved" once CGS has the memo, which on its own
        // would read as though the extension had been granted.
        if ($application->status === Application::STATUS_APPROVED) {
            $summary .= '. CGS has the memo and will email you the outcome';
        }

        return $summary;
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
