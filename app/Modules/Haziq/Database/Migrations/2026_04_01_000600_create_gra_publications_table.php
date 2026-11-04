<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Section F of the requisition — repeatable, keyed by profile.
     *
     * stage_gate_id is nullable and set only when a publication is also the
     * evidence for a Stage Gate 2/3 requirement, so the same upload serves
     * both the application's publication list and the Stage Gate's
     * completion evidence — never duplicated between them.
     */
    public function up(): void
    {
        Schema::create('gra_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('gra_ga_student_profiles')->cascadeOnDelete();
            $table->foreignId('stage_gate_id')->nullable()->constrained('stage_gates')->nullOnDelete();

            $table->string('publication_type', 50);
            $table->text('authors');
            $table->string('publication_year', 4)->nullable();
            $table->string('title', 255);
            $table->string('journal_or_conference', 255)->nullable();
            $table->string('volume_chapter', 100)->nullable();
            $table->string('page_number', 50)->nullable();
            $table->string('doi', 150)->nullable();
            $table->string('issn', 50)->nullable();
            $table->string('database_quartile', 100)->nullable();
            $table->string('impact_factor', 50)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gra_publications');
    }
};
