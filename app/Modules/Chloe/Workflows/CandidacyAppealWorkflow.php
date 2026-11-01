<?php

namespace App\Modules\Chloe\Workflows;

use App\Modules\Chloe\Models\CandidacyAppealDetail;
use App\Modules\Core\Contracts\ProvidesLinks;
use App\Modules\Core\Contracts\SuppliesApplicationDetail;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;

/**
 * Student -> Supervisor -> Department Chair -> CGS Verification -> Dean of PGR.
 *
 * "Department Chair" reuses Role::CHAIR (see app/Modules/Chloe/README.md —
 * docs/module-keys.md and TODO.md both already tentatively concluded this is
 * the same person as Norhanis' "Department Chair", still unconfirmed with
 * CGS). "CGS Verification" and "Dean" reuse the `cgs_verify`/`non_exec_cgs`
 * and `dean`/`dean_pgr` pairing already listed in docs/module-keys.md's
 * stage-key table, put there for exactly this chain.
 */
class CandidacyAppealWorkflow implements WorkflowModule, SuppliesApplicationDetail, ProvidesLinks
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
            new Stage('chair', 'Department Chair', Role::CHAIR, 'endorsed'),
            new Stage('cgs_verify', 'CGS Verification', Role::NON_EXEC_CGS, 'verified'),
            new Stage('dean', 'Dean of PGR', Role::DEAN_PGR, 'approved'),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = CandidacyAppealDetail::where('application_id', $application->id)->first();

        return $detail
            ? "Extension request: {$detail->requested_extension_months} month(s)"
            : 'Study candidacy appeal';
    }

    /**
     * Sidebar and dashboard shortcuts through the module mechanism (no shared layout edit): the portal queue for every CGS role
     * (the engine only knows non_exec_cgs as a stage owner) and "My Signature" for everyone who signs.
     */
    public function links(\App\Modules\Core\Models\User $user): array
    {
        $links = [];

        if (in_array($user->role, array_diff((array) config('candidacy.cgs_roles'), [Role::NON_EXEC_CGS]), true)) {
            $links[] = ['label' => 'Candidacy Appeals', 'route' => 'candidacy.portal.queue'];
        }

        if (in_array($user->role, array_merge(Role::cgsTeam(), [Role::REGISTRY]), true)) {
            $links[] = ['label' => 'Dismissal Upload', 'route' => 'candidacy.cgs.dismissal-upload'];
        }

        if (in_array($user->role, array_unique(array_merge([Role::SUPERVISOR, Role::CHAIR, Role::DEAN_PGR], (array) config('candidacy.cgs_roles'))), true)) {
            $links[] = ['label' => 'My Signature', 'route' => 'candidacy.signature.show'];
        }

        return $links;
    }

    public function createRoute(): ?string
    {
        return 'candidacy-appeal.create';
    }

    public function queueRoute(): string
    {
        return 'candidacy.portal.queue';
    }

    /**
     * Reused on both screens: the approver's queue (via
     * core::partials.queue's $detailView) and the student's own "Track My
     * Application" page (via this interface) both include the same
     * partial. See Resources/views/candidacy/appeal/_detail.blade.php.
     */
    public function detailView(): string
    {
        return 'chloe::candidacy.appeal._detail';
    }
}
