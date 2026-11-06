<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per student, not per application — accepting the GRA Scheme
     * Terms and Conditions once covers every GRA application and extension
     * that student ever submits afterwards, the same way gra_ga_student_
     * profiles is keyed by student rather than by application.
     *
     * A separate table rather than a column on gra_ga_student_profiles on
     * purpose: a first-time applicant has no profile row yet at the point
     * they must accept (GraApplicationController::create() gates on this
     * before a profile exists), so the acceptance has to be able to outlive
     * — and predate — the profile itself.
     */
    public function up(): void
    {
        Schema::create('gra_terms_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->timestamp('accepted_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gra_terms_acceptances');
    }
};
