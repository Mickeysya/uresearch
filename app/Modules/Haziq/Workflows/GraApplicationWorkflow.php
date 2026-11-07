<?php

namespace App\Modules\Haziq\Workflows;

use App\Modules\Core\Contracts\ProvidesLinks;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Haziq\Models\StageGate;

/**
 * GRA Application — new appointments and extensions alike.
 *
 * Extension is not a separate module: it re-submits through this same
 * four-stage chain with GraApplicationDetail::application_type = 'extension'
 * and parent_application_id pointing at the original appointment. That is
 * what your interim report calls "extensions reuse the same approval
 * workflow", and it is also why there is no collision with Nureen's
 * ga_extension — that module is GA-only, this one is GRA-only.
 *
 * Four stages: Admin verifies, CGS Staff / Supervisor / Senior Director each
 * approve or reject once. An earlier round of this chain gave Supervisor a
 * two-click "In Process" -> "Settled" step (two Stage entries sharing one
 * role); reverted 2026-10-11 back to one stage, same shape as CGS Staff and
 * Senior Director, after it rendered as a confusing duplicate "Supervisor"
 * step on the stepper.
 *
 * Admin's stage is verification, not approval: a positive decision here
 * just means "the paperwork is in order", so it uses 'verified' rather than
 * 'approved' as its decision verb. Admin never rejects outright — if
 * something is wrong, Admin raises an ApplicationCorrectionRequest instead
 * (Http\Controllers\Concerns\HandlesApplicationCorrections), which does not
 * touch status/current_stage at all. The first stage with real reject power
 * is CGS Staff.
 */
class GraApplicationWorkflow implements WorkflowModule, ProvidesLinks
{
    public function key(): string
    {
        return 'gra_application';
    }

    public function label(): string
    {
        return 'GRA Application';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage(
                key: 'admin_verify',
                label: 'Admin',
                role: Role::ADMIN,
                decision: 'verified',
                queueTitle: 'Pending Document Verification',
            ),
            new Stage(
                key: 'cgs_review',
                label: 'CGS Staff',
                role: Role::NON_EXEC_CGS,
                decision: 'reviewed',
                queueTitle: 'Pending CGS Approval',
            ),
            new Stage(
                key: 'supervisor',
                label: 'Supervisor',
                role: Role::SUPERVISOR,
                decision: 'endorsed',
                queueTitle: 'Pending My Review',
            ),
            new Stage(
                key: 'senior_director',
                label: 'Senior Director CGS',
                role: Role::SENIOR_DIRECTOR_CGS,
                decision: 'approved',
                queueTitle: 'Final Approval',
            ),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = GraApplicationDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return 'GRA application';
        }

        $prefix = $detail->isExtension() ? 'GRA Extension' : 'GRA Application';

        return $prefix.' — '.$detail->project_title;
    }

    public function createRoute(): ?string
    {
        return 'gra-application.create';
    }

    public function queueRoute(): string
    {
        return 'gra-application.queue';
    }

    /**
     * Extra links the approval-chain-derived sidebar cannot infer on its
     * own: extension (a student action outside any queue), and Stage
     * Gate / Allowance monitoring for the roles that use it.
     */
    public function links(User $user): array
    {
        // TA Hours moved to Norhanis' module; its link is on her Travel section.
        $links = [];

        if ($user->isStudent()) {
            // Without this, the only way back to a submitted application
            // was knowing the URL — the "Apply GRA" link only ever points
            // at the blank create form.
            $mine = GraApplicationDetail::where('application_type', 'new')
                ->whereHas('application', fn ($q) => $q
                    ->where('student_id', $user->id)
                    ->where('module_type', 'gra_application')
                    ->where('status', '!=', Application::STATUS_DRAFT))
                ->latest('id')
                ->first();

            if ($mine) {
                $links[] = ['label' => 'My GRA Application', 'route' => 'gra-application.mine', 'params' => ['application' => $mine->application_id]];
            }

            if (GraGaStudentProfile::where('user_id', $user->id)->exists()) {
                $links[] = ['label' => 'GRA Extension', 'route' => 'gra-application.extension.create'];
            }

            // Only once an approval has actually generated a schedule —
            // covers GA appointments too, since the screen itself scopes to
            // "every gate belonging to this student" regardless of module.
            if (StageGate::whereHas('application', fn ($q) => $q->where('student_id', $user->id))->exists()) {
                $links[] = ['label' => 'My Stage Gate', 'route' => 'stage-gate.index'];
                // Same scope that unlocks Stage Gate also means a payment
                // log exists to show — confirmed explicitly 2026-10-23:
                // "student akan nampak die nye je" (own row only, the
                // controller itself scopes this).
                $links[] = ['label' => 'My Allowance', 'route' => 'allowance.index'];
            }

            // Only while actually terminated — confirmed explicitly
            // 2026-10-15: this is its own action, reached from its own
            // sidebar entry, not something buried inside the GRA
            // application form (see CandidatureAppealController). Links
            // straight to the appeal form for the most recently
            // terminated appointment, not just back to Stage Gate (which
            // already has "My Stage Gate" for that). Once a Senior
            // Director approves the appeal, candidature_status flips back
            // to active and this link disappears on its own — nothing
            // else to track.
            $terminatedGra = GraApplicationDetail::where('candidature_status', 'terminated')
                ->whereHas('application', fn ($q) => $q->where('student_id', $user->id)->where('module_type', 'gra_application'))
                ->latest('id')->first();
            $terminatedGa = GaApplicationDetail::where('candidature_status', 'terminated')
                ->whereHas('application', fn ($q) => $q->where('student_id', $user->id)->where('module_type', 'ga_application'))
                ->latest('id')->first();

            $terminated = $terminatedGra ?? $terminatedGa;

            if ($terminated) {
                $links[] = ['label' => 'Appeal Termination', 'route' => 'candidature-appeal.create', 'params' => ['application' => $terminated->application_id]];
            }

            return $links;
        }

        if ($user->isAdmin() || $user->isCgs() || $user->role === Role::SUPERVISOR) {
            $links[] = ['label' => 'GRA Applicants', 'route' => 'gra-application.index'];
            $links[] = ['label' => 'Stage Gate Monitoring', 'route' => 'stage-gate.index'];
            $links[] = ['label' => 'Allowance Eligibility', 'route' => 'allowance.index'];
            $links[] = ['label' => 'Candidature Appeals', 'route' => 'candidature-appeal.queue'];
        }

        return $links;
    }
}
