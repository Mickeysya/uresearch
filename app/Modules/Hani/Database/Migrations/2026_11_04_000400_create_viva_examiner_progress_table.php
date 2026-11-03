<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each main examiner's progress through the examination, recorded by the
 * Academic Executive: letter sent, accepted or rejected, thesis received,
 * report returned with its category (1-5).
 *
 * One row per examiner per main seat. After a decline the backup promoted
 * into the seat gets a new row, and the declined examiner's row stays as
 * history (superseded_at), so the current panel is always the rows with no
 * superseded_at, matching examiner_nominations' seat columns.
 *
 * The uploaded report is stored on the private disk OUTSIDE DocumentStore
 * (report_path), on purpose: DocumentStore files hang off an application the
 * student owns, and Core shows the student every such file. Students must
 * never see an examiner's report or category. See SHARED_CHANGES.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viva_examiner_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('viva_application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('nomination_id')->constrained('examiner_nominations')->cascadeOnDelete();
            $table->string('seat', 30)->comment('internal_main_id | external_main_id');
            $table->foreignId('examiner_id')->constrained('examiners')->cascadeOnDelete();
            $table->timestamp('superseded_at')->nullable()->comment('declined and replaced; kept as history');

            $table->date('letter_sent_at')->nullable();
            $table->string('letter_source', 20)->nullable()->comment('dispatch (Jason\'s module) | manual');

            $table->string('acknowledgement', 10)->nullable()->comment('accepted | rejected');
            $table->date('acknowledged_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->date('thesis_received_at')->nullable();
            $table->date('report_received_at')->nullable();
            $table->unsignedTinyInteger('category')->nullable()->comment('1-5, see VivaExaminerProgress::CATEGORIES');
            $table->json('ratings')->nullable()->comment('optional: {criteria: {key: 1-5}, sections: {key: 1-5}}');

            $table->string('report_path')->nullable();
            $table->string('report_original_name')->nullable();

            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['nomination_id', 'seat', 'examiner_id']);
            $table->index(['viva_application_id', 'superseded_at']);
        });

        // What viva:reminders has already sent: written BEFORE sending, and
        // the unique key makes a second run skip it (the rpd:remind pattern).
        Schema::create('viva_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('viva_application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('reference', 64)->default('')->comment('what the reminder is about within the viva, e.g. a progress row');
            $table->timestamp('sent_at');

            $table->unique(['viva_application_id', 'kind', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viva_reminder_logs');
        Schema::dropIfExists('viva_examiner_progress');
    }
};
