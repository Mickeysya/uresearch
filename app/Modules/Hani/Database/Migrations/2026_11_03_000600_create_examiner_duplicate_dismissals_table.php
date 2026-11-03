<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pairs the Senior Executive has checked and marked "Not the same person",
 * so duplicate detection stops flagging them. Stored lowest id first, so the
 * unique key holds the pair whichever way round it was dismissed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examiner_duplicate_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examiner_a_id')->constrained('examiners')->cascadeOnDelete();
            $table->foreignId('examiner_b_id')->constrained('examiners')->cascadeOnDelete();
            $table->foreignId('dismissed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['examiner_a_id', 'examiner_b_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examiner_duplicate_dismissals');
    }
};
