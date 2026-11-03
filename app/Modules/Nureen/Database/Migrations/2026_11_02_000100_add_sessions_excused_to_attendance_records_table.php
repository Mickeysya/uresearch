<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            // Missed sessions in this period that an APPROVED attendance
            // appeal has excused -- see AttendanceAppealController::decide().
            // Kept apart from sessions_attended, which stays exactly what
            // UTrace reported, so a re-upload of the period never wipes an
            // excusal and the raw figure is never rewritten.
            // AttendanceRecord's saving hook folds it into `percentage`.
            $table->unsignedSmallInteger('sessions_excused')->default(0)->after('sessions_total');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn('sessions_excused');
        });
    }
};
