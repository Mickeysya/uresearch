<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Section D of the requisition — repeatable, keyed by profile so it is entered once and outlives any one application. */
    public function up(): void
    {
        Schema::create('gra_academic_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('gra_ga_student_profiles')->cascadeOnDelete();

            $table->string('level_of_study', 100);
            $table->string('institution', 150);
            $table->string('programme', 150);
            $table->string('result', 100)->nullable();
            $table->string('year_from', 4)->nullable();
            $table->string('year_to', 4)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gra_academic_qualifications');
    }
};
