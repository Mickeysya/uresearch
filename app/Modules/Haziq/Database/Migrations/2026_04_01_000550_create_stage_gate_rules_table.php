<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reference table, not per-student data — one row per (level, stage).
     * Support\StageGateEngine::generateSchedule() reads this to build a
     * student's actual stage_gates rows from the appointment's
     * service_start_date, so the Master/PhD month numbers below exist in
     * exactly one place rather than scattered through if/else branches.
     * Seeded here (not in a seeder)
     * because sync.sh only runs `migrate`, not `db:seed`, after the first
     * install — a rule table the engine depends on has to exist on every
     * checkout, not just a fresh one.
     *
     * Numbers are your interim report's figures, per report logic — see
     * docs/scope/haziq.md. Not the UTP form/handbook numbers (deliberately
     * set aside this round).
     */
    public function up(): void
    {
        Schema::create('stage_gate_rules', function (Blueprint $table) {
            $table->id();
            $table->string('level_of_study', 10); // VARCHAR, not ENUM -- see Core\Support\Role
            $table->unsignedTinyInteger('stage_order');
            $table->string('stage_name', 150);
            $table->text('requirement_description');
            $table->unsignedSmallInteger('deadline_month');
            $table->unsignedSmallInteger('grace_end_month');
            // Stage 1 (RPD) only. Stages 2+ trigger the candidature
            // termination hand-off instead of a recovery path.
            $table->boolean('recoverable')->default(false);
            $table->timestamps();

            $table->unique(['level_of_study', 'stage_order']);
        });

        $now = now();

        DB::table('stage_gate_rules')->insert([
            // Master — max 24 months
            ['level_of_study' => 'master', 'stage_order' => 1, 'stage_name' => 'RPD Completion', 'requirement_description' => 'Research Progress Defense (RPD) completed.', 'deadline_month' => 3, 'grace_end_month' => 6, 'recoverable' => true, 'created_at' => $now, 'updated_at' => $now],
            ['level_of_study' => 'master', 'stage_order' => 2, 'stage_name' => 'Journal Article Accepted', 'requirement_description' => 'One journal article accepted in a journal indexed by WoS/ERA/Scopus.', 'deadline_month' => 12, 'grace_end_month' => 18, 'recoverable' => false, 'created_at' => $now, 'updated_at' => $now],
            ['level_of_study' => 'master', 'stage_order' => 3, 'stage_name' => 'Journal Article Published', 'requirement_description' => 'One journal article published in a journal indexed by WoS/ERA/Scopus.', 'deadline_month' => 18, 'grace_end_month' => 24, 'recoverable' => false, 'created_at' => $now, 'updated_at' => $now],

            // PhD — max 42 months
            ['level_of_study' => 'phd', 'stage_order' => 1, 'stage_name' => 'RPD Completion', 'requirement_description' => 'Research Progress Defense (RPD) completed.', 'deadline_month' => 6, 'grace_end_month' => 9, 'recoverable' => true, 'created_at' => $now, 'updated_at' => $now],
            ['level_of_study' => 'phd', 'stage_order' => 2, 'stage_name' => 'Journal Article Accepted', 'requirement_description' => 'One journal article accepted in a journal indexed by WoS/ERA/Scopus.', 'deadline_month' => 18, 'grace_end_month' => 30, 'recoverable' => false, 'created_at' => $now, 'updated_at' => $now],
            ['level_of_study' => 'phd', 'stage_order' => 3, 'stage_name' => 'Journal Article Published (Q1/Q2)', 'requirement_description' => 'One journal article published in Q1/Q2, OR one accepted and one further article published in WoS/ERA/Scopus.', 'deadline_month' => 30, 'grace_end_month' => 36, 'recoverable' => false, 'created_at' => $now, 'updated_at' => $now],
            ['level_of_study' => 'phd', 'stage_order' => 4, 'stage_name' => 'Soft-bound Submission', 'requirement_description' => 'Soft-bound thesis submission for RCS.', 'deadline_month' => 36, 'grace_end_month' => 42, 'recoverable' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_gate_rules');
    }
};
