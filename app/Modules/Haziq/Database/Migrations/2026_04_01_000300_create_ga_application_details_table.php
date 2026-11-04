<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per GA Application (module_type = 'ga_application').
     *
     * GA follows the same shape as GRA (same profile table, same document
     * conventions) but has no extension path here — GA extension stays
     * Nureen's ga_extension entirely, so this table carries no
     * application_type/parent_application_id.
     */
    public function up(): void
    {
        Schema::create('ga_application_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained('gra_ga_student_profiles')->cascadeOnDelete();

            $table->string('department_unit', 150)->nullable();
            $table->string('purpose', 255)->nullable();
            $table->unsignedTinyInteger('proposed_hours_per_week')->nullable();
            $table->date('service_start_date');
            $table->date('service_end_date');
            $table->decimal('proposed_allowance', 10, 2);

            $table->text('eligibility_notes')->nullable();

            // Set by the Research Centre stage; see GaApplicationWorkflow.
            $table->string('interview_result', 20)->default('pending'); // VARCHAR, not ENUM -- see Core\Support\Role
            $table->dateTime('interview_date')->nullable();
            $table->text('interview_notes')->nullable();
            $table->foreignId('interviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ga_application_details');
    }
};
