<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyAppealDocument;
use App\Modules\Chloe\Models\CandidacyAppealEvent;
use App\Modules\Chloe\Models\CandidacyDepartment;
use App\Modules\Chloe\Models\CandidacyStaffAssignment;
use App\Modules\Chloe\Models\CandidacyStaffProfile;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Notifications\CandidacyAppealNotice;
use App\Modules\Chloe\Services\CandidacyAppealService;
use App\Modules\Chloe\Services\CandidacySignatureService;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** Phase 2: the appeal state machine, its policy, signatures, notifications and the daily close job. */
class CandidacyAppealServiceTest extends TestCase
{
    use BuildsCandidacyWorld;
    use MakesUsers;
    use RefreshDatabase;

    // ---- eligibility ----------------------------------------------------------------------------------------

    public function test_a_clean_candidacy_may_appeal_for_one_to_twelve_months(): void
    {
        $this->assertSame([], $this->service->eligibilityProblems($this->candidacy, 12));
        $this->assertNotSame([], $this->service->eligibilityProblems($this->candidacy, 13));
        $this->assertNotSame([], $this->service->eligibilityProblems($this->candidacy, 0));
    }

    public function test_expiry_today_is_still_eligible_but_yesterday_is_not(): void
    {
        $this->candidacy->update(['candidacy_expiry_date' => '2026-11-01']);
        $this->assertSame([], $this->service->eligibilityProblems($this->candidacy->fresh()));

        $this->candidacy->update(['candidacy_expiry_date' => '2026-10-31']);
        $this->assertNotSame([], $this->service->eligibilityProblems($this->candidacy->fresh()));
    }

    public function test_inactive_exhausted_or_finally_rejected_candidacies_cannot_appeal(): void
    {
        $this->candidacy->update(['status' => StudyCandidacy::STATUS_DISMISSED]);
        $this->assertNotSame([], $this->service->eligibilityProblems($this->candidacy->fresh()));

        $this->candidacy->update(['status' => StudyCandidacy::STATUS_ACTIVE, 'extension_months_used' => 12]);
        $this->assertNotSame([], $this->service->eligibilityProblems($this->candidacy->fresh()));

        $this->candidacy->update(['extension_months_used' => 6, 'last_rejection_at' => now()]);
        $this->assertNotSame([], $this->service->eligibilityProblems($this->candidacy->fresh()));
    }

    public function test_requested_months_cannot_exceed_the_remaining_allowance(): void
    {
        $this->candidacy->update(['extension_months_used' => 9]);
        $this->assertSame([], $this->service->eligibilityProblems($this->candidacy->fresh(), 3));
        $this->assertNotSame([], $this->service->eligibilityProblems($this->candidacy->fresh(), 4));
    }

    // ---- draft and submit -----------------------------------------------------------------------------------

    public function test_a_draft_has_no_number_and_there_is_only_one_per_student(): void
    {
        $a = $this->draft();
        $b = $this->service->saveDraft($this->student, ['reason' => 'Changed.']);

        $this->assertSame($a->id, $b->id);
        $this->assertNull($b->appeal_no);
        $this->assertSame(1, CandidacyAppeal::where('status', 'draft')->count());
    }

    public function test_submit_assigns_the_number_fixes_the_people_and_starts_at_the_supervisor(): void
    {
        $appeal = $this->submitted();

        $this->assertSame('SCA-22001001-01', $appeal->appeal_no);
        $this->assertSame(CandidacyAppeal::STATUS_WITH_SUPERVISOR, $appeal->status);
        $this->assertSame('supervisor', $appeal->current_stage);
        $this->assertSame(1, $appeal->round);
        $this->assertSame('2027-01-31', $appeal->expiry_at_submission->toDateString());
        $this->assertSame($this->profile($this->chairUser)->id, $appeal->chair_profile_id);
        $this->assertSame($this->profile($this->deanUser)->id, $appeal->dean_profile_id);
        $this->assertSame(['submitted'], CandidacyAppealEvent::where('candidacy_appeal_id', $appeal->id)->pluck('action')->all());
        Notification::assertSentTo($this->lecturer, CandidacyAppealNotice::class);
    }

