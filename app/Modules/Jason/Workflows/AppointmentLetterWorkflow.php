<?php

namespace App\Modules\Jason\Workflows;

use App\Modules\Core\Contracts\ProvidesLinks;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Jason\Models\AppointmentExaminer;

/**
 * Examiner appointment letters.
 *
 * Nomination -- the Chair filing an examiner panel, at least one internal and
 * one external, for one of the department's candidates -- has been removed;
 * nothing currently creates a new application here. What remains is the
 * chain that still processes whatever already exists: the Academic Executive
 * endorses (or rejects with comments), then the Non-Executive CGS prepares
 * the pack -- the candidate's degree, programme, supervisor and thesis
 * title, auto-filled from the candidate's own record wherever one exists,
 * plus each examiner's address and reference number. Preparing generates two
 * documents per examiner -- the appointment letter and the thesis evaluation
 * report form -- and archives them, so the Dean of PGR approves documents
 * that already exist. On the Dean's approval each examiner is emailed their
 * own two documents. Examiners have no account in this system, so that
 * dispatch happens outside the WorkflowEngine/ApplicationDecided path.
 * See AppointmentLetterController::generatePack() and dispatchPacks().
 *
 * A straight linear chain, no conditional routing.
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
        $examiners = AppointmentExaminer::where('application_id', $application->id)
            ->orderByRaw("CASE WHEN examiner_type = 'internal' THEN 0 ELSE 1 END")
            ->get();

        if ($examiners->isEmpty()) {
            return 'Examiner panel nomination';
        }

        return $examiners
            ->map(fn ($e) => $e->examiner_name.' ('.$e->examiner_type.')')
            ->implode(', ');
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
     * The Chair no longer files nominations, so they get nothing from this
     * chain -- see the class doc comment. CGS keeps the examiner list, so a
     * new examiner can still be registered by whoever hears of them first.
     */
    public function links(User $user): array
    {
        return match ($user->role) {
            Role::NON_EXEC_CGS => [
                ['label' => 'Examiner List', 'route' => 'appointment-letter.examiners'],
                ['label' => 'Issued Appointments', 'route' => 'appointment-letter.issued'],
            ],
            default => [],
        };
    }
}
