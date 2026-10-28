<?php

namespace App\Modules\Nureen\Support\AttendanceSources;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Support\Contracts\AttendanceSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Generates one plausible week's record for every seeded student who does
 * not already have one for the current week-ending date -- for a demo with
 * no real Power Automate export to point at, not for production.
 *
 * Occasionally under-attends a student (roughly 1 in 5) so the at-risk path
 * has something to show on a fresh run, rather than every simulated row
 * landing at a suspiciously uniform near-100%.
 */
class SimulatedSource implements AttendanceSource
{
    public function label(): string
    {
        return 'Simulated';
    }

    public function pull(): Collection
    {
        $periodEnd = CarbonImmutable::now()->endOfWeek(CarbonImmutable::FRIDAY)->format('Y-m-d');

        $already = AttendanceRecord::whereDate('period_end', $periodEnd)
            ->pluck('student_id')
            ->all();

        return User::where('role', Role::STUDENT)
            ->get()
            ->reject(fn (User $student) => in_array($student->id, $already, true))
            ->map(fn (User $student) => $this->rowFor($student, $periodEnd))
            ->values();
    }

    /** @return array{matric_no: ?string, period_end: string, sessions_attended: int, sessions_total: int} */
    protected function rowFor(User $student, string $periodEnd): array
    {
        $total = random_int(4, 6);

        $attended = random_int(0, 4) === 0
            ? random_int(0, (int) floor($total * 0.7))
            : random_int((int) ceil($total * 0.85), $total);

        return [
            'matric_no' => $student->matric_no,
            'period_end' => $periodEnd,
            'sessions_attended' => $attended,
            'sessions_total' => $total,
        ];
    }
}
