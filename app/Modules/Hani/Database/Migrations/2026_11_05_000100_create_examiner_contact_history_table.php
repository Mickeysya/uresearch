<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every change to an examiner's email, and every report or confirmation of
 * it, in order. examiners.email holds only the current address; this is
 * what remembers the old one, who changed it and why.
 *
 * source: manual (the examiner form) | email_form (Non-Exec's email-only
 *         edit) | import | proposal (confirmed from a nomination) |
 *         reported (not working) | confirmed (still valid)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examiner_contact_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examiner_id')->constrained('examiners')->cascadeOnDelete();
            $table->string('old_email', 150)->nullable();
            $table->string('new_email', 150)->nullable();
            $table->string('source', 20);
            $table->text('reason')->nullable();
            $table->foreignId('changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['examiner_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examiner_contact_history');
    }
};
