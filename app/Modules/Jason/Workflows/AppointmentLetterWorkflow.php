<?php

namespace App\Modules\Jason\Workflows;

use App\Modules\Core\Contracts\DecidesOneAtATime;
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
 * Examiner SELECTION happens before this module: the supervisor chooses the
 * panel, the Academic Executive compiles it, and CGS, the Senior Director,
 * the Chair and the Dean settle the list between them. That is Hani's
 * Examiner Nomination chain. What arrives here is the finalised list, as a
 * spreadsheet, and this module starts by importing it -- one appointment per
 * candidate on it, each carrying its own panel.
 *
 * The Non-Executive CGS then prepares the pack: the candidate's degree,
 * programme, supervisor and thesis title, auto-filled from the candidate's
 * own record wherever one exists, plus each examiner's address and reference
 * number. Preparing generates two documents per examiner -- the appointment
 * letter and the thesis evaluation report form -- and archives them, so the
 * Dean of PGR approves documents that already exist. On the Dean's approval
 * each examiner is emailed their own two documents. Examiners have no
 * account in this system, so that dispatch happens outside the
 * WorkflowEngine/ApplicationDecided path.
 * See AppointmentLetterController::generatePack() and dispatchPacks().
 *
 * Two stages, no conditional routing. It was three until 2026-09-30: the
 * Chair filed a nomination and the Academic Executive endorsed it, which is
 * the work that now happens upstream in Hani's chain. Keeping it here would
 * have meant the same panel being chosen twice.
 */
class AppointmentLetterWorkflow implements WorkflowModule, DecidesOneAtATime, ProvidesLinks
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
                key: 'cgs_prep',
                label: 'Non-Executive CGS',
                role: Role::NON_EXEC_CGS,
                decision: 'prepared',
                queueTitle: 'Packs to Prepare',
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
        // Opened by CGS importing the finalised list, not by anybody filling
        // in a form, so it never appears under a student's "New
        // Application" list.
        return null;
    }

    public function queueRoute(): string
    {
        return 'appointment-letter.queue';
    }

    /**
     * Importing the list is stage zero -- it happens before the chain
     * starts, so no stage owns it and it would otherwise appear in nobody's
     * sidebar.
     */
    public function links(User $user): array
    {
        return match ($user->role) {
            Role::NON_EXEC_CGS => [
                ['label' => 'Import Examiner List', 'route' => 'appointment-letter.import'],
                ['label' => 'Issued Appointments', 'route' => 'appointment-letter.issued'],
            ],
            default => [],
        };
    }
}
