<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rpd_dismissal_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidacy_id')->constrained('candidacies')->cascadeOnDelete();

            // Why Non-Exec CGS is initiating dismissal for this candidacy --
            // there is no student-facing form for this module (see
            // RpdDismissalWorkflow::createRoute()), so this is written by CGS
            // staff from the overdue-candidacies list, not by the student.
            $table->text('reason');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rpd_dismissal_details');
    }
};
