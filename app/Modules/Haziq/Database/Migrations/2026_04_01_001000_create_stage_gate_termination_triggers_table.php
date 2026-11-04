<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raised by the daily job when a Stage 2+ gate's grace period expires
     * without completion. This module does NOT own candidature/enrolment
     * status — that belongs to whichever module ends up built for it
     * (rpd_dismissal or candidacy_dismissal, both still "planned" in
     * docs/module-keys.md). This table is the durable, addressable record of
     * "this student's Stage Gate failed and needs a candidature termination
     * review" that a future dismissal module can consume, so the Stage Gate
     * engine only ever triggers, never decides.
     */
    public function up(): void
    {
        Schema::create('stage_gate_termination_triggers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('stage_gate_id')->constrained('stage_gates')->cascadeOnDelete();

            $table->text('reason');
            $table->timestamp('triggered_at');

            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_gate_termination_triggers');
    }
};
