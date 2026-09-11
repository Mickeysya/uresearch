<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publication_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            $table->string('conference_or_journal_name', 255);
            $table->string('publication_title', 255);
            $table->date('event_date');
            $table->string('location', 150)->nullable();
            $table->decimal('funding_amount_requested', 10, 2)->default(0);

            // Drives whether the student must attach a Letter of Undertaking
            // (a document upload via DocumentStore, not a column here — see
            // PublicationController::store()).
            $table->boolean('requires_letter_of_undertaking')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_details');
    }
};
