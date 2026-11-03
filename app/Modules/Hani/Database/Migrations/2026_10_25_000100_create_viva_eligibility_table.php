<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The student's self-declared checklist before the Notice of Thesis
        // Submission. One row per student, keyed on the student rather than
        // an application, because it is filled in BEFORE the notice creates
        // the viva application.
        //
        // Programme level is deliberately not stored: it is read from
        // users.programme every time (VivaChecklist::levelFor), so a
        // corrected programme never leaves a stale MSc/PhD snapshot here.
        Schema::create('viva_eligibility', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained('users')->cascadeOnDelete();

            // One column per check in VivaChecklist::items(). rcs_passed is
            // PhD only; an MSc row simply leaves it false.
            $table->boolean('research_methodology_passed')->default(false);
            $table->boolean('publication_accepted')->default(false);
            $table->boolean('rcs_passed')->default(false);
            $table->boolean('registered_current_semester')->default(false);
            $table->boolean('no_outstanding_fees')->default(false);
            $table->boolean('within_candidacy_period')->default(false);

            // Room for the optional "verified by AE" step later. Nullable and
            // unused for now; nothing reads them yet.
            $table->foreignId('verified_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viva_eligibility');
    }
};
