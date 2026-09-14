<?php

namespace App\Modules\Core\Services\Concerns;

use App\Modules\Core\Contracts\SuppliesAttendance;

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
}
