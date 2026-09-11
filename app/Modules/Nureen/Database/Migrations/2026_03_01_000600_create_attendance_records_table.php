<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            // The week/period a CSV upload row covers. One row per student
            // per period; re-uploading the same period updates it in place
            // rather than duplicating it.
            $table->date('period_end');
            $table->unsignedSmallInteger('sessions_attended');
            $table->unsignedSmallInteger('sessions_total');

            // Derived from the two counts above, not taken from the CSV --
            // see AttendanceRecord::booted(). Stored so the at-risk dashboard
            // and trend rule can query it directly instead of recomputing
            // from every historical row on every page load.
            $table->decimal('percentage', 5, 2);
            $table->boolean('at_risk')->default(false)->comment('see App\Modules\Nureen\Support\AttendanceRiskEvaluator');

            $table->timestamps();

            $table->unique(['student_id', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
