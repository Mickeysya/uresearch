<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The record the as-is manual process never kept — "which reminder,
        // to whom, when" — see Console\Commands\SendCandidacyReminders.
        Schema::create('study_candidacy_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_candidacy_id')->constrained()->cascadeOnDelete();

            // 1st, 2nd, 3rd... reminder in the monthly-from-3-months-out
            // sequence for this candidacy.
            $table->unsignedTinyInteger('reminder_number');

            $table->timestamp('sent_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_candidacy_reminders');
    }
};
