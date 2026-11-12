<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Departmental Academic Committee's decision on an examiner nomination
 * (UAT 11 Nov 2026): the Academic Executive compiles the department's
 * nominations for the DAC meeting and records its outcome here. Only a
 * DAC-endorsed nomination goes on to CGS. One row per decision, so a list
 * sent back and endorsed again keeps both.
 *
 * The minutes are staff-only (they name examiners), so they sit on the
 * private disk like the re-viva staff documents, not in DocumentStore,
 * which shows a student every file on their application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examiner_dac_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nomination_id')->constrained('examiner_nominations')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('outcome', 20)->comment('endorsed | not_endorsed');
            $table->date('meeting_date')->nullable();
            $table->string('reference', 150)->nullable();
            $table->string('minutes_path')->nullable()->comment('private local disk');
            $table->string('minutes_name')->nullable();
            $table->text('reason')->nullable()->comment('not endorsed: why');
            $table->foreignId('recorded_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['application_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examiner_dac_records');
    }
};
