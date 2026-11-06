<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supervisor/Senior Director flagging CGS's recommended allowance,
     * with a reason — deliberately a separate table from
     * application_correction_requests, not a variant of it. That table's
     * whole design is "the student sees this and acts on it"; this one is
     * the opposite on purpose — internal feedback from one approver to
     * CGS about a figure CGS set, which the student is never meant to see.
     * Mixing the two would mean every future read of
     * application_correction_requests has to remember to filter this kind
     * out for a student-facing screen; a separate table makes "never shown
     * to the student" true by construction instead of by discipline.
     */
    public function up(): void
    {
        Schema::create('allowance_recommendation_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowance_recommendation_notes');
    }
};
