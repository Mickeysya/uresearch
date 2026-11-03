<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per viva application, created by the Notice of Thesis
        // Submission. Later steps (softbound, viva date, report, result
        // category) add their columns here rather than to `applications`.
        Schema::create('viva_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            // Snapshot at notice time, unlike viva_eligibility: the panel
            // rules and word limit that follow depend on the level the
            // notice was filed under.
            $table->string('programme_level', 3);

            $table->string('thesis_title', 255);

            // The notice must come at least 3 months before softbound
            // submission, enforced in VivaController::storeNotice().
            $table->date('expected_softbound_date');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viva_details');
    }
};
