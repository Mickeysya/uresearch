<?php

namespace App\Modules\Chloe\Workflows;

use App\Modules\Chloe\Models\CandidacyAppealDetail;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;

/**
 * Student -> Supervisor -> Programme Chair -> CGS Verification -> Dean of PGR.
 *
 * "Programme Chair" reuses Role::CHAIR (see app/Modules/Chloe/README.md —
 * docs/module-keys.md and TODO.md both already tentatively concluded this is
 * the same person as Norhanis' "Chair of Department", still unconfirmed with
 * CGS). "CGS Verification" and "Dean" reuse the `cgs_verify`/`non_exec_cgs`
 * and `dean`/`dean_pgr` pairing already listed in docs/module-keys.md's
 * stage-key table, put there for exactly this chain.
 */
class CandidacyAppealWorkflow implements WorkflowModule
{
    public function key(): string
    {
        return 'candidacy_appeal';
    }

    public function label(): string
    {
        return 'Study Candidacy Appeal';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage('supervisor', 'Lecturer/Supervisor', Role::SUPERVISOR, 'endorsed'),
            new Stage('chair', 'Programme Chair', Role::CHAIR, 'endorsed'),
            new Stage('cgs_verify', 'CGS Verification', Role::NON_EXEC_CGS, 'verified'),
            new Stage('dean', 'Dean of PGR', Role::DEAN_PGR, 'approved'),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = CandidacyAppealDetail::where('application_id', $application->id)->first();

        return $detail
            ? "Extension request — {$detail->requested_extension_months} month(s)"
            : 'Study candidacy appeal';
    }

    public function createRoute(): ?string
    {
        return 'candidacy-appeal.create';
    }

    public function queueRoute(): string
    {
        return 'candidacy-appeal.queue';
    }
}
