<?php

namespace Tests\Feature\Jason;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Jason\Models\HardboundAppealDetail;
use App\Modules\Jason\Models\HardboundSubmissionDetail;
use App\Modules\Jason\Notifications\ExtensionMemoReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The three rules in this module that fail quietly.
 *
 * `FormsTest` covers how his screens render. These cover what they enforce:
 * the signature gate on approval, the resubmit guard, and what the appeal
 * chain enforces. All of them are the kind that break without anything on
 * screen looking wrong -- an unsigned Confirmation or memo goes out, a
 * student resubmits twice, or CGS receiving an extension memo is announced
 * to the candidate as the extension being granted.
 */
class HardboundRulesTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    /** A submitted hardbound submission, sitting on the Supervisor's stage. */
    protected function submission(User $student): Application
    {
        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'hardbound_submission',
            'status' => Application::STATUS_DRAFT,
        ]);

        $this->detailFor($application, $student);

        return app(WorkflowEngine::class)->submit($application);
    }

    /** The same, already returned to the student. */
    protected function rejectedSubmission(User $student): Application
    {
        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'hardbound_submission',
            'status' => Application::STATUS_REJECTED,
            'current_stage' => 'cgs_review',
        ]);

        $this->detailFor($application, $student);

        return $application;
    }

    protected function detailFor(Application $application, User $student): HardboundSubmissionDetail
    {
        return HardboundSubmissionDetail::create([
            'application_id' => $application->id,
            'thesis_title' => 'A Study of Something',
            'matric_no' => $student->matric_no,
            'programme' => 'PhD Full-Time',
            'supervisor_name' => 'Dr. Aisyah Rahman',
            'viva_date' => now()->subMonth(),
        ]);
    }

    /** What the resubmission form posts. */
    protected function resubmissionPayload(array $overrides = []): array
    {
        return $overrides + [
            'thesis_title' => 'A Study of Something (corrected)',
            'programme' => 'PhD Full-Time',
            'supervisor_name' => 'Dr. Aisyah Rahman',
            'viva_date' => now()->subMonth()->toDateString(),
            'response_to_comments' => 'Corrected the pagination and the reference list as requested.',
        ];
    }

    /* ---------------- the signature gate ---------------- */

    /**
     * Approving at `supervisor` or `chair` stamps that approver's signature
     * onto the Confirmation of Correction to Thesis, so there has to be one
     * to stamp. Without the gate the approval still goes through and the
     * Confirmation is archived with an empty signature block -- a form that
     * looks issued and is not signed.
     */
    public function test_an_approver_without_a_signature_cannot_approve_but_can_reject(): void
    {
        Storage::fake('local');

        $student = $this->student();
        $supervisor = $this->supervisor();
        $application = $this->submission($student);

        $this->actingAs($supervisor)
            ->post(route('hardbound.decide', $application), ['decision' => 'approve'])
            ->assertRedirect(route('hardbound.signature'))
            ->assertSessionHas('error');

        // Nothing moved and nothing was written to the trail.
        $this->assertSame('supervisor', $application->fresh()->current_stage);
        $this->assertSame(Application::STATUS_PENDING, $application->fresh()->status);
        $this->assertSame(0, ApprovalHistory::where('application_id', $application->id)->count());

        // Rejecting signs nothing, so it is not gated -- a supervisor who has
        // not uploaded a signature can still return a submission.
        $other = $this->submission($this->student('22001099', 'student9@test.my'));

        $this->actingAs($supervisor)
            ->post(route('hardbound.decide', $other), [
                'decision' => 'reject',
                'remarks' => 'The corrections in chapter four are not the ones the panel asked for.',
            ])
            ->assertRedirect();

        $this->assertSame(Application::STATUS_REJECTED, $other->fresh()->status);

        // With a signature on file the same approval goes through.
        $this->actingAs($supervisor)
            ->post(route('hardbound.signature.store'), [
                'signature' => UploadedFile::fake()->image('signature.png', 300, 100),
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($supervisor)
            ->post(route('hardbound.decide', $application), ['decision' => 'approve'])
            ->assertRedirect();

        $this->assertSame('chair', $application->fresh()->current_stage);
    }

    /* ---------------- the resubmit guard ---------------- */

    public function test_only_the_owner_of_a_returned_submission_may_resubmit_it_and_only_once(): void
    {
        Storage::fake('local');

        $student = $this->student();
        $returned = $this->rejectedSubmission($student);

        // Somebody else's returned submission is not yours to replace.
        $intruder = $this->student('22001098', 'student8@test.my');
        $this->actingAs($intruder)->get(route('hardbound.resubmit.form', $returned))->assertForbidden();

        // Neither is one that is still making its way through the chain.
        $open = $this->submission($student);
        $this->actingAs($student)->get(route('hardbound.resubmit.form', $open))->assertForbidden();

        $this->actingAs($student)->get(route('hardbound.resubmit.form', $returned))->assertOk();

        $this->actingAs($student)
            ->post(route('hardbound.resubmit', $returned), $this->resubmissionPayload())
            ->assertSessionHasNoErrors();

        // The replacement points back at what it replaces, which is what makes
        // "once, per submission" enforceable at all.
        $replacement = HardboundSubmissionDetail::where('resubmission_of_id', $returned->id)->sole();
        $this->assertNotSame($returned->id, $replacement->application_id);

        // Second time round the same returned submission is spent, and it
        // says so in those words -- the generic backstop below it would also
        // return 403, so asserting only the status would not notice the
        // specific guard going missing.
        $this->actingAs($student)
            ->get(route('hardbound.resubmit.form', $returned))
            ->assertForbidden()
            ->assertSee('You have already resubmitted this submission.');
    }

    /**
     * The response to CGS's comments is the point of a resubmission -- an
     * empty one gives the reviewer the same document back with nothing to
     * read, so it is required here and optional on a first submission.
     */
    public function test_a_resubmission_must_say_what_changed(): void
    {
        Storage::fake('local');

        $student = $this->student();
        $returned = $this->rejectedSubmission($student);

        $this->actingAs($student)
            ->post(route('hardbound.resubmit', $returned), $this->resubmissionPayload(['response_to_comments' => '']))
            ->assertSessionHasErrors('response_to_comments');

        $this->assertSame(0, HardboundSubmissionDetail::whereNotNull('resubmission_of_id')->count());
    }

    /* ---------------- the appeal's once-only rule ---------------- */

    /* ---------------- the extension appeal ---------------- */

    /**
     * An appeal is a request to extend the hardbound submission deadline,
     * filed by a candidate who has NOT submitted. Two of them moving at once
     * would reach CGS as two appeals for the same deadline, so the second is
     * refused -- and refused in the controller, not only by hiding the form,
     * because a posted form does not go through the page.
     */
    public function test_only_one_appeal_may_be_moving_at_a_time(): void
    {
        Storage::fake('local');

        $student = $this->student();
        $this->supervisor();

        $this->actingAs($student)
            ->post(route('hardbound-appeal.store'), $this->appealPayload())
            ->assertSessionHasNoErrors();

        $this->assertSame(1, HardboundAppealDetail::count());

        $this->actingAs($student)
            ->post(route('hardbound-appeal.store'), $this->appealPayload())
            ->assertSessionHas('error');

        $this->assertSame(1, HardboundAppealDetail::count());
    }

    /**
     * The memo is written by the portal, not uploaded, so filing an appeal
     * has to produce one -- the Supervisor opens a memo rather than a form.
     */
    public function test_filing_an_appeal_writes_the_memo(): void
    {
        Storage::fake('local');

        $student = $this->student();
        $this->supervisor();

        $this->actingAs($student)
            ->post(route('hardbound-appeal.store'), $this->appealPayload())
            ->assertSessionHasNoErrors();

        $appeal = Application::where('module_type', 'hardbound_appeal')->firstOrFail();

        $memo = $appeal->documents()->where('doc_type', 'Appeal Memo')->firstOrFail();

        $this->assertSame("Appeal-Memo-{$appeal->id}.pdf", $memo->original_name);
        Storage::disk('local')->assertExists($memo->path);
    }

    /**
     * Endorsing stamps the endorser's signature onto the memo, so there has
     * to be one -- the same gate the Confirmation has, for the same reason.
     * The re-issued memo replaces the previous copy, so the appeal carries
     * exactly one current version rather than a pile of drafts.
     */
    public function test_endorsing_an_appeal_needs_a_signature_and_re_issues_the_memo(): void
    {
        Storage::fake('local');

        $student = $this->student();
        $supervisor = $this->supervisor();

        $this->actingAs($student)->post(route('hardbound-appeal.store'), $this->appealPayload());
        $appeal = Application::where('module_type', 'hardbound_appeal')->firstOrFail();

        // No signature on file: bounced to the upload page, nothing decided.
        $this->actingAs($supervisor)
            ->post(route('hardbound-appeal.decide', $appeal), ['decision' => 'approve'])
            ->assertRedirect(route('hardbound.signature'));

        $this->assertSame('supervisor', $appeal->fresh()->current_stage);

        $this->signatureFor($supervisor);

        $this->actingAs($supervisor)
            ->post(route('hardbound-appeal.decide', $appeal), ['decision' => 'approve'])
            ->assertSessionHas('status');

        $this->assertSame('chair', $appeal->fresh()->current_stage);
        $this->assertSame(1, $appeal->documents()->where('doc_type', 'Appeal Memo')->count());
    }

    /**
     * Refusing ends the appeal, so the candidate has to be told why. The
     * shared decision form calls remarks optional, so without this the
     * refusal goes through silently and they are left guessing.
     */
    public function test_refusing_an_appeal_needs_remarks(): void
    {
        Storage::fake('local');

        $student = $this->student();
        $supervisor = $this->supervisor();
        $this->signatureFor($supervisor);

        $this->actingAs($student)->post(route('hardbound-appeal.store'), $this->appealPayload());
        $appeal = Application::where('module_type', 'hardbound_appeal')->firstOrFail();

        $this->actingAs($supervisor)
            ->post(route('hardbound-appeal.decide', $appeal), ['decision' => 'reject'])
            ->assertSessionHas('error');

        $this->assertSame(Application::STATUS_PENDING, $appeal->fresh()->status);

        $this->actingAs($supervisor)
            ->post(route('hardbound-appeal.decide', $appeal), [
                'decision' => 'reject',
                'remarks' => 'Your corrections are nearly done; submit on time instead.',
            ])
            ->assertSessionHas('status');

        $this->assertSame(Application::STATUS_REJECTED, $appeal->fresh()->status);
    }

    /**
     * CGS receiving the memo is NOT the extension being granted: the Dean
     * signs the memo off-portal and CGS emails the outcome. The engine's own
     * notice would tell the candidate their application is "fully approved",
     * so the module sends its own saying what actually happened.
     */
    public function test_cgs_receiving_the_memo_tells_the_candidate_to_wait(): void
    {
        Storage::fake('local');
        Notification::fake();

        $student = $this->student();
        $supervisor = $this->supervisor();
        $chair = $this->chair();
        $cgs = $this->cgs();
        $this->signatureFor($supervisor);
        $this->signatureFor($chair);

        $this->actingAs($student)->post(route('hardbound-appeal.store'), $this->appealPayload());
        $appeal = Application::where('module_type', 'hardbound_appeal')->firstOrFail();

        $this->actingAs($supervisor)->post(route('hardbound-appeal.decide', $appeal), ['decision' => 'approve']);
        $this->actingAs($chair)->post(route('hardbound-appeal.decide', $appeal), ['decision' => 'approve']);

        // Both endorsements are on the one current memo.
        $this->assertSame(1, $appeal->documents()->where('doc_type', 'Appeal Memo')->count());

        $this->actingAs($cgs)
            ->post(route('hardbound-appeal.decide', $appeal), ['decision' => 'approve'])
            ->assertSessionHas('status');

        Notification::assertSentTo($student, ExtensionMemoReceived::class);
    }

    /** Puts a signature on file for an approver, the way they would. */
    protected function signatureFor(User $approver): void
    {
        $this->actingAs($approver)
            ->post(route('hardbound.signature.store'), [
                'signature' => UploadedFile::fake()->image('signature.png', 300, 100),
            ])
            ->assertSessionHasNoErrors();
    }

    /** What the appeal form posts. */
    protected function appealPayload(array $overrides = []): array
    {
        return $overrides + [
            'reason' => "My experimental rig failed in the final month and the replacement parts "
                ."took six weeks to arrive.\n\nThe remaining chapters are drafted.",
            'original_deadline' => now()->addWeeks(2)->toDateString(),
            'requested_until' => now()->addMonths(3)->toDateString(),
        ];
    }
}
