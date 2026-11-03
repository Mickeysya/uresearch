<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: brings candidacy_appeal_details up to the CGS paper form's
 * actual Section A-D structure (phase, RCS, prior-extension flags,
 * disclaimer), rather than the freeform reason/months pair it launched
 * with. Every new column is nullable/defaulted so the one appeal already
 * submitted through the earlier form keeps working unchanged; 'reason'
 * itself is untouched at the DB level (still NOT NULL) and instead becomes
 * optional purely at the validation layer -- see CandidacyAppealController.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidacy_appeal_details', function (Blueprint $table) {
            // Section A -- Academic Progress / Current Status
            $table->string('phase')->nullable()->after('reason');
            $table->unsignedTinyInteger('writing_completion_percent')->nullable()->after('phase');

            // Research Completion Seminar -- either a completed one (date +
            // category) or, if not yet done, an expected date.
            $table->string('rcs_status')->nullable()->after('writing_completion_percent');
            $table->date('rcs_date')->nullable()->after('rcs_status');
            $table->string('rcs_category')->nullable()->after('rcs_date');
            $table->date('rcs_expected_date')->nullable()->after('rcs_category');

            // Section B -- Candidacy Extension Record (prior extensions)
            $table->boolean('extension_via_gsc')->default(false)->after('rcs_expected_date');
            $table->boolean('extension_via_vc')->default(false)->after('extension_via_gsc');

            // Disclaimer acknowledgement -- required at submission time.
            $table->timestamp('disclaimer_acknowledged_at')->nullable()->after('extension_via_vc');
        });
    }

    public function down(): void
    {
        Schema::table('candidacy_appeal_details', function (Blueprint $table) {
            $table->dropColumn([
                'phase', 'writing_completion_percent',
                'rcs_status', 'rcs_date', 'rcs_category', 'rcs_expected_date',
                'extension_via_gsc', 'extension_via_vc',
                'disclaimer_acknowledged_at',
            ]);
        });
    }
};
