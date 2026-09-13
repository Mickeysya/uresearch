<?php

namespace App\Modules\Jason\Workflows;

use App\Modules\Core\Contracts\ProvidesLinks;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Jason\Models\AppointmentDetail;

/**
 * Examiner appointment letters.
 *
 * The Chair of Department nominates an external/internal examiner for one of
 * the department's candidates. The Academic Executive endorses (or rejects
 * with comments), the Non-Executive CGS then prepares the letter body -- the
 * candidate's degree, programme, supervisor and thesis title, auto-filled
 * from the candidate's own record wherever one exists -- and the Dean of PGR
 * gives final approval. On the Dean's approval the controller generates the
 * Appointment Letter PDF and emails it straight to the examiner -- who has no
 * account in this system at all, so that dispatch happens outside the
 * WorkflowEngine/ApplicationDecided path.
 * See AppointmentLetterController::issueAppointmentLetter().
 *
 * A straight linear chain, no conditional routing -- the closest analogue is
 * Hani's ExaminerNominationWorkflow, not Norhanis' branching TravelWorkflow.
 */
class AppointmentLetterWorkflow implements WorkflowModule, ProvidesLinks
{
    public function key(): string
    {
        return 'appointment_letter';
    }

    public function label(): string
    {
        return 'Appointment Letter';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage(
                key: 'academic_exec',
                label: 'Academic Executive',
                role: Role::ACADEMIC_EXEC,
                decision: 'endorsed',
                queueTitle: 'Pending My Endorsement',
            ),
            new Stage(
                key: 'cgs_prep',
                label: 'Non-Executive CGS',
                role: Role::NON_EXEC_CGS,
                decision: 'prepared',
                queueTitle: 'Letters to Prepare',
            ),
            new Stage(
                key: 'dean',
                label: 'Dean of PGR',
                role: Role::DEAN_PGR,
                decision: 'approved',
                queueTitle: 'Pending My Approval',
            ),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = AppointmentDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return 'Appointment letter nomination';
        }

        return 'Examiner: '.$detail->examiner_name.' ('.$detail->examiner_institution.')';
    }

    public function createRoute(): ?string
    {
        // Filed by the Chair of Department, not by students, so it never
        // appears under a student's "New Application" list.
        return null;
    }

    public function queueRoute(): string
    {
        return 'appointment-letter.queue';
    }

    /**
     * The Chair owns no stage in this chain (nomination is stage zero, filed
     * before the chain starts), so without this the nomination form would
     * never appear in their sidebar. Same shape as Hani's supervisor link
     * for Examiner Nomination.
     */
    public function links(User $user): array
    {
        if ($user->role !== Role::CHAIR) {
            return [];
        }

        return [
            ['label' => 'Nominate Examiner', 'route' => 'appointment-letter.create'],
        ];
    }
}
