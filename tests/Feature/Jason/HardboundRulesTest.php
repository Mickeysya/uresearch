<?php

namespace Tests\Feature\Jason;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Jason\Models\HardboundAppealDetail;
use App\Modules\Jason\Models\HardboundSubmissionDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The three rules in this module that fail quietly.
 *
 * `FormsTest` covers how his screens render. These cover what they enforce:
 * the signature gate on approval, the resubmit guard, and the appeal's
 * once-only rule. All three are the kind that break without anything on
 * screen looking wrong -- an unsigned Confirmation goes out, a student
 * resubmits twice, or a rejected submission is appealed over and over.
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

    public function test_a_submission_may_be_appealed_once_and_not_after_it_has_been_replaced(): void
    {
        Storage::fake('local');

        $student = $this->student();
        $returned = $this->rejectedSubmission($student);

        $payload = [
            'hardbound_application_id' => $returned->id,
            'justification' => 'The corrections CGS listed were completed before the deadline and the letter shows it.',
            'appeal_memo' => UploadedFile::fake()->create('appeal-memo.pdf', 80, 'application/pdf'),
        ];

        $this->actingAs($student)
            ->post(route('hardbound-appeal.store'), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(1, HardboundAppealDetail::where('hardbound_application_id', $returned->id)->count());

        // Twice is not allowed, and the rule is in the validator rather than
        // only in the picker, so posting the id directly cannot get round it.
        $this->actingAs($student)
            ->post(route('hardbound-appeal.store'), $payload + [
                'appeal_memo' => UploadedFile::fake()->create('appeal-memo-2.pdf', 80, 'application/pdf'),
            ])
            ->assertSessionHasErrors('hardbound_application_id');

        $this->assertSame(1, HardboundAppealDetail::where('hardbound_application_id', $returned->id)->count());

        // And a submission the student has already replaced is spent too:
        // they took the other route, so there is nothing left to appeal.
        $replaced = $this->rejectedSubmission($student);

        $this->actingAs($student)
            ->post(route('hardbound.resubmit', $replaced), $this->resubmissionPayload())
            ->assertSessionHasNoErrors();

        $this->actingAs($student)
            ->post(route('hardbound-appeal.store'), [
                'hardbound_application_id' => $replaced->id,
                'justification' => 'Changed my mind about resubmitting and would like a ruling instead.',
                'appeal_memo' => UploadedFile::fake()->create('appeal-memo-3.pdf', 80, 'application/pdf'),
            ])
            ->assertSessionHasErrors('hardbound_application_id');

        $this->assertSame(0, HardboundAppealDetail::where('hardbound_application_id', $replaced->id)->count());
    }
}
