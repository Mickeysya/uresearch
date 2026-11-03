<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An email for an examiner, proposed by the supervisor who nominated them
 * because the pool had none (or a dead one). Encik Syahmi used to find these
 * by searching university websites; the supervisor usually knows already.
 *
 * Never written to examiners.email directly: Non-Executive or Senior
 * Executive CGS confirms it with one click, which goes through
 * ExaminerContact and the contact history.
 *
 * The optional CV is kept on the private disk under a random name, OUTSIDE
 * DocumentStore, like the viva examiner reports: an ApplicationDocument on
 * the nomination would be visible to the candidate (ApplicationDocument::
 * visibleTo), and students never see who their examiners are. See
 * SHARED_CHANGES.md item 12 (a staff-only flag for DocumentStore).
 *
 * status: pending | confirmed | dismissed | superseded
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examiner_email_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examiner_id')->constrained('examiners')->cascadeOnDelete();
            $table->foreignId('nomination_id')->nullable()->constrained('examiner_nominations')->nullOnDelete();
            $table->string('proposed_email', 150);
            $table->foreignId('proposed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cv_path')->nullable();
            $table->string('cv_original_name')->nullable();
            $table->string('status', 12)->default('pending');
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'examiner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examiner_email_proposals');
    }
};
