<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The reference seat-map PDF prints its own slide titles (N2-03-01-02,
 * "PG LAB MALE 1.9", ...), but those aren't what's actually on the room
 * doors -- CGS confirmed the physical door plates read N2-02-01, J2-01-06,
 * etc. This renames the already-seeded rooms to match the real labels; the
 * seat data and counts underneath are unchanged, only room_code and name.
 */
return new class extends Migration
{
    protected array $renames = [
        'N2-03-01-02' => 'N2-02-01',
        'N2-03-03-04' => 'N2-02-03',
        'N2-03-05-06' => 'N2-02-05',
        'N2-03-07-08' => 'N2-02-07',
        'N2-03-10-11' => 'N2-02-11',
        'N2-03-12-13' => 'N2-02-12',
        'N2-03-14-15' => 'N2-02-14',
        'J2-1.9' => 'J2-01-06',
        'J2-1.2' => 'J2-01-05',
        'J2-1.1' => 'J2-01-03',
    ];

    public function up(): void
    {
        foreach ($this->renames as $old => $new) {
            $row = DB::table('workstation_locations')->where('room_code', $old)->first();

            if (! $row) {
                continue;
            }

            // The J2 rows carried a descriptive name ("PG LAB MALE 1.9")
            // that isn't on the door at all -- keep it in the description
            // instead of discarding it, now that name is just the code.
            $description = str_starts_with($new, 'J2-')
                ? trim($row->name.' -- '.($row->description ?? ''), ' -')
                : $row->description;

            DB::table('workstation_locations')
                ->where('id', $row->id)
                ->update(['room_code' => $new, 'name' => $new, 'description' => $description]);
        }
    }

    public function down(): void
    {
        foreach ($this->renames as $old => $new) {
            DB::table('workstation_locations')
                ->where('room_code', $new)
                ->update(['room_code' => $old, 'name' => $old]);
        }
    }
};
