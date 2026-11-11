<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The internal CGS-only allowance note mechanism this table backed is
     * gone: the student now proposes the allowance figure directly on the
     * form (both GRA and GA), and any approver who disagrees uses the same
     * Request Correction flow as any other field, visible to the student
     * like every other correction — confirmed explicitly 2026-11-11.
     */
    public function up(): void
    {
        Schema::dropIfExists('allowance_recommendation_notes');
    }

    public function down(): void
    {
        Schema::create('allowance_recommendation_notes', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->timestamps();
        });
    }
};
