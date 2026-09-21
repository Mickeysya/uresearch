<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the reminder command has already sent.
 *
 * The scope asks for emails at 3, 2 and 1 months before the RPD deadline. The
 * command runs daily, so without a record it would send the 3-month reminder
 * every day for a month. The unique constraint on (candidacy_id, milestone) is
 * what makes "fires once" a database guarantee rather than a careful query --
 * a duplicate insert fails loudly instead of quietly mailing a student again.
 *
 * milestone is the month mark (3, 2 or 1), not a date: if an appeal moves the
 * deadline, the new deadline's milestones are genuinely different reminders and
 * the rows are cleared by RpdAppealController when it grants the extension.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rpd_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidacy_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('milestone')->comment('months before deadline: 3, 2 or 1');

            // The deadline this reminder was measured against, kept so the log
            // still makes sense after an extension moves the deadline.
            $table->date('deadline_at_send');

            $table->timestamp('sent_at');

            $table->unique(['candidacy_id', 'milestone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rpd_reminder_logs');
    }
};
