<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hardbound_appeal_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            // The hardbound submission being appealed. An appeal cannot exist
            // without one, so this is the one column that makes this module
            // depend on the other.
            $table->foreignId('hardbound_application_id')
                ->constrained('applications')->cascadeOnDelete();

            $table->text('justification');

            // Written by the Non-Executive CGS when compiling the Dean PFR
            // report that the Senior Executive rules on.
            $table->text('pfr_recommendation')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hardbound_appeal_details');
    }
};
