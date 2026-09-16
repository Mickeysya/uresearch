<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Examiner appointment nominations. The student this concerns is
        // already carried on applications.student_id -- set to the candidate
        // when the Chair files the nomination -- so it is not duplicated
        // here, the same way Hani's examiner_nominations table relies on
        // applications.student_id rather than a second student FK.
        Schema::create('appointment_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            $table->string('examiner_name', 150);
            $table->string('examiner_institution', 150);
            $table->string('examiner_email', 150);
            $table->string('examiner_expertise', 255);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_details');
    }
};
