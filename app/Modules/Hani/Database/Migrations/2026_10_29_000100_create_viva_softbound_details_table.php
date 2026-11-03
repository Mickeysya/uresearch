<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per softbound attempt (CGS Online Form #019). A returned
        // attempt stays as it was and the student files a new one, so every
        // attempt keeps its own documents and audit trail.
        Schema::create('viva_softbound_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            // The Notice of Thesis Submission this softbound belongs to.
            $table->foreignId('viva_application_id')->constrained('applications')->cascadeOnDelete();

            // Declared by the student, checked by the AE against the uploaded
            // similarity report and thesis.
            $table->decimal('similarity_percent', 5, 2);
            $table->unsignedInteger('word_count');
            $table->boolean('abstract_bilingual')->default(false);
            $table->boolean('copies_certified')->default(false);
            $table->boolean('before_candidacy_end')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viva_softbound_details');
    }
};
