<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chloe-owned, not Core: the team's TODO.md flags this as the same
        // shape Norhanis' (unbuilt) RPD module will eventually need, and
        // says to settle a shared table as a team decision before either
        // side commits to one. Building it here, scoped to study candidacy
        // only, keeps that decision open rather than presuming an answer
        // for both modules — see app/Modules/Chloe/README.md.
        Schema::create('study_candidacies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained('users')->cascadeOnDelete();

            $table->date('programme_start_date');
            $table->date('candidacy_expiry_date')->index();

            // active | softbound | dismissed | inactive | completed
            $table->string('status', 20)->default('active')->index();

            // Toward the 12-month cumulative appeal cap — see
            // CandidacyAppealWorkflow and StudyCandidacy::remainingAppealMonths().
            $table->unsignedTinyInteger('cumulative_extension_months')->default(0);

            // Set the moment a Dean rejects an appeal — a second block on
            // future appeals independent of the 12-month cap.
            $table->timestamp('last_dean_rejection_at')->nullable();

            $table->timestamp('dismissed_at')->nullable();
            $table->foreignId('dismissed_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_candidacies');
    }
};
