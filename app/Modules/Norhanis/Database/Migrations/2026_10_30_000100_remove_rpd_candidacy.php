<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * RPD Candidacy (reminders, extension appeals, dismissals) is out of
 * Norhanis' scope and its code has been removed from this module.
 *
 * On a database that already ran the old RPD migrations this tidies up: the
 * RPD applications go (their stored files first, then the rows, which takes
 * their approval history and document rows with them by cascade), then the
 * four RPD tables. On a fresh database none of it exists and this does
 * nothing. The `rpd_appeal` and `rpd_dismissal` module keys stay retired in
 * docs/module-keys.md, so nobody reuses them for something else.
 */
return new class extends Migration
{
    protected const RETIRED_KEYS = ['rpd_appeal', 'rpd_dismissal'];

    public function up(): void
    {
        $ids = DB::table('applications')->whereIn('module_type', self::RETIRED_KEYS)->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('application_documents')->whereIn('application_id', $ids)->pluck('path')
                ->each(fn (string $path) => Storage::disk('local')->delete($path));

            DB::table('applications')->whereIn('id', $ids)->delete();
        }

        // Children before the table they point at.
        Schema::dropIfExists('rpd_dismissal_details');
        Schema::dropIfExists('rpd_appeal_details');
        Schema::dropIfExists('rpd_reminder_logs');
        Schema::dropIfExists('candidacies');
    }

    /** One way only: the code these tables served is gone. */
    public function down(): void
    {
    }
};
