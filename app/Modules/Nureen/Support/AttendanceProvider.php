<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Contracts\SuppliesAttendance;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\AttendanceReading;
use App\Modules\Nureen\Models\AttendanceRecord;
use Illuminate\Support\Collection;

/**
 * Nureen's attendance module, as Core's dashboards see it.
 *
 * The only place that knows both `attendance_records` and Core's
 * AttendanceReading. Everything else on either side of the boundary talks to
 * one or the other, never both -- which is the point: Core's dashboards no
 * longer name a class in this folder, so renaming AttendanceRecord or
 * changing its columns is a change inside this module again.
 *
 * Registered from this module's ModuleProvider. If this module were removed,
 * nothing would bind SuppliesAttendance and Core's attendance panels would
 * hold their skeletons rather than fatal.
 */
class AttendanceProvider implements SuppliesAttendance
{
    public function latestFor(User $student): ?AttendanceReading
    {
        $record = AttendanceRecord::where('student_id', $student->id)
            ->orderByDesc('period_end')
            ->first();

        return $record ? $this->toReading($record) : null;
    }

    public function projectionFor(User $student): ?float
    {
        $record = AttendanceRecord::where('student_id', $student->id)
            ->orderByDesc('period_end')
            ->first();

        // The projection rule itself stays in AttendanceRiskEvaluator, next
        // to isAtRisk() -- the two are the same small explicit rule set and
        // are meant to be read together.
        return $record ? AttendanceRiskEvaluator::project($record) : null;
    }

    /** @return Collection<int, AttendanceReading> */
    public function latestPerStudent(): Collection
    {
        return AttendanceRecord::latestPerStudent()
            ->get()
            ->map(fn (AttendanceRecord $r) => $this->toReading($r));
    }

    protected function toReading(AttendanceRecord $record): AttendanceReading
    {
        return new AttendanceReading(
            percentage: (float) $record->percentage,
            period_end: $record->period_end,
            sessions_attended: (int) $record->sessions_attended,
            sessions_total: (int) $record->sessions_total,
            at_risk: (bool) $record->at_risk,
        );
    }
}
