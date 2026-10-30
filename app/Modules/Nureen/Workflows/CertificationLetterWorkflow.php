<?php

namespace App\Modules\Nureen\Workflows;

use App\Modules\Core\Contracts\DecidesOneAtATime;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Nureen\Models\GaCertificationDetail;

/**
 * GA/GRA Certification Letter.
 *
 * Three stages: the student's own named supervisor reviews first, then CGS
 * Staff verify the appointment type, then Senior Director CGS gives the
 * final digital endorsement. The 'cgs_verify' / 'senior_director' keys are
 * unchanged from before this module had a supervisor stage at all -- an
 * application already sitting at either one (submitted before this change)
 * is found and decided exactly the same way, since current_stage is looked
 * up by key, not by position in the chain. See CertificationController::
 * decide(), which addresses the new stage to one named person the same way
 * SupervisionController does, not to everyone holding the Supervisor role.
 * The Senior Director's approval is still what triggers PDF generation.
 */
class CertificationLetterWorkflow implements WorkflowModule, DecidesOneAtATime
{
    public function key(): string
    {
        return 'ga_certification';
    }

    public function label(): string
    {
        return 'Certification Letter';
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
