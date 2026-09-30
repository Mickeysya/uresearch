<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The examiner's signature on the Confirmation of Correction to Thesis.
 *
 * Separate from `hardbound_signatures`, which is keyed on `user_id`: an
 * examiner has no account and never will, so there is no user to key on.
 * They sign through an emailed link instead, and what identifies them is
 * the appointment they were appointed under.
 *
 * One row per application: the form has a single
 * "INTERNAL / EXTERNAL EXAMINER" block, so the first examiner to sign fills
 * it and the rest are told it is done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hardbound_examiner_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete();

            // Which appointed examiner signed. Their name and email are read
            // back through this, and that row is already a snapshot taken
            // when the panel was appointed.
            $table->foreignId('appointment_examiner_id')
                ->constrained('appointment_examiners')->cascadeOnDelete();

            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->timestamp('signed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hardbound_examiner_signatures');
    }
};
