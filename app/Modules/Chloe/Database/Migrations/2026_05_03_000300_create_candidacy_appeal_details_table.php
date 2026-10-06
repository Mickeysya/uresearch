<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidacy_appeal_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('study_candidacy_id')->constrained()->cascadeOnDelete();

            // Chosen at submission per the brief ("Student selects their
            // Supervisor"), not read off users.supervisor_id — a student may
            // need to route to someone other than their assigned supervisor,
            // and this table is the only place that choice is recorded.
            $table->foreignId('supervisor_id')->constrained('users')->cascadeOnDelete();

            $table->text('reason');

            // Requested and (once Dean-approved) granted extension length.
            // The 12-month cumulative cap is enforced in code against
            // study_candidacies.cumulative_extension_months, not here.
            $table->unsignedTinyInteger('requested_extension_months');
            $table->date('new_expiry_date')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidacy_appeal_details');
    }
};
