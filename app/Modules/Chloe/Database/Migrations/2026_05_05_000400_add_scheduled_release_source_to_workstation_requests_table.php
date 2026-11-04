<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguishes a scheduled_release_date the daily job derived itself (from
 * StudyCandidacy.dismissed_at -- a real, reliable signal) from one set by
 * the TEST-ONLY workstation:set-scheduled-release command (simulating
 * "Completed", for which no reliable signal exists anywhere in the
 * codebase today -- see STEP 1's findings). Without this, the job's "clear
 * scheduled_release_date if the student is no longer Dismissed" rule would
 * also wipe out a manually-set test date on its very next run, since a
 * test student is never actually Dismissed in StudyCandidacy either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstation_requests', function (Blueprint $table) {
            // dismissed | test
            $table->string('scheduled_release_source', 20)->nullable()->after('scheduled_release_date');
        });
    }

    public function down(): void
    {
        Schema::table('workstation_requests', function (Blueprint $table) {
            $table->dropColumn('scheduled_release_source');
        });
    }
};
