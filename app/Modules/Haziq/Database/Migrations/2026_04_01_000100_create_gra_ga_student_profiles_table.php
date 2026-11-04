<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per student, not per application.
     *
     * level_of_study picks which Stage Gate rule set applies
     * (Support\StageGateEngine::generateSchedule() reads it from here, but
     * the schedule's actual dates are anchored to the appointment's own
     * service_start_date, not admission_date — see that method). Must stay
     * identical across a student's GRA/GA application and every later
     * extension. Capturing it once here, keyed by student, is what lets an
     * extension reuse everything instead of asking the student to retype it.
     *
     * Kept out of Core's users table on purpose (Hard Rule 2): this is data
     * only this module's forms collect.
     */
    public function up(): void
    {
        Schema::create('gra_ga_student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // VARCHAR, not ENUM -- see Core\Support\Role's docblock for why
            // this codebase avoids MySQL ENUM for value sets like this one.
            $table->string('level_of_study', 10);
            $table->string('programme', 150);
            $table->string('mode_of_study', 50)->nullable();
            $table->date('admission_date');
            $table->string('thesis_title', 255)->nullable();
            $table->foreignId('co_supervisor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('ic_passport_no', 50)->nullable();
            $table->date('visa_expiry_date')->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account_no', 50)->nullable();
            $table->string('previous_sponsorship_name', 150)->nullable();
            $table->string('previous_sponsorship_duration', 100)->nullable();
            $table->string('scholarship_name', 150)->nullable();
            $table->string('scholarship_duration', 100)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gra_ga_student_profiles');
    }
};
