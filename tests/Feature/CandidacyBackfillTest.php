<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyAppealApproval;
use App\Modules\Chloe\Models\CandidacyAppealEvent;
use App\Modules\Chloe\Models\CandidacyStaffAssignment;
use App\Modules\Chloe\Models\CandidacyStaffProfile;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Support\CandidacyVerification;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** Phase 1: candidacy:backfill-appeals and candidacy:check-allowance on realistic legacy (shared-engine) data. */
class CandidacyBackfillTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected User $student;

    protected User $lecturer;

    protected User $chair;

    protected User $dean;

    protected User $cgs;

    protected StudyCandidacy $candidacy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->student('22001001', 'ahmad@test.my', ['name' => 'Ahmad Danial', 'department' => 'Computer & Information Sciences']);
        $this->lecturer = $this->user('supervisor', ['name' => 'Dr. Aisyah Rahman', 'email' => 'aisyah@test.my', 'department' => 'Computer & Information Sciences']);
        $this->chair = $this->user('chair', ['name' => 'Dr. Lim Wei Chun', 'email' => 'lim@test.my', 'department' => 'Computer & Information Sciences']);
        $this->dean = $this->user('dean_pgr', ['name' => 'Prof. Dr. Hafiz Osman', 'email' => 'hafiz@test.my', 'department' => 'PGR']);
        $this->cgs = $this->user('non_exec_cgs', ['name' => 'Puan Waheeda', 'email' => 'waheeda@test.my', 'department' => 'CGS']);
        $this->candidacy = StudyCandidacy::create([
            'student_id' => $this->student->id, 'programme_start_date' => '2024-01-10', 'candidacy_expiry_date' => '2027-06-10',
            'status' => 'active', 'cumulative_extension_months' => 6,
        ]);
    }

    /**
     * A legacy appeal as the shared engine stored it.
     *
     * @param  array<int, array{0: string, 1: string, 2: string, 3: string}>  $history  [stage_key, approver role key, decision, time]
     */
    protected function legacyAppeal(string $status, ?string $currentStage, array $history, int $months = 6, ?User $student = null, ?string $submittedAt = '2026-10-11 17:17:24'): int
    {
        $student ??= $this->student;
        $approvers = ['supervisor' => $this->lecturer, 'chair' => $this->chair, 'cgs_verify' => $this->cgs, 'dean' => $this->dean];

        $applicationId = DB::table('applications')->insertGetId([
            'student_id' => $student->id, 'submitted_by_id' => $student->id, 'module_type' => 'candidacy_appeal', 'status' => $status,
            'current_stage' => $currentStage, 'submitted_at' => $submittedAt, 'created_at' => $submittedAt, 'updated_at' => $submittedAt,
        ]);

        $detailId = DB::table('candidacy_appeal_details')->insertGetId([
            'application_id' => $applicationId, 'study_candidacy_id' => $this->candidacy->id, 'supervisor_id' => $this->lecturer->id,
            'department' => 'Computer & Information Sciences', 'reason' => 'I need more time to finish my thesis.', 'phase' => 'writing',
            'writing_completion_percent' => 75, 'rcs_status' => 'completed', 'rcs_date' => '2026-05-01', 'rcs_category' => 'Annual',
            'extension_via_gsc' => 1, 'extension_via_vc' => 0, 'requested_extension_months' => $months,
            'new_expiry_date' => $status === 'approved' ? '2027-06-10' : null, 'created_at' => $submittedAt, 'updated_at' => $submittedAt,
        ]);

        DB::table('candidacy_appeal_publications')->insert(['candidacy_appeal_detail_id' => $detailId, 'description' => 'A paper on drilling fluids, 2026.', 'created_at' => $submittedAt, 'updated_at' => $submittedAt]);

        foreach ($history as [$stage, $decision, $remarks, $at]) {
            DB::table('approval_history')->insert([
                'application_id' => $applicationId, 'approver_id' => $approvers[$stage]->id, 'stage_key' => $stage, 'stage_label' => ucfirst($stage),
                'decision' => $decision, 'remarks' => $remarks, 'created_at' => $at, 'updated_at' => $at,
            ]);
        }

        if ($status === 'approved') {
            DB::table('application_documents')->insert([
                'application_id' => $applicationId, 'doc_type' => 'Appeal for Extension of Candidacy Memo', 'original_name' => 'Ahmad Danial_22001001_Memo.pdf',
                'path' => 'documents/memo.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1000, 'created_at' => '2026-10-11 17:19:09', 'updated_at' => '2026-10-11 17:19:09',
            ]);
        }

        return $applicationId;
    }

    protected function approvedHistory(): array
    {
        return [
            ['supervisor', 'endorsed', 'Good progress.', '2026-10-11 17:18:05'],
            ['chair', 'endorsed', 'Agreed.', '2026-10-11 17:18:23'],
            ['cgs_verify', 'verified', 'Documents complete.', '2026-10-11 17:18:50'],
            ['dean', 'approved', 'Approved.', '2026-10-11 17:19:08'],
        ];
    }

    public function test_an_approved_legacy_appeal_is_imported_with_everything_mapped(): void
    {
        $applicationId = $this->legacyAppeal('approved', null, $this->approvedHistory());

        $this->artisan('candidacy:backfill-appeals')->assertSuccessful();

        $appeal = CandidacyAppeal::with(['approvals', 'events', 'publications'])->firstOrFail();
        $this->assertSame('SCA-22001001-01', $appeal->appeal_no);
        $this->assertSame(1, $appeal->sequence_no);
        $this->assertSame($applicationId, $appeal->legacy_application_id);
        $this->assertSame(CandidacyAppeal::STATUS_APPROVED, $appeal->status);
        $this->assertSame(CandidacyAppeal::OUTCOME_APPROVED, $appeal->final_outcome);
        $this->assertNull($appeal->current_stage);
        $this->assertSame(1, $appeal->round);
        $this->assertSame(6, $appeal->requested_months);
        $this->assertSame(6, $appeal->approved_months);
        $this->assertSame('2026-12-10', $appeal->expiry_at_submission->toDateString());
        $this->assertSame('2027-06-10', $appeal->new_expiry_date->toDateString());
        $this->assertSame('done', $appeal->rcs_status);
        $this->assertSame(75, $appeal->percent_complete);
        $this->assertTrue($appeal->gsc_extension);
        $this->assertFalse($appeal->vc_extension);
        $this->assertSame('I need more time to finish my thesis.', $appeal->reason);
        $this->assertSame('Ahmad Danial_22001001_Memo.pdf', $appeal->memo_filename);
        $this->assertSame('documents/memo.pdf', $appeal->memo_path);
        $this->assertSame('A paper on drilling fluids, 2026.', $appeal->publications->first()->title);

        $this->assertSame('Computer & Information Sciences', $appeal->department->name);
        $this->assertSame('Dr. Aisyah Rahman', $appeal->supervisorProfile->full_name);
        $this->assertSame('Dr. Lim Wei Chun', $appeal->chairProfile->full_name);
        $this->assertSame('Prof. Dr. Hafiz Osman', $appeal->deanProfile->full_name);

        // Events: submitted + four decisions, with the engine's words mapped to ours.
        $this->assertSame(['submitted', 'approved', 'approved', 'verified', 'approved'], $appeal->events->pluck('action')->all());
        $this->assertSame([null, 'supervisor', 'chair', 'cgs', 'dean'], $appeal->events->pluck('stage')->all());
        $this->assertSame(['with_supervisor', 'with_chair', 'with_cgs', 'with_dean', 'approved'], $appeal->events->pluck('to_status')->all());

        // Approvals: one per stage, codes recomputable.
        $this->assertEqualsCanonicalizing(['approved', 'approved', 'verified', 'approved'], $appeal->approvals->pluck('decision')->all());
        foreach ($appeal->approvals as $approval) {
            $this->assertSame(CandidacyVerification::code('SCA-22001001-01', $approval->stage, $approval->profile_id, $approval->decided_at), $approval->verification_code);
        }

        // The candidacy's own bookkeeping.
        $candidacy = $this->candidacy->fresh();
        $this->assertSame('2026-12-10', $candidacy->original_expiry_date->toDateString());
        $this->assertSame('2027-06-10', $candidacy->candidacy_expiry_date->toDateString());
        $this->assertSame(6, $candidacy->extension_months_used);
        $this->assertSame('Computer & Information Sciences', $candidacy->department->name);

        // Staff: the lecturer and the chair are assigned to the department, the dean and CGS are not.
        $this->assertEqualsCanonicalizing(['lecturer', 'chair'], CandidacyStaffAssignment::pluck('role')->all());
        $this->assertSame('Centre for Graduate Studies (CGS)', CandidacyStaffProfile::where('user_id', $this->cgs->id)->value('designation'));
    }

    public function test_the_legacy_tables_are_only_read(): void
    {
        $this->legacyAppeal('approved', null, $this->approvedHistory());
        $tables = ['applications', 'approval_history', 'application_documents', 'candidacy_appeal_details', 'candidacy_appeal_publications', 'users'];
        $snapshot = fn () => array_map(fn ($t) => DB::table($t)->orderBy('id')->get()->toJson(), $tables);
        $before = $snapshot();

        $this->artisan('candidacy:backfill-appeals')->assertSuccessful();

        $after = $snapshot();
        // The only legacy-shaped table that changes is the publications table, by gaining the NEW rows (the legacy row itself is intact).
        unset($before[4], $after[4]);
        $this->assertSame($before, $after);
        $this->assertSame(1, DB::table('candidacy_appeal_publications')->whereNotNull('candidacy_appeal_detail_id')->count());
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->legacyAppeal('approved', null, $this->approvedHistory());

        $this->artisan('candidacy:backfill-appeals')->assertSuccessful();
        $counts = fn () => [CandidacyAppeal::count(), CandidacyAppealEvent::count(), CandidacyAppealApproval::count(), CandidacyStaffProfile::count(), CandidacyStaffAssignment::count(), DB::table('candidacy_appeal_publications')->count()];
        $first = $counts();

        $this->artisan('candidacy:backfill-appeals')->assertSuccessful();

        $this->assertSame($first, $counts());
    }

    public function test_a_dry_run_reports_but_persists_nothing(): void
    {
        $this->legacyAppeal('approved', null, $this->approvedHistory());

        $this->artisan('candidacy:backfill-appeals', ['--dry-run' => true])->expectsOutputToContain('rolled back')->assertSuccessful();

        $this->assertSame(0, CandidacyAppeal::count());
        $this->assertSame(0, CandidacyStaffProfile::count());
        $this->assertNull($this->candidacy->fresh()->original_expiry_date);
    }

    public function test_a_returned_legacy_appeal_keeps_its_stage_and_round(): void
    {
        $this->legacyAppeal('returned', null, [
            ['supervisor', 'endorsed', 'Ok.', '2026-10-11 17:18:05'],
            ['chair', 'returned', 'Please add the publication list.', '2026-10-11 17:18:23'],
        ], 3);

        $this->artisan('candidacy:backfill-appeals')->assertSuccessful();

        $appeal = CandidacyAppeal::firstOrFail();
        $this->assertSame(CandidacyAppeal::STATUS_RETURNED, $appeal->status);
        $this->assertSame(CandidacyAppeal::STAGE_CHAIR, $appeal->returned_by_stage);
        $this->assertNull($appeal->current_stage);
        $this->assertSame(1, $appeal->round);
        $this->assertNull($appeal->approved_months);
        $this->assertTrue($appeal->isOpen());
        $this->assertSame(['submitted', 'approved', 'returned'], $appeal->events->pluck('action')->all());
        $this->assertSame('Please add the publication list.', $appeal->events->last()->remark);
        $this->assertTrue($this->candidacy->fresh()->hasOpenAppeal());
    }

    public function test_a_pending_legacy_appeal_is_with_its_stage(): void
    {
        $this->legacyAppeal('pending', 'chair', [['supervisor', 'endorsed', 'Ok.', '2026-10-11 17:18:05']], 4);

        $this->artisan('candidacy:backfill-appeals')->assertSuccessful();

        $appeal = CandidacyAppeal::firstOrFail();
        $this->assertSame(CandidacyAppeal::STATUS_WITH_CHAIR, $appeal->status);
        $this->assertSame(CandidacyAppeal::STAGE_CHAIR, $appeal->current_stage);
        $this->assertSame('2027-06-10', $appeal->expiry_at_submission->toDateString(), 'an open appeal starts from the current expiry');
        $this->assertNotNull($appeal->stage_entered_at);
    }

    public function test_a_second_appeal_of_the_same_student_gets_the_next_number_in_submission_order(): void
    {
        $this->legacyAppeal('approved', null, $this->approvedHistory(), 6, null, '2026-10-11 17:17:24');
        $this->legacyAppeal('pending', 'supervisor', [], 2, null, '2026-10-20 09:00:00');

        $this->artisan('candidacy:backfill-appeals')->assertSuccessful();

        $this->assertSame(['SCA-22001001-01', 'SCA-22001001-02'], CandidacyAppeal::orderBy('sequence_no')->pluck('appeal_no')->all());
        $this->assertSame(CandidacyAppeal::STATUS_APPROVED, CandidacyAppeal::where('appeal_no', 'SCA-22001001-01')->value('status'));
    }

    public function test_a_student_without_a_student_id_is_reported_not_guessed(): void
    {
        $noId = $this->user('student', ['name' => 'No Matric', 'email' => 'nomatric@test.my', 'matric_no' => null]);
        $this->candidacy->update(['student_id' => $noId->id]);
        $this->legacyAppeal('pending', 'supervisor', [], 3, $noId);

        $this->artisan('candidacy:backfill-appeals')->expectsOutputToContain('student, candidacy or student ID missing')->assertSuccessful();

        $this->assertSame(0, CandidacyAppeal::count());
    }

    public function test_the_allowance_check_passes_after_a_backfill_and_catches_a_mismatch(): void
    {
        $this->legacyAppeal('approved', null, $this->approvedHistory());
        $this->artisan('candidacy:backfill-appeals')->assertSuccessful();

        $this->artisan('candidacy:check-allowance')->expectsOutputToContain('OK')->assertSuccessful();

        $this->candidacy->update(['extension_months_used' => 3]);
        $this->artisan('candidacy:check-allowance')->expectsOutputToContain('MISMATCH')->assertFailed();

        $this->candidacy->update(['extension_months_used' => 6, 'candidacy_expiry_date' => '2027-07-10']);
        $this->artisan('candidacy:check-allowance')->expectsOutputToContain('MISMATCH')->assertFailed();
    }

    public function test_the_expiry_arithmetic_never_overflows_a_month_end(): void
    {
        $this->candidacy->update(['original_expiry_date' => '2026-08-31', 'candidacy_expiry_date' => '2027-02-28', 'extension_months_used' => 6]);
        CandidacyAppeal::create([
            'student_id' => $this->student->id, 'study_candidacy_id' => $this->candidacy->id, 'status' => 'approved', 'requested_months' => 6,
            'approved_months' => 6, 'sequence_no' => 1, 'appeal_no' => 'SCA-22001001-01',
        ]);

        $this->artisan('candidacy:check-allowance')->expectsOutputToContain('2027-02-28')->assertSuccessful();
    }
}
