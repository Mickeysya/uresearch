<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A student appealing a Stage Gate termination on a specific GRA/GA
     * appointment, reinstating that SAME application — not a new
     * submission at all (an earlier, now-removed mechanism required
     * resubmitting the whole GRA form with an extra letter; confirmed
     * explicitly 2026-10-15 that this dedicated action replaces it
     * entirely, see CandidatureAppealController). The letter itself is an
     * ordinary ApplicationDocument (doc_type 'Candidature Appeal Letter')
     * on that same application, via DocumentStore — this table is just
     * the decision record: who appealed, whether it's still pending, and
     * who (only Senior Director may) decided it and why. One application
     * can carry more than one row over time — a rejected appeal doesn't
     * get overwritten by a later attempt, both stay as history.
     */
    public function up(): void
    {
        Schema::create('candidature_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            $table->string('status', 20)->default('pending'); // pending|approved|rejected

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidature_appeals');
    }
};
