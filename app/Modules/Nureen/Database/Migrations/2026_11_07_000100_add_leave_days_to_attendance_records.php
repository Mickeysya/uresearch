<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approved annual leave (Postgraduate Handbook 2025: up to 14 days a year
 * for full-time research students, approved by the Chair via UCampus) must
 * not count as absence. Recorded per attendance period, like the sessions
 * an approved appeal excuses, and folded in the same way -- see
 * AttendanceRecord::countedAttended() and config('nureen.attendance.leave').
 *
 * Set by CGS on the Approved Leave page or by an optional `leave_days`
 * column in the attendance sheet / sync, both through
 * AttendanceRecord::recordPeriod()/recordLeave().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->unsignedSmallInteger('leave_days')->default(0)->after('sessions_excused');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn('leave_days');
        });
    }
};
