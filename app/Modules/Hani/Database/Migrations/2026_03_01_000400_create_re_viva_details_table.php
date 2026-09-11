<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per re-viva cycle. A student who loops back at outcome
        // level 4 gets a second Application + a second row here, linked via
        // previous_cycle_id -- the loop is a chain of applications, not a
        // cycle inside the Stage graph, which WorkflowEngine cannot express.
        Schema::create('re_viva_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('cycle_number')->default(1);
            $table->foreignId('previous_cycle_id')->nullable()
                ->constrained('re_viva_details')->nullOnDelete();

            // Set once, at upload, from the re-corrected thesis submission.
            // Both deadlines are computed from it and stored, not recomputed
            // on read, so a later change to the policy window never silently
            // reaches back into an already-running cycle.
            $table->timestamp('resubmission_at');
            $table->date('correction_deadline');
            $table->date('hardbound_deadline');

            // Recorded by the Academic Executive once the stepper chain
            // (report sent -> ... -> consolidation scheduled) completes.
            // Never drives applications.status -- see ReVivaWorkflow.
            $table->unsignedTinyInteger('outcome_level')->nullable();
            $table->text('outcome_remarks')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('re_viva_details');
    }
};
