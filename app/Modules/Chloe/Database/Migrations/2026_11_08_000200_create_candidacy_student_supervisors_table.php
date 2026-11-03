<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 4 (E): the Supervisor / Programme Manager is fixed at enrolment, so the student no longer chooses one. users has no
 * column for it (and Hard Rule 2 forbids adding one), and users.supervisor_id points at a login account while the approvers
 * here are candidacy_staff_profiles, so the module keeps its own mapping: one row per student (a real login or a demo profile)
 * naming the staff profile. An appeal copies the person onto itself when it is submitted, so an appeal already submitted keeps
 * the person it was routed to if this row changes later.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('candidacy_student_supervisors')) {
            return;
        }

        Schema::create('candidacy_student_supervisors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->nullable()->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('demo_profile_id')->nullable()->unique()->constrained('demo_student_profiles')->cascadeOnDelete();
            $table->foreignId('staff_profile_id')->constrained('candidacy_staff_profiles')->cascadeOnDelete();
            // 'supervisor' for a research student, 'programme_manager' for a coursework student; the label shown is the same.
            $table->string('kind', 20)->default('supervisor');
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidacy_student_supervisors');
    }
};
