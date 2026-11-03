<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An examiner's publications: their titles feed the matcher, and the
 * co-author list feeds the conflict-of-interest pre-check (co-authored with
 * the candidate or the supervisor). coauthors is the list as written,
 * semicolon-separated; it is matched fuzzily, never by id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examiner_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examiner_id')->constrained('examiners')->cascadeOnDelete();
            $table->string('title', 500);
            $table->unsignedSmallInteger('year')->nullable();
            $table->text('coauthors')->nullable();
            $table->timestamps();

            $table->index(['examiner_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examiner_publications');
    }
};
