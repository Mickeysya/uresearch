<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An RPD extension appeal: Supervisor -> Chair -> Non-Exec CGS -> Dean.
 *
 * `new_deadline` stays null until the Dean approves. It is written by
 * RpdAppealController::decide() at the moment of approval and holds the exact
 * date the masterlist was moved to -- the record of what was granted, kept
 * separately from the candidacy so a later extension cannot overwrite the
 * history of this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rpd_appeal_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            // Which clock this appeal moves. Not derived from the student at
            // decision time: a student could in principle be re-enrolled, and
            // the appeal must move the candidacy it was filed against.
            $table->foreignId('candidacy_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('requested_months');
            $table->text('justification');

            // Snapshot of the deadline when the appeal was filed, so the
            // approver sees what the student was actually looking at.
            $table->date('deadline_at_filing');

            // Written only on the Dean's approval.
            $table->date('new_deadline')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rpd_appeal_details');
    }
};
