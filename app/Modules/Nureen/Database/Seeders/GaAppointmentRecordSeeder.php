<?php

namespace App\Modules\Nureen\Database\Seeders;

use App\Modules\Core\Support\Role;
use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\GaAppointmentRecord;
use Illuminate\Database\Seeder;

/**
 * A handful of GA/GRA appointment records, so Support\AppointmentVerifier
 * has something real to check a certification request against for a demo
 * -- Haziq's module, which owns this data in principle, has none yet (see
 * the migration that creates ga_appointment_records).
 *
 * Picks real seeded students rather than naming specific ones, so this
 * still works whatever the rest of the demo data looks like: the first is
 * given a record that matches a plausible certification request (reads as
 * "Verified"), the second a record of the wrong type/period (reads as
 * "Mismatch"), and the rest are left alone on purpose -- a student with no
 * row at all is exactly the "Not Found" case, and does not need one.
 *
 * Matched on student_id, so running it twice replaces rather than
 * duplicates.
 *
 *     php artisan db:seed --class="App\Modules\Nureen\Database\Seeders\GaAppointmentRecordSeeder"
 *
 * Not called from Core's DatabaseSeeder: that file belongs to the whole
 * team, and this data is only useful for demoing this module.
 */
class GaAppointmentRecordSeeder extends Seeder
{
    public function run(): void
    {
        $students = User::where('role', Role::STUDENT)->orderBy('id')->limit(2)->get();

        if ($students->count() < 1) {
            $this->command?->warn('No seeded students found -- nothing to attach a GA/GRA appointment record to.');

            return;
        }

        if ($verified = $students->get(0)) {
            GaAppointmentRecord::updateOrCreate(
                ['student_id' => $verified->id],
                [
                    'appointment_type' => 'GA',
                    'period_start' => now()->subMonths(8)->startOfMonth(),
                    'period_end' => now()->addMonths(4)->endOfMonth(),
                ],
            );
        }

        if ($mismatched = $students->get(1)) {
            GaAppointmentRecord::updateOrCreate(
                ['student_id' => $mismatched->id],
                [
                    // On file as a GRA for a period that has already
                    // ended -- a GA certification request covering the
                    // current period reads as "Mismatch", not "Verified".
                    'appointment_type' => 'GRA',
                    'period_start' => now()->subYear(),
                    'period_end' => now()->subMonths(7),
                ],
            );
        }

        $this->command?->info('Seeded GA/GRA appointment records for '.$students->count().' student(s).');
    }
}
