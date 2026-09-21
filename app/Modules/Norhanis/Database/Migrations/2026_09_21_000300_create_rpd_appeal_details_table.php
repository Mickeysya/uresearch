<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rpd_appeal_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidacy_id')->constrained('candidacies')->cascadeOnDelete();

            $table->text('reason');

            // Whole months of extension the student asks for, agreed with CGS:
            // 1-6, subject to the Dean's approval. On approval the candidacy's
            // deadline moves out by exactly this many months (see
            // RpdAppealController::extendCandidacy()). Months rather than a
            // date so the student never has to work out a calendar date.
            $table->unsignedTinyInteger('requested_extension_months');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rpd_appeal_details');
    }
};
