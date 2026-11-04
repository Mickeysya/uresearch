<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PART 7: metadata only, never the question or answer text, never a name,
 * student ID or email -- see Models\AssistantLog's own class doc for why
 * there is deliberately no `question`/`answer` column here at all, not
 * even one left empty. Append-only: no code anywhere calls ->update() or
 * ->delete() on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('role', 30);
            $table->timestamp('occurred_at');
            $table->string('source', 20); // suggestion | typed
            $table->string('lookup', 60); // lookup name, "guidance:<topic>", or a refusal type
            $table->string('outcome', 20); // answered | asked_back | refused | unavailable | error
            $table->string('refusal_reason', 60)->nullable();
            $table->unsignedSmallInteger('result_count')->nullable();
            $table->string('answered_by', 10); // rule | model | direct -- widened to 20 and two more values added by a later migration (model_guidance | model_compose)
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_logs');
    }
};
