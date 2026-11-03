<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What `examiners:sweep` has already sent, so a daily command reminds once
 * per window and never twice. The same guard as Norhanis' rpd_reminder_logs:
 * the row is written BEFORE the reminder goes out, and the unique key on
 * (nomination_id, kind, sequence) is what makes a second run -- or two
 * overlapping runs -- skip it.
 *
 * sequence is the reminder's window: 0 for the first, then one more for every
 * config('hani.sweep.remind_every_days') days the evaluation stays open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examiner_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nomination_id')->constrained('examiner_nominations')->cascadeOnDelete();
            $table->string('kind', 40)->comment('evaluation_overdue');
            $table->unsignedSmallInteger('sequence');
            $table->timestamp('sent_at');

            $table->unique(['nomination_id', 'kind', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examiner_reminder_logs');
    }
};
