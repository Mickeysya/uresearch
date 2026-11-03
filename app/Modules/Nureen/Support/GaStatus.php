<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\GaAppointmentRecord;

/**
 * Whether a student is a Graduate Assistant right now, from
 * GaAppointmentRecord: true (an appointment of one of
 * config('nureen.supervision.pgfac_memo.ga_types') covers today), false
 * (appointments are on record but none of those does), or null (nothing on
 * record at all -- the form then asks the student to declare it).
 */
final class GaStatus
{
    public static function fromRecord(User $student): ?bool
    {
        $records = GaAppointmentRecord::where('student_id', $student->id)->get();

        if ($records->isEmpty()) {
            return null;
        }

        $types = config('nureen.supervision.pgfac_memo.ga_types', ['GA']);
        $today = now()->startOfDay();

        return $records->contains(fn (GaAppointmentRecord $record) => in_array($record->appointment_type, $types, true)
            && $record->period_start->lte($today)
            && $record->period_end->gte($today));
    }
}
