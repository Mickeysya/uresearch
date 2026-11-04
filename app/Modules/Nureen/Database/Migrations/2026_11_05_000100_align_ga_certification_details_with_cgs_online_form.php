<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The GA/GRA Certification form, aligned with CGS's official online form.
 *
 * Section 1 (student details) takes Matric ID, Name, Department and
 * Programme from the student's own profile -- not stored here. These are
 * what the profile doesn't hold:
 *   study_level            coursework | research
 *   level_of_study         master | phd
 *   mode_of_study          full_time | full_time_odl | part_time
 *   identity_number        MyKad/Passport number, ENCRYPTED (the model's
 *                          'encrypted' cast) -- text, because ciphertext is
 *                          long; never shown in full except to CGS staff
 *   personal_email         the student's personal email (section 4)
 *   declaration_accepted_at when the student ticked the declaration
 *                          (section 5); required on submit, not on a draft
 *
 * All nullable: a saved draft may not have them yet; submit requires them.
 * The option lists live in config('nureen.ga_certification.options').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ga_certification_details', function (Blueprint $table) {
            $table->string('study_level', 20)->nullable()->after('appointment_type');
            $table->string('level_of_study', 20)->nullable()->after('study_level');
            $table->string('mode_of_study', 20)->nullable()->after('level_of_study');
            $table->text('identity_number')->nullable()->after('mode_of_study')->comment('encrypted');
            $table->string('personal_email', 150)->nullable()->after('identity_number');
            $table->timestamp('declaration_accepted_at')->nullable()->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('ga_certification_details', function (Blueprint $table) {
            $table->dropColumn(['study_level', 'level_of_study', 'mode_of_study', 'identity_number', 'personal_email', 'declaration_accepted_at']);
        });
    }
};
