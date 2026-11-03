<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Things an import found that a person should look at, rather than act on by
 * itself. Chiefly: CGS's sheet says an examiner is holding a student
 * ("Ong Wei Ming_22000731") but there is no nomination in UResearch behind it.
 * The import records that here and does NOT invent a nomination -- a
 * nomination is a six-stage approval, not something a spreadsheet cell can
 * create. Resolved by the Senior Executive once checked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examiner_review_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examiner_id')->constrained('examiners')->cascadeOnDelete();
            $table->string('kind', 40)->comment('current_assignment | multiple_students');
            $table->string('detail', 500);
            $table->foreignId('import_batch_id')->nullable()->constrained('examiner_import_batches')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['examiner_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examiner_review_items');
    }
};
