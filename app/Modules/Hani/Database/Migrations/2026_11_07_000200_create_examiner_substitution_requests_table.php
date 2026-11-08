<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Ask department to substitute", from Conflict Detection: which seat on which
 * pending list, why, and how it ended. Staff only: the candidate must never
 * learn who their examiners are, so the reason lives here and not in the
 * remarks WorkflowEngine::returnTo() sends them. Kept after it is resolved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examiner_substitution_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nomination_id')->constrained('examiner_nominations')->cascadeOnDelete();
            $table->string('seat', 30)->comment('internal_main_id | internal_backup_id | external_main_id | external_backup_id');
            $table->foreignId('examiner_id')->nullable()->constrained('examiners')->nullOnDelete()->comment('who holds the seat now');
            $table->string('requested_at_stage', 30)->comment('cgs_compile | senior_director | dean');
            $table->text('reason');
            $table->string('status', 12)->default('open')->comment('open | done | closed');
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('replacement_examiner_id')->nullable()->constrained('examiners')->nullOnDelete();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['nomination_id', 'seat', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examiner_substitution_requests');
    }
};
