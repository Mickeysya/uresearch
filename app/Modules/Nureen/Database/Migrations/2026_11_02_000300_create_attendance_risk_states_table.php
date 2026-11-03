<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per student: the outcome of the latest risk check,
        // including the end-of-semester projection. CGS-facing only -- see
        // AttendanceController::atRisk(). attendance_records.at_risk keeps
        // its old meaning (the original rule, per period), so nothing a
        // student sees changes because of the projection.
        Schema::create('attendance_risk_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('at_risk')->default(false);
            // current | projected | both -- null when not at risk.
            $table->string('reason', 20)->nullable();
            // Cumulative over the semester's periods, excused sessions included.
            $table->decimal('current_percentage', 5, 2)->nullable();
            $table->decimal('projected_percentage', 5, 2)->nullable();
            // Percentage points per period.
            $table->decimal('trend_slope', 6, 2)->nullable();
            $table->unsignedSmallInteger('periods_used')->default(0);
            // Null when there is a projection; otherwise why not:
            // too_few_periods | inconsistent_periods (mixed period lengths --
            // see AttendanceProjector::unavailableReason()).
            $table->string('projection_status', 30)->nullable();
            $table->date('latest_period_end')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_risk_states');
    }
};
