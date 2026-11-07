<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fills in the nomination chain's examiner id on appointments written
 * before this module stored it.
 *
 * Those rows name their examiner and nothing else, so a panel they belong
 * to cannot be compared against the chain's current seats. The comparison
 * now falls back to the name, which closes the gap on its own -- this is
 * the tidier half: where the name identifies exactly one examiner in the
 * records, the id is written down and the fallback is not needed again.
 *
 * Exact, unique matches only. A name held by two examiners is left alone:
 * picking one would tie a letter to the wrong person, and the consequence
 * of that is withholding someone's letter because another person appears
 * to have had it. A name the records do not hold is left alone too.
 *
 * Reads another module's table and writes only to this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('examiners')) {
            return;
        }

        $rows = DB::table('appointment_examiners')
            ->whereNull('hani_examiner_id')
            ->get(['id', 'examiner_name']);

        foreach ($rows->groupBy(fn ($row) => mb_strtolower(trim($row->examiner_name))) as $name => $group) {
            if ($name === '') {
                continue;
            }

            $matches = DB::table('examiners')
                ->whereRaw('LOWER(TRIM(name)) = ?', [$name])
                ->pluck('id');

            // One examiner, or leave it: an ambiguous name is worse than
            // no name, because it looks authoritative.
            if ($matches->count() !== 1) {
                continue;
            }

            DB::table('appointment_examiners')
                ->whereIn('id', $group->pluck('id'))
                ->update(['hani_examiner_id' => $matches->first()]);
        }
    }

    /**
     * Left in place. The ids are correct, and which rows this filled in is
     * not recorded, so undoing it would have to blank ids that other
     * imports wrote legitimately.
     */
    public function down(): void {}
};
