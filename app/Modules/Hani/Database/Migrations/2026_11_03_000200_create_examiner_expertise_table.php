<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per expertise keyword or phrase ("anomaly detection"), so the
 * matcher can read an examiner's expertise as discrete terms rather than
 * parse a free-text cell every time, and the dashboard can count keywords
 * across the pool. examiners.expertise keeps the text as CGS wrote it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examiner_expertise', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examiner_id')->constrained('examiners')->cascadeOnDelete();
            $table->string('keyword', 120)->index();
            $table->timestamps();

            $table->unique(['examiner_id', 'keyword']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examiner_expertise');
    }
};
