<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Section E of the requisition — repeatable, keyed by profile. */
    public function up(): void
    {
        Schema::create('gra_work_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('gra_ga_student_profiles')->cascadeOnDelete();

            $table->string('company_name', 150);
            $table->string('designation', 150);
            $table->text('job_description')->nullable();
            $table->date('date_from');
            $table->date('date_to')->nullable();
            $table->string('reason_for_leaving', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gra_work_experiences');
    }
};
