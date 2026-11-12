<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Period Candidature" on the real Requisition and Extension forms
     * (Section C, right beside Admission Date) -- a free-text line on the
     * physical form, not a structured date range, so stored the same way
     * here. Confirmed explicitly 2026-11-11 against the actual UTP/CGS/
     * 001-A1 and UTP/CGS/001-A2 forms: every other Section C field was
     * already captured somewhere in this schema or the student's own
     * account; this was the one genuinely missing.
     */
    public function up(): void
    {
        Schema::table('gra_ga_student_profiles', function (Blueprint $table) {
            $table->string('candidature_period', 100)->nullable()->after('admission_date');
        });
    }

    public function down(): void
    {
        Schema::table('gra_ga_student_profiles', function (Blueprint $table) {
            $table->dropColumn('candidature_period');
        });
    }
};
