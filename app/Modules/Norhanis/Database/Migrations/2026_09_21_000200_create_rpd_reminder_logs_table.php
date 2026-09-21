<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rpd_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidacy_id')->constrained('candidacies')->cascadeOnDelete();

            // 3, 2 or 1 -- months remaining before the candidacy's deadline.
            $table->unsignedTinyInteger('month_mark');

            $table->timestamp('sent_at');
            $table->timestamps();

            // Enforced at the DB level, not just in RemindRpdCandidates --
            // a reminder must never fire twice for the same candidacy/mark,
            // even if the command is ever run twice in the same window.
            $table->unique(['candidacy_id', 'month_mark']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rpd_reminder_logs');
    }
};
