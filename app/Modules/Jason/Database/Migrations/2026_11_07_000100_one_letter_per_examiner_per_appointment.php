<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per examiner per appointment, enforced by the database.
 *
 * The import now refuses a candidate who already has an appointment, which
 * is where duplicates actually came from. This is the backstop under it: a
 * second route to the same mistake -- a retried request, a future caller,
 * a hand-written fix -- fails loudly here rather than quietly sending a
 * second letter over a second Dean's signature.
 *
 * Scoped to rows that name an examiner in the nomination chain's records.
 * Rows matched by name alone carry null there, and MySQL treats each null
 * as distinct, so they are unaffected -- as are the rows imported before
 * that column existed.
 *
 * If the table already holds a duplicate the migration stops and names it,
 * rather than failing on an index it cannot build. Nothing is deleted
 * automatically: which of two appointments is the real one is a question
 * about letters that may already have been sent, and that is CGS's call.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('appointment_examiners')
            ->select('application_id', 'hani_examiner_id', DB::raw('COUNT(*) as occurrences'))
            ->whereNotNull('hani_examiner_id')
            ->groupBy('application_id', 'hani_examiner_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $named = $duplicates
                ->map(fn ($row) => "application #{$row->application_id} / examiner {$row->hani_examiner_id}"
                    ." ({$row->occurrences} rows)")
                ->implode('; ');

            throw new RuntimeException(
                'appointment_examiners already holds the same examiner twice on one appointment, so the '
                ."unique index cannot be added: {$named}. Decide which row is the real appointment -- check "
                .'whether a letter went out for either -- remove the other, then run this migration again.'
            );
        }

        Schema::table('appointment_examiners', function (Blueprint $table) {
            $table->unique(['application_id', 'hani_examiner_id'], 'appointment_examiners_one_per_examiner');
        });
    }

    public function down(): void
    {
        Schema::table('appointment_examiners', function (Blueprint $table) {
            $table->dropUnique('appointment_examiners_one_per_examiner');
        });
    }
};
