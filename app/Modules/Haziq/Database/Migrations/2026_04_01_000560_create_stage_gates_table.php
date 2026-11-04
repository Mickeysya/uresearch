<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A student's actual, dated milestone schedule for one GRA/GA
     * appointment — generated once on approval from stage_gate_rules + that
     * appointment's own service_start_date (gra_application_details /
     * ga_application_details, not gra_ga_student_profiles.admission_date —
     * this clock tracks the appointment, not academic candidature), then
     * never regenerated.
     *
     * completion_status is CGS's recorded answer to "did the student meet
     * this requirement", written by hand. timeline_status and
     * allowance_status are both computed by the daily
     * `stage-gate:evaluate` job from completion_status + today's date — CGS
     * clicking late never moves deadline_date or grace_end_date. This is the
     * distinction the whole module is built around; see
     * Support\StageGateEngine.
     */
    public function up(): void
    {
        Schema::create('stage_gates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('stage_gate_rules')->nullOnDelete();

            $table->unsignedTinyInteger('stage_order');
            $table->string('stage_name', 150);
            $table->text('requirement_description');
            $table->date('deadline_date');
            $table->date('grace_end_date');

            // VARCHAR, not ENUM, throughout this table -- see Core\Support\Role.
            $table->string('completion_status', 20)->default('not_recorded');
            $table->date('completion_date')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('timeline_status', 20)->default('upcoming');
            $table->string('allowance_status', 20)->default('eligible');

            $table->text('notes')->nullable();
            // Throttles the monthly grace reminder to once per calendar month.
            $table->date('last_reminder_sent_at')->nullable();

            $table->timestamps();

            $table->unique(['application_id', 'stage_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_gates');
    }
};
