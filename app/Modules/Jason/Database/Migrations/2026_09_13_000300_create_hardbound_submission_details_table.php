<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The student is already applications.student_id, but the thesis
        // title, programme, supervisor and matric number are captured here
        // rather than read back off `users` each time: they are what the
        // student declared on the day they submitted, and a candidate who
        // changes programme or supervisor later must not retroactively
        // change what CGS reviewed.
        Schema::create('hardbound_submission_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            $table->string('thesis_title', 500);
            $table->string('matric_no', 30);
            $table->string('programme', 150);
            $table->string('supervisor_name', 150);

            // Set when this submission replaces one CGS returned. The chain
            // has no "returned, still open" outcome -- a return is a
            // rejection at the review stage, and the student's resubmission
            // is a fresh application that points back at it, so both the
            // original decision and its remarks stay on the record.
            $table->foreignId('resubmission_of_id')->nullable()
                ->constrained('applications')->nullOnDelete();

            // The student's answer to the reviewer's comments, required when
            // resubmitting so the reviewer can see what changed.
            $table->text('response_to_comments')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hardbound_submission_details');
    }
};
