<?php

namespace App\Modules\Chloe\Workflows;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Core\Contracts\DecidesOneAtATime;
use App\Modules\Core\Contracts\ProvidesLinks;
use App\Modules\Core\Contracts\SuppliesApplicationDetail;
use App\Modules\Core\Contracts\SuppliesSignature;
use App\Modules\Chloe\Support\CandidacyStaffDirectory;
use App\Modules\Core\Models\User;
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
class CandidacyAppealWorkflow implements WorkflowModule, DecidesOneAtATime, SuppliesApplicationDetail, ProvidesLinks, SuppliesSignature
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
            new Stage('supervisor', 'Supervisor / Programme Manager', Role::SUPERVISOR, 'endorsed'),
            new Stage('chair', 'Department Chair', Role::CHAIR, 'endorsed'),
            new Stage('cgs_verify', 'CGS Verification', Role::NON_EXEC_CGS, 'verified'),
            new Stage('dean', 'Dean of PGR', Role::DEAN_PGR, 'approved'),
        ];
    }

    public function summary(Application $application): string
    {
        // Only the one appeal filed through the shared engine before 2026-11-01 comes here; its data now lives in candidacy_appeals.
        $months = CandidacyAppeal::where('legacy_application_id', $application->id)->value('requested_months');

        return $months
            ? "Extension request: {$months} month(s)"
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
            $links[] = ['label' => 'Study Candidacy Appeals', 'route' => 'candidacy.portal.queue'];
        }

        if (in_array($user->role, (array) config('candidacy.cgs_roles'), true)) {
            // The shared CGS sidebar hard-codes Operations and Dismissal List; Insights is added here (Actions) until a line is added there.
            $links[] = ['label' => 'Study Candidacy Insights', 'route' => 'candidacy.cgs.insights'];
        }

        if (in_array($user->role, array_merge(Role::cgsTeam(), [Role::REGISTRY]), true)) {
            $links[] = ['label' => 'Dismissal Upload', 'route' => 'candidacy.cgs.dismissal-upload'];
        }

        // No "My Signature" link here any more: Core's single "My Signatures"
        // page shows this module's signature as one section -- signatureFor().

        return $links;
    }

    /**
     * This module's section on Core's "My Signatures" page: the same people
     * the candidacy.signature routes serve. Required for the Supervisor,
     * Chair and Dean (CandidacyAppealService refuses their approval without
     * one); optional for
     * CGS, whose stamp is text-only without one.
     */
    public function signatureFor(User $user): ?array
    {
        $signers = array_unique(array_merge([Role::SUPERVISOR, Role::CHAIR, Role::DEAN_PGR], (array) config('candidacy.cgs_roles')));

        if (! in_array($user->role, $signers, true)) {
            return null;
        }

        $profile = CandidacyStaffDirectory::profileForUser($user);
        $onFile = $profile?->hasSignature() ?? false;

        return [
            'key' => 'candidacy',
            'title' => 'Study Candidacy Appeals',
            'usedFor' => 'Stamped onto every Study Candidacy Appeal you approve. Appeals you already approved keep the copy you had at the time.',
            'required' => in_array($user->role, [Role::SUPERVISOR, Role::CHAIR, Role::DEAN_PGR], true),
            'imageUrl' => $onFile ? route('candidacy.signature.image') : null,
            'updatedAt' => $onFile ? $profile->signature_updated_at : null,
            'storeRoute' => 'candidacy.signature.store',
            'destroyRoute' => 'candidacy.signature.destroy',
            'hint' => 'PNG or JPG, at most 1 MB and at least 200 x 60 pixels. A white or transparent background works best.',
        ];
    }

    public function createRoute(): ?string
    {
        // The appeal form page itself (it decides editable / read-only / continue / resubmit). The old
        // candidacy-appeal.create URL still redirects here.
        return 'candidacy.appeals.new';
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
