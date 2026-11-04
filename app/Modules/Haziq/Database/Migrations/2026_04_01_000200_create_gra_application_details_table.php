<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per GRA Application row (module_type = 'gra_application').
     *
     * Extension reuses this same workflow and the same detail table rather
     * than being a separate module — application_type + parent_application_id
     * say whether a row is the original appointment or a later extension of
     * it, the way GA/GRA Certification already threads gra_application_id
     * through re-submissions. This is also why the collision with Nureen's
     * ga_extension never arises: that module is GA-only, this one is
     * GRA-only, and neither shares a module_type or a table with the other.
     */
    public function up(): void
    {
        Schema::create('gra_application_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained('gra_ga_student_profiles')->cascadeOnDelete();

            $table->string('application_type', 20)->default('new'); // VARCHAR, not ENUM -- see Core\Support\Role
            // Self-referential to applications, not to this table, so the
            // whole chain (original -> extension -> extension) reads through
            // one relation regardless of which row started it.
            $table->foreignId('parent_application_id')->nullable()->constrained('applications')->nullOnDelete();

            $table->string('project_leader_name', 150);
            $table->string('project_leader_contact', 30)->nullable();
            $table->string('project_title', 255);
            $table->string('funding_source', 100);
            $table->string('grant_name', 150)->nullable();
            $table->string('cost_centre', 50)->nullable();
            $table->string('centre_name', 150)->nullable();

            $table->date('expected_reporting_date')->nullable();
            $table->date('service_start_date');
            $table->date('service_end_date');
            $table->decimal('proposed_allowance', 10, 2);

            // Extension-only fields; null on a 'new' row.
            $table->decimal('previous_allowance', 10, 2)->nullable();
            $table->text('extension_reason')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gra_application_details');
    }
};
