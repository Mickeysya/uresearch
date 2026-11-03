<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\GaAppointmentRecord;
use Carbon\CarbonInterface;

/**
 * "Does this certification request match a real appointment" -- the check
 * that lets CGS Staff handle only the exceptions, per the supervisor's
 * "lacks automation" feedback.
 *
 * Checks against ga_appointment_records, the provisional stand-in for
 * Haziq's (not yet built) GRA/GA module -- see that table's migration.
 * Purely informational: nothing here blocks approve/reject, it only tells
 * CGS what the system already checked.
 */
class AppointmentVerifier
{
    public const VERIFIED = 'verified';

    public const NOT_FOUND = 'not_found';

    public const MISMATCH = 'mismatch';

    /**
     * @return array{status: string, record: ?GaAppointmentRecord}
     */
    public function verify(
        User $student,
        string $appointmentType,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): array {
        $records = GaAppointmentRecord::where('student_id', $student->id)->get();

        if ($records->isEmpty()) {
            return ['status' => self::NOT_FOUND, 'record' => null];
        }

        // An appointment of the right type whose own period covers the
        // requested one -- a GA certifying for a period outside what is on
        // file is exactly the case CGS needs flagged, not waved through.
        $matching = $records->first(fn (GaAppointmentRecord $record) => $record->appointment_type === $appointmentType
            && $record->period_start->lte($periodStart)
            && $record->period_end->gte($periodEnd));

        if ($matching) {
            return ['status' => self::VERIFIED, 'record' => $matching];
        }

        // A record exists for this student, just not one that matches --
        // worth showing CGS which record was on file, not just "no match".
        return ['status' => self::MISMATCH, 'record' => $records->first()];
    }
}
