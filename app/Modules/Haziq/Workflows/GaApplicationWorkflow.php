<?php

namespace App\Modules\Haziq\Workflows;

use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Support\Roles;

/**
 * GA Application. Same profile/document shape as GRA (Section D/E/F via
 * the shared gra_ga_student_profiles) but a different chain and a
 * different life after approval — confirmed explicitly 2026-11-03: "ga is
 * different from gra."
 *
 * Five stages, same shape as GRA's own chain with Research Centre's
 * interview inserted between Admin and CGS: Admin verifies the paperwork
 * (never rejects outright, same as GRA — see
 * GaApplicationController::decide()), Research Centre conducts the
 * interview and records Pass/Fail with a report attached (a Fail rejects
 * outright, right here — CGS never sees it), then CGS Staff / Supervisor
 * / Senior Director each approve or reject once, same as GRA.
 *
 * Unlike GRA, approval never generates a Stage Gate schedule or touches
 * allowance tracking at all — "after approve takdelah stage gate or
 * allowance bagai bagai tu, die just application approve" — a GA
 * appointment is just the approved record plus its offer letters.
 *
 * GA extension is out of scope for this module entirely — that stays
 * Nureen's ga_extension.
 */
class GaApplicationWorkflow implements WorkflowModule
{
    public function key(): string
    {
        return 'ga_application';
    }

    public function label(): string
    {
        return 'GA Application';
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
                key: 'research_centre_interview',
                label: 'Research Centre',
                role: Roles::RESEARCH_CENTRE,
                decision: 'interviewed',
                queueTitle: 'Pending Interview',
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
        $detail = GaApplicationDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return 'GA application';
        }

        return 'GA Application — '.($detail->department_unit ?? $detail->purpose ?? 'General');
    }

    public function createRoute(): ?string
    {
        return 'ga-application.create';
    }

    public function queueRoute(): string
    {
        return 'ga-application.queue';
    }
}
