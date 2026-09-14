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

            $table->string('type_of_request', 30); // publication_conference | publication_journal
            $table->string('title_of_paper', 255);
            $table->string('title_of_conference_journal', 255);
            $table->string('organizer_publisher', 255);

            // The fee itself and the accounting code it's charged against are
            // two different things — kept as separate columns rather than one
            // conflated field so the fee stays a real currency amount and the
            // cost centre stays free-text, never summed as money.
            $table->decimal('conference_journal_fee', 10, 2)->default(0);
            $table->string('currency_type', 10)->default('MYR');
            $table->string('cost_centre', 100);

            // Drives whether Senior Director CGS's final approval should
            // trigger generating a Letter of Undertaking PDF (via Dompdf, a
            // later pass) — not a document upload, just the request flag.
            $table->boolean('wants_letter_of_undertaking')->default(false);

            // The conference/publication window itself, not a trip — Travel
            // is a separate application through the Travel module.
            $table->date('conference_start_date');
            $table->date('conference_end_date');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_details');
    }
};
