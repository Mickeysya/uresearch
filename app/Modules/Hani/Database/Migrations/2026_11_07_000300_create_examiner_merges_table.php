<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Same person": one row per record merged away. Nothing is deleted. The
 * duplicate stays in the pool as Unavailable, and this row is what says which
 * record it became, so an old signed link can still find its examiner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examiner_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kept_id')->constrained('examiners');
            $table->foreignId('duplicate_id')->unique()->constrained('examiners');
            $table->foreignId('merged_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('summary')->comment('what moved: seats, keywords, publications');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examiner_merges');
    }
};
