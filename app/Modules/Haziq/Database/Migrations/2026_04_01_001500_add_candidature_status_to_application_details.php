<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks whether THIS GRA/GA appointment is still active, separately from
     * stage_gate_termination_triggers (which only records that a non-recoverable
     * gate's grace period lapsed, for whichever future module ends up owning
     * actual candidature/enrolment status). This column is narrower and
     * entirely Haziq's own: it says whether this appointment's funding has
     * lapsed, which is what gates reapplication
     * (GraApplicationController::activeNewApplication()) and what the Stage
     * Gate dashboard's Active/Terminated counts read. The student's
     * application_id row is never deleted — terminating only flips this.
     */
    public function up(): void
    {
        Schema::table('gra_application_details', function (Blueprint $table) {
            $table->string('candidature_status', 20)->default('active')->after('application_type');
        });

        Schema::table('ga_application_details', function (Blueprint $table) {
            $table->string('candidature_status', 20)->default('active')->after('application_id');
        });
    }

    public function down(): void
    {
        Schema::table('gra_application_details', function (Blueprint $table) {
            $table->dropColumn('candidature_status');
        });

        Schema::table('ga_application_details', function (Blueprint $table) {
            $table->dropColumn('candidature_status');
        });
    }
};
