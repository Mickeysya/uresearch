<?php

namespace App\Modules\Nureen\Workflows;

use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Nureen\Models\GaCertificationDetail;

/**
 * GA/GRA Certification Letter.
 *
 * CGS staff verify the appointment type, Senior Director CGS gives the final
 * digital endorsement -- the same two-stage shape as GA Extension, reusing
 * its 'cgs_verify' / 'senior_director' stage keys. The Senior Director's
 * approval is also what triggers PDF generation; see
 * CertificationController::decide().
 */
class CertificationLetterWorkflow implements WorkflowModule
{
    public function key(): string
    {
        return 'ga_certification';
    }

    public function label(): string
    {
        return 'GA/GRA Certification Letter';
    }

    public function stages(?Application $application = null): array
    {
        return [
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
                queueTitle: 'Final Endorsement',
            ),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = GaCertificationDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return 'GA/GRA certification request';
        }

        return $detail->appointment_type.' certification, '
            .$detail->period_start->format('M Y').' – '.$detail->period_end->format('M Y');
    }

    public function createRoute(): ?string
    {
        return 'ga-certification.create';
    }

    public function queueRoute(): string
    {
        return 'ga-certification.queue';
    }
}
