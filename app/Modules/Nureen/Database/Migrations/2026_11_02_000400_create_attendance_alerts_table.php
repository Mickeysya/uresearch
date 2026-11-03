<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every automatic early-warning email, as sent -- what CGS sees as
        // "last emailed", and what decides whether the next risk check may
        // email the student again. See Support\AttendanceEarlyWarning.
        Schema::create('attendance_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 20);
            $table->decimal('current_percentage', 5, 2);
            // Recorded for CGS and for the re-alert rule; never put in the
            // email itself.
            $table->decimal('projected_percentage', 5, 2)->nullable();
            $table->boolean('supervisor_copied')->default(false);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['student_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_alerts');
    }
};
