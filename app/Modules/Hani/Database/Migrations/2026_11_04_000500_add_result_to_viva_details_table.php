<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 5 of the viva: the result and the corrections. Module-side state on
 * the viva record, not a workflow stage: the notice's own chain finished
 * long ago, and nothing here writes applications.status.
 *
 *   result_category        1-5, from the chairman's viva report
 *   corrections_due_at     categories 1-3: from the viva date (config)
 *   resubmission_opens_at  category 4: the re-examination window
 *   resubmission_closes_at
 *   corrections_*          the corrected final thesis, and the AE accepting it
 *   examiners_freed_at     category 4 only: the window closed with no
 *                          re-viva logged, so the panel was freed (no gap)
 *
 * Deadlines are stored, not recomputed, so a later policy change never moves
 * a running deadline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viva_details', function (Blueprint $table) {
            $table->unsignedTinyInteger('result_category')->nullable()->after('schedule_sequence');
            $table->timestamp('result_recorded_at')->nullable()->after('result_category');
            $table->foreignId('result_recorded_by_id')->nullable()->after('result_recorded_at')->constrained('users')->nullOnDelete();
            $table->date('corrections_due_at')->nullable()->after('result_recorded_by_id');
            $table->date('resubmission_opens_at')->nullable()->after('corrections_due_at');
            $table->date('resubmission_closes_at')->nullable()->after('resubmission_opens_at');
            $table->timestamp('corrections_submitted_at')->nullable()->after('resubmission_closes_at');
            $table->timestamp('corrections_accepted_at')->nullable()->after('corrections_submitted_at');
            $table->foreignId('corrections_accepted_by_id')->nullable()->after('corrections_accepted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('examiners_freed_at')->nullable()->after('corrections_accepted_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('viva_details', function (Blueprint $table) {
            $table->dropForeign(['result_recorded_by_id']);
            $table->dropForeign(['corrections_accepted_by_id']);
            $table->dropColumn([
                'result_category', 'result_recorded_at', 'result_recorded_by_id', 'corrections_due_at',
                'resubmission_opens_at', 'resubmission_closes_at', 'corrections_submitted_at',
                'corrections_accepted_at', 'corrections_accepted_by_id', 'examiners_freed_at',
            ]);
        });
    }
};
