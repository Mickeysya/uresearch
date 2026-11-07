<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per question asked of the student chatbot -- the audit
     * trail, and also how AssistantController enforces its own daily
     * cap (count today's rows for a student rather than a separate
     * counter table). Deliberately this module's own table, not a
     * shared one: see Assistant\GeminiClient's own docblock for why
     * this chatbot is a second, independent caller rather than a
     * consumer of Chloe's existing assistant infrastructure.
     */
    public function up(): void
    {
        Schema::create('haziq_assistant_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->text('question');
            $table->text('answer')->nullable();
            $table->string('outcome', 20); // ok | unavailable
            $table->string('error_code', 40)->nullable();
            $table->timestamps();

            $table->index(['student_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('haziq_assistant_logs');
    }
};
