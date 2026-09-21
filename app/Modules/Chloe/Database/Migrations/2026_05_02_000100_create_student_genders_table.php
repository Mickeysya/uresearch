<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // `users` carries no gender column (Hard Rule 2 forbids adding one),
        // so room-eligibility filtering needs somewhere else to read a
        // student's gender from. This is that somewhere — owned entirely by
        // this module, keyed by student, editable by the student themselves
        // and overridable by CGS. See WorkstationController::genderFor().
        Schema::create('student_genders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('gender', 10);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_genders');
    }
};