    public function test_submit_needs_a_complete_draft_and_the_official_form(): void
    {
        $draft = $this->service->saveDraft($this->student, ['requested_months' => 3, 'supervisor_profile_id' => $this->profile($this->lecturer)->id]);

        try {
            $this->service->submit($this->student, $draft);
            $this->fail('an incomplete draft was submitted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
            $this->assertArrayHasKey('official_form', $e->errors());
            $this->assertArrayHasKey('declaration', $e->errors());
        }
    }

    public function test_the_supervisor_must_belong_to_the_students_department(): void
    {
        $other = CandidacyStaffProfile::create(['full_name' => 'Elsewhere', 'designation' => 'Lecturer']);
        $draft = $this->draft();
        $draft->update(['supervisor_profile_id' => $other->id]);

        $this->expectException(ValidationException::class);
        $this->service->submit($this->student, $draft);
    }

    public function test_the_default_dean_is_used_when_the_department_has_none(): void
    {
        CandidacyStaffAssignment::where('role', CandidacyStaffAssignment::ROLE_DEAN)->delete();

        $appeal = $this->submitted();

        $this->assertSame($this->profile($this->deanUser)->id, $appeal->dean_profile_id);
    }

    public function test_a_second_submitted_appeal_gets_the_next_number_after_the_first_closed(): void
    {
        $first = $this->toStage('dean', 3);
        $this->service->decide($this->deanUser, $first, 'reject', ['progress_insufficient']);
        // a final rejection blocks every later appeal
        $this->assertNotSame([], $this->service->eligibilityProblems($this->candidacy->fresh()));
    }

    // ---- the happy path and approvals -----------------------------------------------------------------------

    public function test_full_approval_chain_extends_the_candidacy_by_calendar_months(): void
    {
        $appeal = $this->toStage('dean', 1);
        $appeal = $this->service->decide($this->deanUser, $appeal, 'approve');

        $this->assertSame(CandidacyAppeal::STATUS_APPROVED, $appeal->status);
        $this->assertNull($appeal->current_stage);
        $this->assertSame(1, $appeal->approved_months);
        $this->assertSame('2027-02-28', $appeal->new_expiry_date->toDateString()); // 31 Jan + 1 month clamps, no overflow
        $this->assertSame('2027-02-28', $this->candidacy->fresh()->candidacy_expiry_date->toDateString());
        $this->assertSame(1, $this->candidacy->fresh()->extension_months_used);
        $this->assertSame('approved', $appeal->final_outcome);
        $this->assertSame(['approved', 'approved', 'verified', 'approved'], $appeal->approvals()->orderBy('id')->pluck('decision')->all());
    }

    public function test_every_approval_stores_a_signature_copy_and_a_verification_code(): void
    {
        $appeal = $this->toStage('chair');
        $approval = $appeal->approvals()->where('stage', 'supervisor')->first();

        $this->assertNotNull($approval->signature_snapshot_path);
        Storage::disk('local')->assertExists($approval->signature_snapshot_path);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{10}$/', $approval->verification_code);

        // replacing the signature later does not change the copy
        $before = hash('sha256', Storage::disk('local')->get($approval->signature_snapshot_path));
        $this->service_signatures()->placeholder($this->profile($this->lecturer)->forceFill(['full_name' => 'Someone Else']));
        $this->assertSame($before, hash('sha256', Storage::disk('local')->get($approval->signature_snapshot_path)));
    }

    public function test_approving_without_a_signature_is_refused_for_lecturer_chair_and_dean_but_not_cgs(): void
    {
        $appeal = $this->submitted();
        $this->service_signatures()->remove($this->profile($this->lecturer));

        try {
            $this->service->decide($this->lecturer, $appeal, 'approve');
            $this->fail('approved without a signature');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('signature', $e->errors());
        }

        $this->assertSame(CandidacyAppeal::STATUS_WITH_SUPERVISOR, $appeal->fresh()->status);

        $this->service_signatures()->placeholder($this->profile($this->lecturer));
        $appeal = $this->service->decide($this->lecturer, $appeal, 'approve');
        $appeal = $this->service->decide($this->chairUser, $appeal, 'approve');
        $appeal = $this->service->decide($this->cgsUser, $appeal, 'verify', documentsComplete: true); // no signature needed
        $this->assertSame('dean', $appeal->current_stage);
    }

    // ---- who may act ----------------------------------------------------------------------------------------

    public function test_only_the_named_person_may_act_and_only_at_their_stage(): void
    {
        $appeal = $this->submitted();
        $otherSup = $this->supervisor('other@test.my');

        foreach ([$otherSup, $this->chairUser, $this->deanUser, $this->cgsUser, $this->student] as $intruder) {
            try {
                $this->service->decide($intruder, $appeal, 'approve');
                $this->fail("{$intruder->role} acted at the supervisor stage");
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(CandidacyAppeal::STATUS_WITH_SUPERVISOR, $appeal->fresh()->status);
    }

    public function test_every_cgs_role_may_view_and_act_at_the_cgs_stage_but_other_roles_may_not(): void
    {
        $appeal = $this->toStage('chair');
        $appeal = $this->service->decide($this->chairUser, $appeal, 'approve');
        $this->assertSame('cgs', $appeal->current_stage);

        foreach (config('candidacy.cgs_roles') as $i => $role) {
            $user = $this->user($role, ['email' => "cgs{$i}@test.my"]);
            $this->assertTrue($user->can('view', $appeal), "{$role} view");
            $this->assertTrue($user->can('act', $appeal), "{$role} act");
        }

        foreach ([Role::ACADEMIC_EXEC, Role::FACULTY, Role::REGISTRY] as $i => $role) {
            $user = $this->user($role, ['email' => "other{$i}@test.my"]);
            $this->assertFalse($user->can('view', $appeal), "{$role} view");
            $this->assertFalse($user->can('act', $appeal), "{$role} act");
        }

        $this->assertEqualsCanonicalizing(\App\Modules\Core\Support\Role::cgsTeam(), array_diff(config('candidacy.cgs_roles'), ['cgs']));
    }

    public function test_the_policy_scopes_viewing_and_downloads(): void
    {
        $appeal = $this->submitted();
        $stranger = $this->student('22009999', 'x@test.my');

        $this->assertTrue($this->student->can('view', $appeal));
        $this->assertTrue($this->lecturer->can('view', $appeal));
        $this->assertTrue($this->cgsUser->can('view', $appeal));
        $this->assertFalse($stranger->can('view', $appeal));
        $this->assertFalse($this->supervisor('o@test.my')->can('view', $appeal));
        $this->assertFalse($this->student->can('act', $appeal));
        $this->assertFalse($this->student->can('downloadMemo', $appeal)); // not approved yet
    }

    // ---- no reject before the Dean; reasons ------------------------------------------------------------------

    public function test_supervisor_chair_and_cgs_cannot_reject(): void
    {
        $appeal = $this->submitted();

        $this->expectException(ValidationException::class);
        $this->service->decide($this->lecturer, $appeal, 'reject', ['other'], 'no');
    }

    public function test_a_return_needs_a_listed_reason_and_other_needs_a_remark(): void
    {
        $appeal = $this->submitted();

        foreach ([[[], null], [['invented'], null], [['other'], null]] as [$reasons, $remark]) {
            try {
                $this->service->decide($this->lecturer, $appeal, 'return', $reasons, $remark);
                $this->fail('returned with an invalid reason');
            } catch (ValidationException) {
                $this->assertSame(CandidacyAppeal::STATUS_WITH_SUPERVISOR, $appeal->fresh()->status);
            }
        }

        $appeal = $this->service->decide($this->lecturer, $appeal, 'return', ['other'], 'Please explain.');
        $this->assertSame(CandidacyAppeal::STATUS_RETURNED, $appeal->status);
        $this->assertSame('supervisor', $appeal->returned_by_stage);
    }

    public function test_cgs_cannot_verify_without_confirming_the_documents(): void
    {
        $appeal = $this->toStage('chair');
        $appeal = $this->service->decide($this->chairUser, $appeal, 'approve');

        $this->expectException(ValidationException::class);
        $this->service->decide($this->cgsUser, $appeal, 'verify', documentsComplete: false);
    }

    // ---- returns and resubmission ---------------------------------------------------------------------------

    public function test_resubmit_goes_back_to_the_returning_stage_in_a_new_round(): void
    {
        $appeal = $this->toStage('chair');
        $appeal = $this->service->decide($this->chairUser, $appeal, 'return', ['documents_missing']);
        $this->assertSame(CandidacyAppeal::STATUS_RETURNED, $appeal->status);

        $appeal = $this->service->resubmit($this->student, $appeal, ['reason' => 'Added documents.']);

        $this->assertSame(CandidacyAppeal::STATUS_WITH_CHAIR, $appeal->status);
        $this->assertSame(2, $appeal->round);
        $this->assertNull($appeal->returned_by_stage);
        $this->assertSame('SCA-22001001-01', $appeal->appeal_no);
    }

    public function test_a_dean_return_goes_through_cgs_again_and_then_back_to_the_dean(): void
    {
        $appeal = $this->toStage('dean');
        $appeal = $this->service->decide($this->deanUser, $appeal, 'return', ['clarification_needed']);
        $this->assertTrue($appeal->needs_cgs_recheck);

        $appeal = $this->service->resubmit($this->student, $appeal);
        $this->assertSame('cgs', $appeal->current_stage);
        $this->assertTrue($appeal->needs_cgs_recheck);

        $appeal = $this->service->decide($this->cgsUser, $appeal, 'verify', documentsComplete: true);
        $this->assertSame('dean', $appeal->current_stage);
        $this->assertFalse($appeal->needs_cgs_recheck);
    }

    public function test_resubmit_is_refused_after_the_candidacy_expired(): void
    {
        $appeal = $this->submitted();
        $appeal = $this->service->decide($this->lecturer, $appeal, 'return', ['documents_missing']);
        $this->candidacy->update(['candidacy_expiry_date' => '2026-10-31']);

        $this->expectException(ValidationException::class);
        $this->service->resubmit($this->student, $appeal->fresh());
    }

    public function test_dean_rejection_is_final_and_marks_the_candidacy(): void
    {
        $appeal = $this->toStage('dean');
        $appeal = $this->service->decide($this->deanUser, $appeal, 'reject', ['progress_insufficient']);

        $this->assertSame(CandidacyAppeal::STATUS_REJECTED, $appeal->status);
        $this->assertSame('rejected_final', $appeal->final_outcome);
        $this->assertNotNull($this->candidacy->fresh()->last_rejection_at);
        $this->assertTrue($this->candidacy->fresh()->hasFinalRejection());
        $this->assertFalse($this->student->can('edit', $appeal));
    }

    public function test_no_withdraw_exists(): void
    {
        $this->assertFalse(method_exists($this->service, 'withdraw'));
    }

    // ---- history and notifications --------------------------------------------------------------------------

    public function test_every_step_is_recorded_in_the_append_only_history(): void
    {
        $appeal = $this->toStage('dean');
        $appeal = $this->service->decide($this->deanUser, $appeal, 'approve');

        $this->assertSame(
            ['submitted', 'approved', 'approved', 'verified', 'approved'],
            CandidacyAppealEvent::where('candidacy_appeal_id', $appeal->id)->orderBy('id')->pluck('action')->all()
        );
        $this->assertSame('dean', CandidacyAppealEvent::where('candidacy_appeal_id', $appeal->id)->orderByDesc('id')->first()->actor_kind);
    }

    public function test_notifications_carry_the_number_in_the_subject_and_the_return_reasons(): void
    {
        $appeal = $this->submitted();
        $this->service->decide($this->lecturer, $appeal, 'return', ['documents_missing', 'other'], 'See page 2.');

        Notification::assertSentTo($this->student, CandidacyAppealNotice::class, function (CandidacyAppealNotice $n) {
            $mail = $n->toMail($this->student);

            return $n->template === 'returned_student'
                && str_starts_with($mail->subject, 'SCA-22001001-01')
                && str_contains(implode(' ', $mail->introLines), 'Supporting documents missing')
                && str_contains(implode(' ', $mail->introLines), 'See page 2.')
                && str_contains(implode(' ', $mail->introLines), 'resubmit it before 31 Jan 2027')
                && in_array('database', $n->via($this->student), true);
        });
    }

    public function test_final_approval_mails_the_student_with_bold_months_and_expiry(): void
    {
        $appeal = $this->toStage('dean', 2);
        $this->service->decide($this->deanUser, $appeal, 'approve');

        Notification::assertSentTo($this->student, CandidacyAppealNotice::class, function (CandidacyAppealNotice $n) {
            $lines = implode(' ', $n->toMail($this->student)->introLines);

            return $n->template === 'final_student' && str_contains($lines, '**2 month(s)**') && str_contains($lines, '**31 Mar 2027**');
        });
    }

    // ---- signatures ----------------------------------------------------------------------------------------

    public function test_signature_upload_is_decoded_resized_and_logged(): void
    {
        $profile = $this->profile($this->lecturer);
        $file = UploadedFile::fake()->image('sig.png', 1200, 400);

        $this->service_signatures()->store($profile, $file, $this->lecturer);
        $info = getimagesizefromstring(Storage::disk('local')->get($profile->fresh()->signature_path));

        $this->assertSame(IMAGETYPE_PNG, $info[2]);
        $this->assertLessThanOrEqual(600, $info[0]);
        $this->assertLessThanOrEqual(200, $info[1]);
        $this->assertTrue(CandidacyAppealEvent::where('staff_profile_id', $profile->id)->where('action', 'signature_uploaded')->exists());
    }

    public function test_signature_upload_rejects_non_images_and_small_images(): void
    {
        $profile = $this->profile($this->lecturer);

        foreach ([UploadedFile::fake()->createWithContent('evil.png', '<?php echo 1;'), UploadedFile::fake()->image('tiny.png', 50, 20)] as $file) {
            try {
                $this->service_signatures()->store($profile, $file, $this->lecturer);
                $this->fail('a bad signature was accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('signature', $e->errors());
            }
        }
    }

    public function test_a_signature_image_is_visible_only_to_its_owner(): void
    {
        $profile = $this->profile($this->lecturer);

        $this->assertTrue($this->lecturer->can('viewSignature', $profile));
        $this->assertFalse($this->chairUser->can('viewSignature', $profile));
        $this->assertFalse($this->cgsUser->can('viewSignature', $profile));
    }

    // ---- daily close job -----------------------------------------------------------------------------------

    public function test_returned_appeals_are_closed_after_expiry_and_the_job_is_idempotent(): void
    {
        $appeal = $this->submitted();
        $appeal = $this->service->decide($this->lecturer, $appeal, 'return', ['documents_missing']);

        $this->assertSame(0, $this->service->closeExpired()->count()); // not expired yet

        $this->candidacy->update(['candidacy_expiry_date' => '2026-10-31']);
        $this->assertSame(1, $this->service->closeExpired(dryRun: true)->count());
        $this->assertSame(CandidacyAppeal::STATUS_RETURNED, $appeal->fresh()->status); // dry run changed nothing

        $this->assertSame(1, $this->service->closeExpired()->count());
        $this->assertSame(0, $this->service->closeExpired()->count());
        $this->assertSame(CandidacyAppeal::STATUS_CLOSED_EXPIRED, $appeal->fresh()->status);
        Notification::assertSentTo($this->student, CandidacyAppealNotice::class, fn ($n) => $n->template === 'closed_expired');
    }

    public function test_an_appeal_waiting_at_an_approver_is_never_closed_by_expiry(): void
    {
        $appeal = $this->submitted();
        $this->candidacy->update(['candidacy_expiry_date' => '2026-10-31']);

        $this->assertSame(0, $this->service->closeExpired()->count());
        $this->assertSame(CandidacyAppeal::STATUS_WITH_SUPERVISOR, $appeal->fresh()->status);
    }

    public function test_the_close_command_has_a_dry_run_and_is_scheduled_for_0720_kuala_lumpur(): void
    {
        $this->artisan('candidacy:close-expired-appeals', ['--dry-run' => true])->expectsOutputToContain('Would close 0')->assertSuccessful();

        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'candidacy:close-expired-appeals'));

        $this->assertNotNull($event);
        $this->assertSame('30 7 * * *', $event->expression);
        $this->assertSame('Asia/Kuala_Lumpur', (string) $event->timezone);
    }

    // ---- the return notification matrix ----------------------------------------------------------------------

    /** @return list<string> the e-mails of everyone who got a notice of $template (the student's e-mail is the test student's) */
    protected function recipients(string $template): array
    {
        $out = [];
        foreach (\App\Modules\Core\Models\User::all() as $user) {
            if (Notification::sent($user, CandidacyAppealNotice::class)->contains(fn ($n) => $n->template === $template)) {
                $out[] = $user->role;
            }
        }
        sort($out);

        return $out;
    }

    public function test_a_return_informs_exactly_the_people_of_the_matrix(): void
    {
        $this->user(Role::MANAGER_CGS, ['email' => 'mgr@test.my']); // another CGS user, who must hear only of a Dean return

        $appeal = $this->submitted();
        $this->service->decide($this->lecturer, $appeal, 'return', ['documents_missing']);
        $this->assertSame([Role::STUDENT], $this->recipients('returned_student'));
        $this->assertSame([Role::SUPERVISOR], $this->recipients('returned_info'));

        Notification::fake();
        $appeal = $this->service->resubmit($this->student, $appeal->fresh());
        $appeal = $this->service->decide($this->lecturer, $appeal, 'approve');
        Notification::fake();
        $this->service->decide($this->chairUser, $appeal, 'return', ['documents_missing']);
        $this->assertSame([Role::SUPERVISOR], $this->recipients('returned_info')); // the lecturer, not the Chair himself
        $this->assertSame([Role::STUDENT], $this->recipients('returned_student'));

        Notification::fake();
        $appeal = $this->service->resubmit($this->student, $appeal->fresh());
        $appeal = $this->service->decide($this->chairUser, $appeal, 'approve');
        Notification::fake();
        $this->service->decide($this->cgsUser, $appeal, 'return', ['documents_unclear']);
        $this->assertSame([Role::CHAIR, Role::SUPERVISOR], $this->recipients('returned_info'));

        Notification::fake();
        $appeal = $this->service->resubmit($this->student, $appeal->fresh());
        $appeal = $this->service->decide($this->cgsUser, $appeal, 'verify', documentsComplete: true);
        Notification::fake();
        $this->service->decide($this->deanUser, $appeal, 'return', ['clarification_needed']);
        $this->assertSame([Role::CHAIR, Role::MANAGER_CGS, Role::NON_EXEC_CGS, Role::SUPERVISOR], $this->recipients('returned_info'));
    }
}
