<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidacies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            // VARCHAR rather than a MySQL ENUM, same reasoning as Support\Role
            // and users.role -- a third study mode should never need an ALTER.
            $table->string('study_mode', 20)->comment('full_time or part_time');

            // Masters vs PhD. The *first* deadline duration (8/12 months) is
            // the same for both at a given study_mode, so programme doesn't
            // affect Candidacy::computeDeadline(). It does affect the
            // resubmission window after a failed attempt -- Table 5 gives four
            // different durations (3/6/6/12 months) for the four
            // programme+study_mode combinations. See
            // Candidacy::resubmissionMonthsFor().
            $table->string('programme', 20)->comment('masters or phd');

            $table->date('start_date');

            // Computed once at creation -- start_date + 8 months (full-time)
            // or 12 months (part-time), same for Masters and PhD. See
            // Candidacy::computeDeadline(). RpdAppealController is the only
            // place this ever moves after that, and only via the Dean's
            // final approval -- never edited directly.
            $table->date('deadline')->index();

            // Set the first time a candidacy is recorded as failed and again
            // on every later failure -- see Candidacy::resubmissionMonthsFor()
            // and CandidacyController::recordFailedAttempt(). Null while the
            // candidacy has never failed an attempt.
            $table->date('resubmission_deadline')->nullable()->index();

            // 1 for a candidacy that has never failed an attempt; incremented
            // by CandidacyController::recordFailedAttempt() on every failure.
            $table->unsignedTinyInteger('attempt_number')->default(1);

            // This module's own status, distinct from applications.status --
            // WorkflowEngine never touches this column. 'active' is the normal
            // state; 'appeal_pending' pauses reminders and dismissal-eligibility
            // while an RpdAppealController appeal is under review;
            // 'failed_awaiting_resubmission' is set after a failed attempt,
            // with resubmission_deadline as the new date to watch; 'dismissed'
            // is set by RpdDismissalController on Faculty's final decision.
            $table->string('status', 30)->default('active')->index();

            // Read by Haziq's future Stage Gate module to know the RPD
            // milestone is complete -- this module owns that field, per
            // TODO.md's cross-module dependency note. Left null until the RPD
            // itself is recorded as done (outside this module's built scope
            // today), so Stage Gates can safely poll it once it lands.
            $table->timestamp('rpd_completed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidacies');
    }
};
