<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the four tables nothing writes any more (docs/proposals/chloe-legacy-tables.md, agreed 2026-11-08):
 *
 *   study_candidacy_reminders       replaced by candidacy_reminder_log
 *   candidacy_dismissals            replaced by candidacy_dismissal_imports / _exports
 *   candidacy_appeal_details        the pre-2026-11-01 engine appeal; its data was copied into candidacy_appeals (legacy_application_id)
 *   workstation_pending_selections  the e-mailed seat-confirmation hold, removed in favour of in-page selection
 *
 * candidacy_appeal_publications.candidacy_appeal_detail_id pointed at candidacy_appeal_details, so it goes first. A legacy publication row is
 * re-pointed at its candidacy_appeals row (by legacy_application_id) before the column is dropped, and keeps its free-text `description`.
 * The original create migrations are untouched. down() recreates the four tables EMPTY (the rows that were in them are not restored).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('candidacy_appeal_details') && Schema::hasColumn('candidacy_appeal_publications', 'candidacy_appeal_detail_id')) {
            $links = DB::table('candidacy_appeal_publications')->whereNull('candidacy_appeal_id')->whereNotNull('candidacy_appeal_detail_id')->get(['id', 'candidacy_appeal_detail_id']);

            foreach ($links as $row) {
                $applicationId = DB::table('candidacy_appeal_details')->where('id', $row->candidacy_appeal_detail_id)->value('application_id');
                $appealId = $applicationId ? DB::table('candidacy_appeals')->where('legacy_application_id', $applicationId)->value('id') : null;

                if ($appealId) {
                    DB::table('candidacy_appeal_publications')->where('id', $row->id)->update(['candidacy_appeal_id' => $appealId]);
                }
            }
        }

        if (Schema::hasColumn('candidacy_appeal_publications', 'candidacy_appeal_detail_id')) {
            Schema::table('candidacy_appeal_publications', function (Blueprint $table) {
                $table->dropForeign(['candidacy_appeal_detail_id']);
            });

            Schema::table('candidacy_appeal_publications', function (Blueprint $table) {
                $table->dropColumn('candidacy_appeal_detail_id');
            });
        }

        Schema::dropIfExists('workstation_pending_selections');
        Schema::dropIfExists('candidacy_dismissals');
        Schema::dropIfExists('study_candidacy_reminders');
        Schema::dropIfExists('candidacy_appeal_details');
    }

    public function down(): void
    {
        Schema::create('study_candidacy_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_candidacy_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('reminder_number');
            $table->timestamp('sent_at');
            $table->timestamps();
        });

        Schema::create('candidacy_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_candidacy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 30);
            $table->timestamp('generated_at');
            $table->string('status', 20)->default('pending_review')->index();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('candidacy_appeal_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('study_candidacy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supervisor_id')->constrained('users')->cascadeOnDelete();
            $table->string('department')->nullable();
            $table->text('reason');
            $table->unsignedTinyInteger('requested_extension_months');
            $table->date('new_expiry_date')->nullable();
            $table->string('phase', 30)->nullable();
            $table->unsignedTinyInteger('writing_completion_percent')->nullable();
            $table->string('rcs_status', 20)->nullable();
            $table->date('rcs_date')->nullable();
            $table->string('rcs_category')->nullable();
            $table->date('rcs_expected_date')->nullable();
            $table->boolean('extension_via_gsc')->default(false);
            $table->boolean('extension_via_vc')->default(false);
            $table->timestamp('disclaimer_acknowledged_at')->nullable();
            $table->timestamps();
        });

        Schema::table('candidacy_appeal_publications', function (Blueprint $table) {
            $table->unsignedBigInteger('candidacy_appeal_detail_id')->nullable()->after('id');
        });

        Schema::create('workstation_pending_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workstation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('workstation_pending_selections', function (Blueprint $table) {
            $table->unsignedBigInteger('student_id_if_pending')->virtualAs("CASE WHEN status = 'pending' THEN student_id ELSE NULL END")->nullable()->after('status');
        });

        Schema::table('workstation_pending_selections', function (Blueprint $table) {
            $table->unique('student_id_if_pending');
        });
    }
};
