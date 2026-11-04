<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // DEMO DATA ONLY. A stand-in for the shared Softbound / student-status source until the modules are merged. Read through
        // Support\StudentStatusReader, only for demo students and only when the shared source has no record; never written by a page.
        Schema::create('candidacy_demo_student_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demo_profile_id')->unique()->constrained('demo_student_profiles')->cascadeOnDelete();
            $table->date('softbound_submitted_at')->nullable();
            $table->boolean('completed')->default(false);
            $table->boolean('inactive')->default(false); // includes deferred
            $table->string('label', 40)->default('Demo data');
            $table->timestamps();
        });

        // One row per (candidacy or demo student, expiry date, milestone): the job can run twice, or restart, and never sends twice. Keyed by the
        // expiry date, so a new expiry date starts a fresh cycle.
        Schema::create('candidacy_reminder_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidacy_id')->nullable()->constrained('study_candidacies')->cascadeOnDelete();
            $table->foreignId('demo_profile_id')->nullable()->constrained('demo_student_profiles')->cascadeOnDelete(); // demo students have no candidacy row
            $table->unsignedBigInteger('student_id')->nullable();
            $table->date('expiry_date');
            $table->unsignedTinyInteger('milestone'); // months before the expiry date: 3, 2 or 1
            $table->char('variant', 1)->nullable();   // A | B | C
            $table->string('status', 10);             // sent | skipped
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['candidacy_id', 'expiry_date', 'milestone'], 'cand_reminder_once');
            $table->unique(['demo_profile_id', 'expiry_date', 'milestone'], 'cand_reminder_once_demo');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE candidacy_reminder_log ADD CONSTRAINT cand_reminder_check CHECK (milestone IN (1,2,3) AND status IN ('sent','skipped') AND (candidacy_id IS NULL) <> (demo_profile_id IS NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('candidacy_reminder_log');
        Schema::dropIfExists('candidacy_demo_student_statuses');
    }
};
