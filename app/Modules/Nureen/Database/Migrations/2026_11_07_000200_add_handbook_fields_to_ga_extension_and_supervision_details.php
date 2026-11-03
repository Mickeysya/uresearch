<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rules from the Postgraduate Handbook 2025 and the GA Terms & Conditions.
 *
 * ga_extension_details.study_type: Research or Coursework. GA is for
 * full-time research students only, and the GA Extension Form never asked
 * which; GaExtensionRules::eligibility() needs it.
 *
 * supervision_details: a GA changing supervisor needs PGFAC approval first
 * (with the supervisor's memo). Snapshotted at submission:
 *   is_change_of_supervisor  a Main Supervisor request while another is on record
 *   is_ga                    a Graduate Assistant at submission
 *   ga_status_source         'record' (GaAppointmentRecord) or 'declared' (the form)
 *   co_supervisor_approver_snapshot  'chair' or 'dean_pgr' -- who approves a
 *                            Co-Supervisor request, fixed at submission like
 *                            cgs_stage_snapshot. See SupervisionWorkflow.
 * All nullable: applications submitted before this have none of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ga_extension_details', function (Blueprint $table) {
            $table->string('study_type', 20)->nullable()->comment('research or coursework')->after('mode_of_study');
        });

        Schema::table('supervision_details', function (Blueprint $table) {
            $table->boolean('is_change_of_supervisor')->nullable()->after('previous_supervisor_id');
            $table->boolean('is_ga')->nullable()->after('is_change_of_supervisor');
            $table->string('ga_status_source', 10)->nullable()->after('is_ga');
            $table->string('co_supervisor_approver_snapshot', 20)->nullable()->after('cgs_stage_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('ga_extension_details', function (Blueprint $table) {
            $table->dropColumn('study_type');
        });

        Schema::table('supervision_details', function (Blueprint $table) {
            $table->dropColumn(['is_change_of_supervisor', 'is_ga', 'ga_status_source', 'co_supervisor_approver_snapshot']);
        });
    }
};
