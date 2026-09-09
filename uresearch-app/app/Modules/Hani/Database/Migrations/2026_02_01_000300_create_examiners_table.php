<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The examiner pool. Absent from the legacy schema entirely, which is
        // why the 90-day cooling-off rule could not be enforced.
        Schema::create('examiners', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('email', 150)->unique();
            $table->string('department', 150)->index();
            $table->string('faculty', 100)->nullable()->index()->comment('FOE, FSMC, ...');
            $table->enum('type', ['internal', 'external']);

            // false = the "Unavailable" state: retired, resigned, deceased.
            $table->boolean('is_active')->default(true)->index();

            // Drives the "On Gap" state; see Examiner::state().
            $table->date('last_examination_date')->nullable()->index();

            // Drives the "Assigned" state.
            $table->date('assigned_until')->nullable()->index();

            $table->timestamps();
        });

        Schema::create('examiner_nominations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            // Both main and backup must pass the eligibility check at the
            // moment of nomination.
            $table->foreignId('main_examiner_id')->constrained('examiners')->restrictOnDelete();
            $table->foreignId('backup_examiner_id')->nullable()->constrained('examiners')->restrictOnDelete();

            $table->string('thesis_title', 255);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examiner_nominations');
        Schema::dropIfExists('examiners');
    }
};
