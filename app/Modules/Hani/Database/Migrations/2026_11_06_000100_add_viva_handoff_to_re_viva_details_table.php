<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 3 item b: the viva hands off to the re-viva.
 *
 *   viva_application_id     the viva_voce notice whose category-4 result
 *                           this re-viva re-examines (every round of the
 *                           same re-examination links the same viva)
 *   resubmission_opens_at   the window the corrected thesis was due in:
 *   resubmission_closes_at  round 1 copies viva_details' window; round N+1
 *                           runs from the level-4 outcome date
 *                           (hani.reviva.next_round_window_months)
 *   viva_result_date        legacy: no viva result in UResearch, so the
 *                           Senior Executive entered the date and the
 *                           window was worked out from it
 *   logged_outside_window   logged before the window opened or after it
 *                           closed: warned, never blocked
 *
 * How the two original deadline columns map (they stay, nullable now):
 *
 *   correction_deadline  rows logged before Prompt 3 keep "resubmission + 6
 *                        months". New rows leave it empty until the outcome,
 *                        then it holds the final-thesis deadline (levels 1-3):
 *                        the same meaning, "corrections due".
 *   hardbound_deadline   rows logged before Prompt 3 keep "resubmission + 12
 *                        months". New rows leave it empty: the hardbound
 *                        thesis belongs to Jason's module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('re_viva_details', function (Blueprint $table) {
            $table->foreignId('viva_application_id')->nullable()->after('previous_cycle_id')
                ->constrained('applications')->nullOnDelete();
            $table->date('resubmission_opens_at')->nullable()->after('resubmission_at');
            $table->date('resubmission_closes_at')->nullable()->after('resubmission_opens_at');
            $table->date('viva_result_date')->nullable()->after('resubmission_closes_at');
            $table->boolean('logged_outside_window')->default(false)->after('viva_result_date');

            $table->date('correction_deadline')->nullable()->change();
            $table->date('hardbound_deadline')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('re_viva_details', function (Blueprint $table) {
            $table->dropForeign(['viva_application_id']);
            $table->dropColumn([
                'viva_application_id', 'resubmission_opens_at', 'resubmission_closes_at',
                'viva_result_date', 'logged_outside_window',
            ]);
        });
    }
};
