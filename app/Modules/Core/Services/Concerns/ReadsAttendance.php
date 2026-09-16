<?php

namespace App\Modules\Core\Services\Concerns;

use App\Modules\Core\Contracts\SuppliesAttendance;
use Illuminate\Support\Collection;

/**
 * How a Core dashboard reaches attendance without naming the module that
 * owns it.
 *
 * Returns null when no module has bound SuppliesAttendance, which is the
 * honest answer to "is attendance available here" and replaces the
 * `class_exists(AttendanceRecord::class)` checks Core used to make. The
 * caller holds that panel's skeleton, exactly as before.
 */
trait ReadsAttendance
{
    protected function attendanceSource(): ?SuppliesAttendance
    {
        return app()->bound(SuppliesAttendance::class)
            ? app(SuppliesAttendance::class)
            : null;
    }

    /**
     * The most recent reading for every student, one each, memoised for the
     * request. Both the CGS bands and the admin average want this, and they
     * each used to wrap it in a one-line method under their own name -- which
     * is how the same query ends up memoised under two keys.
     *
     * @return \Illuminate\Support\Collection<int, \App\Modules\Core\Support\AttendanceReading>
     */
    protected function latestPerStudent(SuppliesAttendance $source): Collection
    {
        return $this->remember('attendance.latestPerStudent', fn () => $source->latestPerStudent());
    }
}
