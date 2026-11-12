<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Contracts\SuppliesAttendanceOverview;
use App\Modules\Nureen\Models\AttendanceRiskState;

/**
 * Core\Contracts\SuppliesAttendanceOverview: the figures the early warning
 * last evaluated (AttendanceRiskState) for many students in one query --
 * the same semester-so-far %, projection and flag the CGS At-Risk list
 * shows, so the Students page can never disagree with it.
 */
class AttendanceOverviewProvider implements SuppliesAttendanceOverview
{
    public function overviewFor(iterable $studentIds): array
    {
        $ids = collect($studentIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return AttendanceRiskState::whereIn('student_id', $ids)->get()
            ->mapWithKeys(fn (AttendanceRiskState $s) => [$s->student_id => [
                'percentage' => $s->current_percentage,
                'projected_percentage' => $s->projected_percentage,
                'at_risk' => (bool) $s->at_risk,
                'latest_period_end' => $s->latest_period_end,
            ]])
            ->all();
    }
}
