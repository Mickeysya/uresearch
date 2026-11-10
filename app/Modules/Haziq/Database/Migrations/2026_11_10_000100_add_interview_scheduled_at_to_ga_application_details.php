<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When Research Centre actually called scheduleInterview(), distinct
     * from interview_date (the interview's own date, which can legitimately
     * sit weeks in the future). Confirmed explicitly 2026-11-10: Research
     * Centre's stage is really two separate tasks -- set the interview,
     * then mark Pass/Fail once it has happened -- and Delay by Actor needs
     * this timestamp to measure each one on its own instead of lumping the
     * calendar gap between them in as if it were Research Centre's own
     * delay. See DelayByActorReport::rows().
     */
    public function up(): void
    {
        Schema::table('ga_application_details', function (Blueprint $table) {
            $table->dateTime('interview_scheduled_at')->nullable()->after('interview_date');
        });
    }

    public function down(): void
    {
        Schema::table('ga_application_details', function (Blueprint $table) {
            $table->dropColumn('interview_scheduled_at');
        });
    }
};
