<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Study Candidacy Appeal, in OUR tables and on OUR state machine (CandidacyAppealService). New appeals are
 * never written into the shared engine's `applications` table, so they can never appear in a shared queue.
 *
 * candidacy_appeals            the appeal itself (status machine, people fixed at submission, sections A-B, memo)
 * candidacy_appeal_publications  section C -- the existing legacy table is extended (legacy rows keep their columns)
 * candidacy_appeal_documents   the official form and supporting documents, stored under generated safe names
 * candidacy_appeal_events      APPEND-ONLY history (no update/delete anywhere in code; see CandidacyAppealEvent)
 * candidacy_appeal_approvals   one row per stage decision of a round, with a COPY of the approver's signature
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidacy_appeals', function (Blueprint $table) {
            $table->id();
            $table->string('appeal_no', 30)->nullable()->unique(); // SCA-<studentID>-<NN>, assigned at submission, never changed
            $table->unsignedTinyInteger('sequence_no')->nullable(); // the student's n-th SUBMITTED appeal (so never above 12)

            // Same identity resolution as the Workstation module (StudentDirectory): a real account or a demo profile.
            $table->foreignId('student_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('demo_profile_id')->nullable()->constrained('demo_student_profiles')->cascadeOnDelete();
            $table->foreignId('study_candidacy_id')->nullable()->constrained('study_candidacies')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('candidacy_departments')->nullOnDelete();

            // The named people, fixed at submission.
            $table->foreignId('supervisor_profile_id')->nullable()->constrained('candidacy_staff_profiles')->nullOnDelete();
            $table->foreignId('chair_profile_id')->nullable()->constrained('candidacy_staff_profiles')->nullOnDelete();
            $table->foreignId('dean_profile_id')->nullable()->constrained('candidacy_staff_profiles')->nullOnDelete();

            $table->string('status', 20)->default('draft')->index(); // draft|with_supervisor|with_chair|with_cgs|with_dean|returned|approved|rejected|closed_expired
            $table->string('current_stage', 12)->nullable();          // supervisor|chair|cgs|dean
            $table->unsignedSmallInteger('round')->default(1);
            $table->string('returned_by_stage', 12)->nullable();
            $table->boolean('needs_cgs_recheck')->default(false);

            $table->unsignedTinyInteger('requested_months')->nullable();
            $table->unsignedTinyInteger('approved_months')->nullable();
            $table->date('expiry_at_submission')->nullable();
            $table->date('new_expiry_date')->nullable();

            $table->text('reason')->nullable();
            // Section A
            $table->string('phase', 30)->nullable();
            $table->unsignedTinyInteger('percent_complete')->nullable();
            $table->string('rcs_status', 12)->nullable(); // done|pending
            $table->date('rcs_date')->nullable();
            $table->date('rcs_expected_date')->nullable();
            $table->string('rcs_category', 255)->nullable();
            // Section B
            $table->boolean('gsc_extension')->default(false);
            $table->boolean('vc_extension')->default(false);
            $table->timestamp('declaration_accepted_at')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('stage_entered_at')->nullable();
            $table->timestamp('last_action_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('final_outcome', 20)->nullable(); // approved|rejected_final|closed_expired

            $table->string('memo_path', 255)->nullable();
            $table->string('memo_filename', 255)->nullable();
            $table->timestamp('memo_generated_at')->nullable();

            // Set only by candidacy:backfill-appeals: which legacy shared-engine application this row was imported from.
            $table->unsignedBigInteger('legacy_application_id')->nullable()->unique();

            $table->timestamps();

            $table->unique(['student_id', 'sequence_no'], 'cand_appeals_student_seq');
            $table->unique(['demo_profile_id', 'sequence_no'], 'cand_appeals_demo_seq');
            $table->index(['current_stage', 'status']);
        });

        if (DB::getDriverName() === 'mysql') {
            // MySQL has no partial indexes: a generated key that is only non-NULL while the rule applies, made unique.
            $who = "CONCAT(IF(student_id IS NULL, 'd', 's'), COALESCE(student_id, demo_profile_id))";
            DB::statement("ALTER TABLE candidacy_appeals ADD COLUMN open_key VARCHAR(24) GENERATED ALWAYS AS (IF(status IN ('with_supervisor','with_chair','with_cgs','with_dean','returned'), {$who}, NULL)) VIRTUAL");
            DB::statement('CREATE UNIQUE INDEX cand_appeals_one_open ON candidacy_appeals (open_key)');
            DB::statement("ALTER TABLE candidacy_appeals ADD COLUMN draft_key VARCHAR(24) GENERATED ALWAYS AS (IF(status = 'draft', {$who}, NULL)) VIRTUAL");
            DB::statement('CREATE UNIQUE INDEX cand_appeals_one_draft ON candidacy_appeals (draft_key)');

            DB::statement('ALTER TABLE candidacy_appeals ADD CONSTRAINT cand_appeals_requested_check CHECK (requested_months IS NULL OR requested_months BETWEEN 1 AND 12)');
            DB::statement('ALTER TABLE candidacy_appeals ADD CONSTRAINT cand_appeals_approved_check CHECK (approved_months IS NULL OR approved_months BETWEEN 1 AND 12)');
            DB::statement('ALTER TABLE candidacy_appeals ADD CONSTRAINT cand_appeals_sequence_check CHECK (sequence_no IS NULL OR sequence_no BETWEEN 1 AND 12)');
            DB::statement('ALTER TABLE candidacy_appeals ADD CONSTRAINT cand_appeals_one_identity_check CHECK ((student_id IS NULL) <> (demo_profile_id IS NULL))');
        }

        if (DB::getDriverName() === 'sqlite') {
            // The isolated test database: partial unique indexes for the one-open / one-draft rules and triggers for the CHECKs.
            $open = "status IN ('with_supervisor','with_chair','with_cgs','with_dean','returned')";
            DB::statement("CREATE UNIQUE INDEX cand_appeals_one_open ON candidacy_appeals (student_id) WHERE student_id IS NOT NULL AND {$open}");
            DB::statement("CREATE UNIQUE INDEX cand_appeals_one_open_demo ON candidacy_appeals (demo_profile_id) WHERE demo_profile_id IS NOT NULL AND {$open}");
            DB::statement("CREATE UNIQUE INDEX cand_appeals_one_draft ON candidacy_appeals (student_id) WHERE student_id IS NOT NULL AND status = 'draft'");
            DB::statement("CREATE UNIQUE INDEX cand_appeals_one_draft_demo ON candidacy_appeals (demo_profile_id) WHERE demo_profile_id IS NOT NULL AND status = 'draft'");

            $rules = [
                'cand_appeals_requested_check' => 'NEW.requested_months IS NOT NULL AND (NEW.requested_months < 1 OR NEW.requested_months > 12)',
                'cand_appeals_approved_check' => 'NEW.approved_months IS NOT NULL AND (NEW.approved_months < 1 OR NEW.approved_months > 12)',
                'cand_appeals_sequence_check' => 'NEW.sequence_no IS NOT NULL AND (NEW.sequence_no < 1 OR NEW.sequence_no > 12)',
                'cand_appeals_one_identity_check' => '(NEW.student_id IS NULL) = (NEW.demo_profile_id IS NULL)',
            ];
            foreach (['INSERT', 'UPDATE'] as $event) {
                $body = implode(' ', array_map(fn ($name, $when) => "SELECT RAISE(ABORT, '{$name}') WHERE {$when};", array_keys($rules), $rules));
                DB::statement("CREATE TRIGGER cand_appeals_checks_{$event} BEFORE {$event} ON candidacy_appeals BEGIN {$body} END");
            }
        }

        // Section C: extend the legacy table (legacy rows keep description + candidacy_appeal_detail_id).
        Schema::table('candidacy_appeal_publications', function (Blueprint $table) {
            $table->foreignId('candidacy_appeal_id')->nullable()->after('id')->constrained('candidacy_appeals')->cascadeOnDelete();
            $table->string('title', 500)->nullable()->after('candidacy_appeal_id');
            $table->string('venue', 255)->nullable()->after('title');
            $table->string('status', 40)->nullable()->after('venue');
            $table->unsignedSmallInteger('year')->nullable()->after('status');
        });
        Schema::table('candidacy_appeal_publications', function (Blueprint $table) {
            $table->unsignedBigInteger('candidacy_appeal_detail_id')->nullable()->change();
            $table->text('description')->nullable()->change();
        });

        Schema::create('candidacy_appeal_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidacy_appeal_id')->constrained('candidacy_appeals')->cascadeOnDelete();
            $table->string('kind', 20); // official_form|supporting
            $table->string('original_name', 255);
            $table->string('stored_path', 255); // a generated safe name, never the user's file name
            $table->string('mime', 120);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->unsignedSmallInteger('round_uploaded')->default(1);
            $table->timestamp('uploaded_at');
        });

        Schema::create('candidacy_appeal_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidacy_appeal_id')->nullable()->constrained('candidacy_appeals')->cascadeOnDelete();
            $table->foreignId('staff_profile_id')->nullable()->constrained('candidacy_staff_profiles')->cascadeOnDelete(); // non-appeal events, e.g. a signature upload
            $table->timestamp('occurred_at', 6);
            $table->string('actor_kind', 10); // student|lecturer|chair|cgs|dean|system
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_name', 150);
            $table->string('actor_role', 30)->nullable();
            $table->string('action', 40)->index();
            $table->string('stage', 12)->nullable();
            $table->unsignedSmallInteger('round')->nullable();
            $table->json('reason_codes')->nullable();
            $table->text('remark')->nullable();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->index(['candidacy_appeal_id', 'occurred_at']);
        });

        Schema::create('candidacy_appeal_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidacy_appeal_id')->constrained('candidacy_appeals')->cascadeOnDelete();
            $table->unsignedSmallInteger('round');
            $table->string('stage', 12);
            $table->foreignId('profile_id')->nullable()->constrained('candidacy_staff_profiles')->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('full_name', 150);          // snapshots: a later rename never rewrites an existing memo
            $table->string('designation', 255);
            $table->string('signature_snapshot_path', 255)->nullable(); // a COPY of the signature image at decision time
            $table->char('signature_snapshot_sha256', 64)->nullable();
            $table->string('decision', 10);             // approved|verified|returned|rejected
            $table->boolean('documents_complete')->nullable(); // CGS verification checklist
            $table->text('remark')->nullable();
            $table->timestamp('decided_at');
            $table->string('verification_code', 12);
            $table->timestamps();

            $table->unique(['candidacy_appeal_id', 'round', 'stage'], 'cand_approvals_round_stage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidacy_appeal_approvals');
        Schema::dropIfExists('candidacy_appeal_events');
        Schema::dropIfExists('candidacy_appeal_documents');

        Schema::table('candidacy_appeal_publications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('candidacy_appeal_id');
            $table->dropColumn(['title', 'venue', 'status', 'year']);
        });

        Schema::dropIfExists('candidacy_appeals');
    }
};
