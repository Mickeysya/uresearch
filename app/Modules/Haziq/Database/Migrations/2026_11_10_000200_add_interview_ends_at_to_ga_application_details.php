<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * interview_date is the interview's start; this is its end. Confirmed
     * explicitly 2026-11-10: "timing interview kena ada from what time
     * end at what time, habis je time tu start counting next phase of
     * task pass or fail." Marking delay (see
     * DelayByActorReport::researchCentreDelayHours()) now counts from
     * this timestamp, not the start -- Research Centre can't reasonably
     * record an outcome before the interview has actually finished.
     * Measurement only, not a hard gate on decide() -- confirmed
     * explicitly the same day.
     */
    public function up(): void
    {
        Schema::table('ga_application_details', function (Blueprint $table) {
            $table->dateTime('interview_ends_at')->nullable()->after('interview_date');
        });
    }

    public function down(): void
    {
        Schema::table('ga_application_details', function (Blueprint $table) {
            $table->dropColumn('interview_ends_at');
        });
    }
};
