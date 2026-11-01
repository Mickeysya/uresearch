<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_appeal_details', function (Blueprint $table) {
            // How many of the chosen period's MISSED sessions the student
            // asks CGS to excuse. Null on a general appeal that names no
            // period (and on every appeal filed before this column existed).
            $table->unsignedSmallInteger('sessions_requested')->nullable()->after('attendance_record_id');

            // How many CGS actually excused on approval -- never more than
            // requested, and possibly fewer. Null until decided (and on a
            // rejection). Both numbers are kept so the record shows what was
            // asked for as well as what was granted. See
            // AttendanceAppealController::decide().
            $table->unsignedSmallInteger('sessions_approved')->nullable()->after('sessions_requested');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_appeal_details', function (Blueprint $table) {
            $table->dropColumn(['sessions_requested', 'sessions_approved']);
        });
    }
};
