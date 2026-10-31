<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppealDetail;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Notifications\CandidacyAppealApproved;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The appeal form mirrors CGS's actual paper template (Sections A-D + a
 * required disclaimer) rather than a freeform reason/months pair -- these
 * tests exercise that exact structure end to end through the real routes,
 * since the fields (phase, RCS either-or, GSC/VC flags, publications list)
 * are conditionally required in ways only an HTTP round-trip proves.
 */
class CandidacyAppealTest extends TestCase
{
    use RefreshDatabase;

    protected function user(string $role, string $email): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => 'password',
            'role' => $role,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->markTestSkipped('Superseded by CandidacyAppealServiceTest / CandidacyStudentPagesTest: the old engine-based student flow now redirects to the new pages. Deleted in Stage 4.');
    }

    protected function candidacyFor(User $student, array $overrides = []): StudyCandidacy
    {
        return StudyCandidacy::create(array_merge([
            'student_id' => $student->id,
            'programme_start_date' => now()->subYears(2),
            'candidacy_expiry_date' => now()->addMonths(2),
            'status' => StudyCandidacy::STATUS_ACTIVE,
        ], $overrides));
    }

    protected function validPayload(User $supervisor, array $overrides = []): array
    {
        return array_merge([
            'supervisor_id' => $supervisor->id,
            'department' => 'Computer & Information Sciences',
            'reason' => 'My data collection was delayed by equipment failure in the lab.',
            'phase' => CandidacyAppealDetail::PHASE_WRITING,
            'writing_completion_percent' => 50,
            'rcs_status' => CandidacyAppealDetail::RCS_PENDING,
            'rcs_expected_date' => now()->addMonth()->format('Y-m-d'),
            'extension_via_gsc' => 'no',
            'extension_via_vc' => 'no',
            'publications' => ['A study on reservoir modelling', ''],
            'requested_extension_months' => 6,
            'disclaimer' => '1',
        ], $overrides);
    }

    public function test_a_full_section_a_to_d_appeal_is_submitted(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $this->candidacyFor($student);

        $this->actingAs($student)
            ->post(route('candidacy-appeal.store'), $this->validPayload($supervisor))
            ->assertRedirect(route('applications.index'));

        $application = Application::where('module_type', 'candidacy_appeal')->sole();
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('supervisor', $application->current_stage);
        $this->assertSame(0, $application->documents()->count());

        $detail = CandidacyAppealDetail::where('application_id', $application->id)->sole();
        $this->assertSame('Computer & Information Sciences', $detail->department);
        $this->assertSame('My data collection was delayed by equipment failure in the lab.', $detail->reason);
        $this->assertSame('writing', $detail->phase);
        $this->assertSame(50, $detail->writing_completion_percent);
        $this->assertSame('pending', $detail->rcs_status);
        $this->assertNull($detail->rcs_date);
        $this->assertFalse($detail->extension_via_gsc);
        $this->assertNotNull($detail->disclaimer_acknowledged_at);
        $this->assertSame(6, $detail->requested_extension_months);

        // The blank second row is dropped, only the real one is kept.
        $this->assertSame(['A study on reservoir modelling'], $detail->publications->pluck('description')->all());
    }

    public function test_disclaimer_acknowledgement_is_required(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $this->candidacyFor($student);

        $payload = $this->validPayload($supervisor);
        unset($payload['disclaimer']);

        $this->actingAs($student)
            ->post(route('candidacy-appeal.store'), $payload)
            ->assertSessionHasErrors('disclaimer');

        $this->assertSame(0, Application::where('module_type', 'candidacy_appeal')->count());
    }

    /** Named against the signature block on UTP's official appeal memo. */
    public function test_department_is_required(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $this->candidacyFor($student);

        $payload = $this->validPayload($supervisor);
        unset($payload['department']);

        $this->actingAs($student)
            ->post(route('candidacy-appeal.store'), $payload)
            ->assertSessionHasErrors('department');

        $this->assertSame(0, Application::where('module_type', 'candidacy_appeal')->count());
    }

    /** The appeal memo's letter body is the actual appeal -- not an optional extra. */
    public function test_reason_for_appeal_is_required(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $this->candidacyFor($student);

        $payload = $this->validPayload($supervisor);
        unset($payload['reason']);

        $this->actingAs($student)
            ->post(route('candidacy-appeal.store'), $payload)
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, Application::where('module_type', 'candidacy_appeal')->count());
    }

    public function test_the_department_field_defaults_to_the_students_own_profile(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $student->update(['department' => 'Civil & Environmental Engineering']);
        $this->candidacyFor($student);

        // Blade escapes the ampersand in the rendered attribute.
        $this->actingAs($student)
            ->get(route('candidacy-appeal.create'))
            ->assertOk()
            ->assertSee('Civil &amp; Environmental Engineering', false);
    }

    /** Read-only: the account's own matric number, not something the form collects. */
    public function test_the_student_id_field_shows_the_students_own_matric_number(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $student->update(['matric_no' => '22005678']);
        $this->candidacyFor($student);

        $this->actingAs($student)
            ->get(route('candidacy-appeal.create'))
            ->assertOk()
            ->assertSee('22005678')
            ->assertSee('readonly', false);
    }

    public function test_percentage_of_completion_is_required_regardless_of_phase(): void
    {
        // The paper form's percentage-of-completion field is one merged
        // cell spanning all four phase rows, not a Writing-only field.
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $this->candidacyFor($student);

        $payload = $this->validPayload($supervisor, ['phase' => CandidacyAppealDetail::PHASE_SIMULATION]);
        unset($payload['writing_completion_percent']);

        $this->actingAs($student)
            ->post(route('candidacy-appeal.store'), $payload)
            ->assertSessionHasErrors('writing_completion_percent');
    }

    /** A dropdown of fixed steps (0/25/50/75/100), not a free-typed number. */
    public function test_percentage_of_completion_must_be_one_of_the_fixed_steps(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $this->candidacyFor($student);

        $this->actingAs($student)
            ->post(route('candidacy-appeal.store'), $this->validPayload($supervisor, ['writing_completion_percent' => 60]))
            ->assertSessionHasErrors('writing_completion_percent');

        $this->assertSame(0, Application::where('module_type', 'candidacy_appeal')->count());
    }

    public function test_a_completed_rcs_requires_its_date_and_category(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $this->candidacyFor($student);

        $payload = $this->validPayload($supervisor, ['rcs_status' => CandidacyAppealDetail::RCS_COMPLETED]);
        unset($payload['rcs_expected_date']);

        $this->actingAs($student)
            ->post(route('candidacy-appeal.store'), $payload)
            ->assertSessionHasErrors(['rcs_date', 'rcs_category']);
    }

    public function test_requested_months_cannot_exceed_the_remaining_twelve_month_allowance(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $this->candidacyFor($student, ['cumulative_extension_months' => 10]);

        $this->actingAs($student)
            ->post(route('candidacy-appeal.store'), $this->validPayload($supervisor, ['requested_extension_months' => 3]))
            ->assertSessionHasErrors('requested_extension_months');
    }

    public function test_a_returned_appeal_can_be_resubmitted_with_updated_section_data(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $this->candidacyFor($student);

        $this->actingAs($student)->post(route('candidacy-appeal.store'), $this->validPayload($supervisor));
        $application = Application::where('module_type', 'candidacy_appeal')->sole();

        app(\App\Modules\Core\Services\WorkflowEngine::class)
            ->decide($application, $supervisor, 'return', 'Please clarify RCS status.');

        $this->assertSame(Application::STATUS_RETURNED, $application->fresh()->status);

        $this->actingAs($student)->post(route('candidacy-appeal.resubmit', $application), $this->validPayload($supervisor, [
            'rcs_status' => CandidacyAppealDetail::RCS_COMPLETED,
            'rcs_date' => now()->subMonth()->format('Y-m-d'),
            'rcs_category' => 'Category A',
            'publications' => ['Paper one', 'Paper two'],
        ]))->assertRedirect(route('applications.index'));

        $application->refresh();
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('supervisor', $application->current_stage);

        $detail = CandidacyAppealDetail::where('application_id', $application->id)->sole();
        $this->assertSame('completed', $detail->rcs_status);
        $this->assertSame('Category A', $detail->rcs_category);
        $this->assertCount(2, $detail->publications);
    }

    /**
     * Return and Reject are different outcomes, not two names for the same
     * thing: Return reopens the same application for editing; Reject is
     * final, at ANY stage (not only the Dean's) -- it permanently blocks
     * every future appeal for that candidacy, even with allowance left.
     */
    public function test_a_rejection_at_a_non_dean_stage_permanently_blocks_further_appeals(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $candidacy = $this->candidacyFor($student);

        $this->actingAs($student)->post(route('candidacy-appeal.store'), $this->validPayload($supervisor));
        $application = Application::where('module_type', 'candidacy_appeal')->sole();

        // Through the real route, not WorkflowEngine directly -- the
        // rejection side effect (last_rejection_at) lives in the
        // controller's decide(), same as the Dean-approval side effect.
        $this->actingAs($supervisor)->post(route('candidacy-appeal.decide', $application), [
            'decision' => 'reject',
            'remarks' => 'Not eligible for this route.',
        ]);

        $candidacy->refresh();
        $this->assertSame(Application::STATUS_REJECTED, $application->fresh()->status);
        $this->assertNotNull($candidacy->last_rejection_at);
        $this->assertFalse($candidacy->canAppeal());

        // Even with the full 12-month allowance still available, a second
        // appeal is refused.
        $this->assertSame(12, $candidacy->remainingAppealMonths());

        $this->actingAs($student)
            ->get(route('candidacy-appeal.create'))
            ->assertRedirect(route('candidacy.status'));
    }

    public function test_a_returned_appeal_does_not_block_further_appeals(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $candidacy = $this->candidacyFor($student);

        $this->actingAs($student)->post(route('candidacy-appeal.store'), $this->validPayload($supervisor));
        $application = Application::where('module_type', 'candidacy_appeal')->sole();

        app(\App\Modules\Core\Services\WorkflowEngine::class)
            ->decide($application, $supervisor, 'return', 'Please add your RCS category.');

        $this->assertNull($candidacy->fresh()->last_rejection_at);
        $this->assertTrue($candidacy->fresh()->canAppeal());
    }

    /**
     * "Track My Applications" (the sidebar link, Core's applications.index)
     * and "View" from My Candidacy's My Appeals table (applications.show)
     * are two doors onto the same detail -- CandidacyAppealWorkflow's
     * detailView() renders on both via SuppliesApplicationDetail, so a
     * candidacy appeal reads identically whichever one a student used.
     */
    public function test_track_my_applications_shows_the_same_detail_as_view_does(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $this->candidacyFor($student);

        $this->actingAs($student)->post(route('candidacy-appeal.store'), $this->validPayload($supervisor));
        $application = Application::where('module_type', 'candidacy_appeal')->sole();

        $this->actingAs($student)
            ->get(route('applications.index'))
            ->assertOk()
            ->assertSee('Section A')
            ->assertSee('Section D')
            ->assertSee(route('candidacy.status'), false);
    }

    /**
     * "Track My Application" (Core's generic applications.show) is the one
     * place a student views any application's full detail now -- Chloe no
     * longer maintains a separate candidacy-appeal.show page for this, see
     * CandidacyAppealWorkflow::detailView() and
     * ApplicationTrackingController::show().
     */
    public function test_the_student_can_always_view_their_own_appeal_regardless_of_status(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $this->candidacyFor($student);

        $this->actingAs($student)->post(route('candidacy-appeal.store'), $this->validPayload($supervisor));
        $application = Application::where('module_type', 'candidacy_appeal')->sole();

        // Pending: viewable, but not yet decided.
        $this->actingAs($student)
            ->get(route('applications.show', $application))
            ->assertOk()
            ->assertSee('Section A');

        // Rejected: still viewable, now showing why it's final.
        app(\App\Modules\Core\Services\WorkflowEngine::class)->decide($application, $supervisor, 'reject', 'No.');

        $this->actingAs($student)
            ->get(route('applications.show', $application))
            ->assertOk()
            ->assertSee('no further appeals can be submitted');
    }

    public function test_a_student_cannot_view_another_students_appeal(): void
    {
        $owner = $this->user(Role::STUDENT, 'owner@test.my');
        $intruder = $this->user(Role::STUDENT, 'intruder@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $this->candidacyFor($owner);

        $this->actingAs($owner)->post(route('candidacy-appeal.store'), $this->validPayload($supervisor));
        $application = Application::where('module_type', 'candidacy_appeal')->sole();

        $this->actingAs($intruder)
            ->get(route('applications.show', $application))
            ->assertForbidden();
    }

    public function test_track_my_application_shows_the_edit_and_resubmit_action_when_returned(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $this->candidacyFor($student);

        $this->actingAs($student)->post(route('candidacy-appeal.store'), $this->validPayload($supervisor));
        $application = Application::where('module_type', 'candidacy_appeal')->sole();

        app(\App\Modules\Core\Services\WorkflowEngine::class)
            ->decide($application, $supervisor, 'return', 'Please add your RCS category.');

        $this->actingAs($student)
            ->get(route('applications.show', $application))
            ->assertOk()
            ->assertSee('action needed')
            ->assertSee('Please add your RCS category.')
            ->assertSee(route('candidacy-appeal.edit', $application), false);
    }

    public function test_editing_is_blocked_once_rejected_even_though_it_was_allowed_after_return(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $this->candidacyFor($student);

        $this->actingAs($student)->post(route('candidacy-appeal.store'), $this->validPayload($supervisor));
        $application = Application::where('module_type', 'candidacy_appeal')->sole();

        app(\App\Modules\Core\Services\WorkflowEngine::class)->decide($application, $supervisor, 'reject', 'No.');

        $this->actingAs($student)
            ->get(route('candidacy-appeal.edit', $application))
            ->assertForbidden();
    }

    /**
     * A Dean rejection is the same terminal outcome as a rejection anywhere
     * else in the chain -- covered generically above, but the Dean is the
     * chain's LAST stage and the one most likely to be confused with
     * "final approval decides everything," so this walks the real chain
     * all the way there rather than rejecting from stage one.
     */
    public function test_a_dean_rejection_specifically_blocks_editing_resubmission_and_new_appeals(): void
    {
        $student = $this->user(Role::STUDENT, 'student@test.my');
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $chair = $this->user(Role::CHAIR, 'chair@test.my');
        $cgs = $this->user(Role::NON_EXEC_CGS, 'cgs@test.my');
        $dean = $this->user(Role::DEAN_PGR, 'dean@test.my');
        $candidacy = $this->candidacyFor($student);

        $this->actingAs($student)->post(route('candidacy-appeal.store'), $this->validPayload($supervisor));
        $application = Application::where('module_type', 'candidacy_appeal')->sole();

        // Through the real decide route at every stage, not WorkflowEngine
        // directly -- the Dean's rejection side effect (last_rejection_at)
        // lives in the controller, same as the Dean's approval side effect.
        $this->actingAs($supervisor)->post(route('candidacy-appeal.decide', $application), ['decision' => 'approve']);
        $this->actingAs($chair)->post(route('candidacy-appeal.decide', $application), ['decision' => 'approve']);
        $this->actingAs($cgs)->post(route('candidacy-appeal.decide', $application), ['decision' => 'approve']);
        $this->actingAs($dean)->post(route('candidacy-appeal.decide', $application), [
            'decision' => 'reject',
            'remarks' => 'Insufficient progress to justify an extension.',
        ]);

        $application->refresh();
        $this->assertSame(Application::STATUS_REJECTED, $application->status);

        // Not editable, not resubmittable -- reject is not return.
        $this->actingAs($student)
            ->get(route('candidacy-appeal.edit', $application))
            ->assertForbidden();
        $this->actingAs($student)
            ->post(route('candidacy-appeal.resubmit', $application), $this->validPayload($supervisor))
            ->assertForbidden();

        // The candidacy itself is permanently blocked from a new appeal,
        // even though the 12-month allowance is untouched (no Dean
        // approval ever happened to spend any of it).
        $candidacy->refresh();
        $this->assertNotNull($candidacy->last_rejection_at);
        $this->assertFalse($candidacy->canAppeal());
        $this->assertSame(12, $candidacy->remainingAppealMonths());

        $this->actingAs($student)
            ->get(route('candidacy-appeal.create'))
            ->assertRedirect(route('candidacy.status'));
        $this->actingAs($student)
            ->post(route('candidacy-appeal.store'), $this->validPayload($supervisor))
            ->assertForbidden();

        // "Track My Application" shows it as final, with no edit action.
        $this->actingAs($student)
            ->get(route('applications.show', $application))
            ->assertOk()
            ->assertSee('no further appeals can be submitted')
            ->assertDontSee('Edit and Resubmit');
    }

    /**
     * On Dean approval the system generates UTP's official "Appeal for
     * Extension of Candidacy" memo as a PDF, archives it the same way an
     * upload would be, and emails it to the student as an attachment --
     * see CandidacyAppealController::generateApprovalMemo() and
     * CandidacyAppealApproved.
     */
    public function test_dean_approval_generates_the_memo_and_emails_it_to_the_student(): void
    {
        Notification::fake();

        $student = $this->user(Role::STUDENT, 'student@test.my');
        $student->update(['matric_no' => '22001234']);
        $supervisor = $this->user(Role::SUPERVISOR, 'supervisor@test.my');
        $chair = $this->user(Role::CHAIR, 'chair@test.my');
        $cgs = $this->user(Role::NON_EXEC_CGS, 'cgs@test.my');
        $dean = $this->user(Role::DEAN_PGR, 'dean@test.my');
        $this->candidacyFor($student);

        $this->actingAs($student)->post(route('candidacy-appeal.store'), $this->validPayload($supervisor));
        $application = Application::where('module_type', 'candidacy_appeal')->sole();

        $this->actingAs($supervisor)->post(route('candidacy-appeal.decide', $application), ['decision' => 'approve']);
        $this->actingAs($chair)->post(route('candidacy-appeal.decide', $application), ['decision' => 'approve']);
        $this->actingAs($cgs)->post(route('candidacy-appeal.decide', $application), ['decision' => 'approve']);
        $this->actingAs($dean)->post(route('candidacy-appeal.decide', $application), ['decision' => 'approve']);

        $application->refresh();
        $this->assertSame(Application::STATUS_APPROVED, $application->status);

        // Generated and archived as a real document behind the authorised
        // download route, not a loose file...
        $memo = $application->documents()->sole();
        $this->assertSame('Appeal for Extension of Candidacy Memo', $memo->doc_type);
        $this->assertSame('Student_22001234_Memo - Appeal for Extension Candidacy.pdf', $memo->original_name);

        // ...and actually emailed, which is the attachment the brief asked for.
        Notification::assertSentTo($student, CandidacyAppealApproved::class);
    }
}
