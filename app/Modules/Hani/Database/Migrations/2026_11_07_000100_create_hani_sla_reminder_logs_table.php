<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What `hani:sla-reminders` has already sent: written BEFORE sending, and the
 * unique key makes a second run skip it (the rpd:remind pattern, as
 * viva_reminder_logs). entered_at is part of the key, so a list sent back to
 * a stage it has been at before starts a fresh clock there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hani_sla_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('stage_key', 60);
            $table->timestamp('entered_at')->comment('when the application reached this stage');
            $table->unsignedTinyInteger('threshold')->comment('1 = first threshold, 2 = second');
            $table->unsignedSmallInteger('days')->comment('days waited when it was logged');
            $table->boolean('sent')->default(true)->comment('false: covered by a higher threshold logged on the same run');
            $table->timestamp('sent_at');

            $table->unique(['application_id', 'stage_key', 'entered_at', 'threshold'], 'hani_sla_reminder_logs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hani_sla_reminder_logs');
    }
};
