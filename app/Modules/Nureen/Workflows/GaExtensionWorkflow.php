<?php

namespace App\Modules\Nureen\Workflows;

use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Nureen\Models\GaExtensionDetail;
use Illuminate\Support\Str;

/**
 * Graduate Assistantship extension requests.
 *
 * Three fixed stages: Supervisor endorses, CGS verifies, Senior Director
 * decides.
 *
 * Ported from the legacy ga_extension_* pages, which stored their position in
 * current_stage as 'supervisor_approved' / 'cgs_verified' while keeping status
 * at 'pending' throughout. That vocabulary did not match the other modules, so
 * the shared tracking page rendered every GA application as a Claims record
 * stuck on step 1. Declaring the chain here puts it on the same footing as
 * everything else.
 */
class GaExtensionWorkflow implements WorkflowModule
{
    public function key(): string
    {
        return 'ga_extension';
    }

    public function label(): string
    {
        return 'GA Extension & VISA';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage(
                key: 'supervisor',
                label: 'Lecturer/Supervisor',
                role: Role::SUPERVISOR,
                decision: 'endorsed',
                queueTitle: 'Pending My Endorsement',
            ),
            new Stage(
                key: 'cgs_verify',
                label: 'CGS Staff',
                role: Role::NON_EXEC_CGS,
                decision: 'reviewed',
                queueTitle: 'Pending My Verification',
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
        $detail = GaExtensionDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return 'GA extension request';
        }

        return 'Extend to '.$detail->requested_new_end_date->format('j M Y')
            .' — '.Str::limit($detail->reason_for_extension, 70);
    }

    public function createRoute(): ?string
    {
        return 'ga-extension.create';
    }

    public function queueRoute(): string
    {
        return 'ga-extension.queue';
    }
}
