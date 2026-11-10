<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The student now logs their own TA hours (date, time in, time out,
     * activity, course lecturer) and the course lecturer approves each row.
     * Before this, staff typed the hours in for the student.
     *
     * `state` is this table's own draft -> pending -> approved | rejected,
     * not an application status: TA hours are not an application and never
     * go through WorkflowEngine.
     *
     * Rows recorded by staff under the old screen are kept as approved, since
     * they were already being counted.
     */
    public function up(): void
    {
        Schema::table('ta_hour_logs', function (Blueprint $table) {
            $table->string('time_in', 5)->nullable()->after('activity_date');
            $table->string('time_out', 5)->nullable()->after('time_in');
            $table->foreignId('course_lecturer_id')->nullable()->after('hours')->constrained('users')->nullOnDelete();
            $table->string('state', 20)->default('draft')->after('course_lecturer_id')->index();
            $table->timestamp('submitted_at')->nullable()->after('state');
            $table->timestamp('decided_at')->nullable()->after('submitted_at');
            $table->text('decision_reason')->nullable()->after('decided_at');
        });

        DB::table('ta_hour_logs')->update(['state' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('ta_hour_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('course_lecturer_id');
            $table->dropColumn(['time_in', 'time_out', 'state', 'submitted_at', 'decided_at', 'decision_reason']);
        });
    }
};
