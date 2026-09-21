<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dismissal for exceeded candidacy: Dean -> Faculty -> Registry.
 *
 * Initiated by Non-Executive CGS, who is therefore NOT a stage in the chain --
 * they create the row, the same way CGS creates a re_viva rather than
 * approving one. The student is the subject, not the submitter, which is the
 * one place in this project where applications.student_id names someone who
 * did not file the application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rpd_dismissal_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidacy_id')->constrained()->cascadeOnDelete();

            // Who at CGS opened the case. Not an approver -- an author.
            $table->foreignId('initiated_by')->constrained('users')->cascadeOnDelete();

            $table->date('deadline_missed_on');
            $table->text('grounds');

            // Stamped by the Registry when the termination email goes out, so
            // "notified" is a fact on the record rather than an assumption
            // drawn from the application being approved.
            $table->timestamp('terminated_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rpd_dismissal_details');
    }
};
