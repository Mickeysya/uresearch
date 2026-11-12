<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Contracts\SuppliesSupervisionHistory;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\SupervisionDetail;

/**
 * Core\Contracts\SuppliesSupervisionHistory, from approved Supervision
 * requests:
 *   co        approved Co-Supervisor appointments whose letter is not revoked
 *             (the same rule as SupervisionDetail::hasActiveCoSupervisor());
 *   previous  the main supervisor each approved Main Supervisor appointment
 *             replaced (previous_supervisor_id), and a main supervisor whose
 *             appointment letter was later revoked.
 */
class SupervisionHistoryProvider implements SuppliesSupervisionHistory
{
    public function supervisorsFor(User $student): array
    {
        $details = SupervisionDetail::with(['requestedSupervisor', 'previousSupervisor', 'application'])
            ->whereHas('application', fn ($q) => $q->where('student_id', $student->id)->where('status', Application::STATUS_APPROVED))
            ->get()
            ->sortBy(fn (SupervisionDetail $d) => $d->letter_issued_at ?? $d->application->updated_at);

        $co = $details
            ->filter(fn (SupervisionDetail $d) => $d->appointment_role === SupervisionDetail::ROLE_CO && $d->letter_revoked_at === null && $d->requestedSupervisor)
            ->map(fn (SupervisionDetail $d) => ['user' => $d->requestedSupervisor, 'since' => $d->letter_issued_at ?? $d->application->updated_at])
            ->unique(fn (array $row) => $row['user']->id)
            ->values()->all();

        $previous = [];
        foreach ($details->where('appointment_role', SupervisionDetail::ROLE_MAIN) as $d) {
            $when = $d->letter_issued_at ?? $d->application->updated_at;

            if ($d->previousSupervisor) {
                $previous[] = ['user' => $d->previousSupervisor, 'until' => $when, 'note' => 'Replaced as main supervisor'];
            }

            if ($d->letter_revoked_at && $d->requestedSupervisor) {
                $previous[] = ['user' => $d->requestedSupervisor, 'until' => $d->letter_revoked_at, 'note' => 'Appointment letter revoked'];
            }
        }

        // The current main supervisor is not "previous", whatever history says.
        $previous = array_values(array_filter($previous, fn (array $row) => $row['user']->id !== $student->supervisor_id));

        return ['co' => $co, 'previous' => $previous];
    }

    public function coSupervisedStudentIds(User $supervisor): array
    {
        return Application::query()
            ->where('module_type', 'supervision')
            ->where('status', Application::STATUS_APPROVED)
            ->whereIn('id', SupervisionDetail::query()
                ->where('requested_supervisor_id', $supervisor->id)
                ->where('appointment_role', SupervisionDetail::ROLE_CO)
                ->whereNull('letter_revoked_at')
                ->select('application_id'))
            ->distinct()
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
