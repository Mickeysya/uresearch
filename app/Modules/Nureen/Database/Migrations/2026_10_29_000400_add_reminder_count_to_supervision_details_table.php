<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supervision_details', function (Blueprint $table) {
            // How many stall reminders have gone out at the CURRENT stage
            // since it last moved -- once this reaches
            // config('nureen.reminders.supervision.escalate_after_reminders'),
            // RemindStalledSupervisionRequests also notifies CGS Staff.
            // Paired with reminded_stage_key: a count from the supervisor's
            // stage must not carry over and immediately read as "escalated"
            // once the application has since moved to cgs_verify, so the
            // command resets both whenever the stage it is reminding about
            // does not match what is stored here.
            $table->unsignedInteger('reminder_count')->default(0)->after('reminded_at');
            $table->string('reminded_stage_key', 50)->nullable()->after('reminder_count');
        });
    }

    public function down(): void
    {
        Schema::table('supervision_details', function (Blueprint $table) {
            $table->dropColumn('reminder_count');
        });
    }
};
