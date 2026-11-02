<?php

namespace Tests\Feature\Jason;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Jason\Mail\ExaminerSignatureRequest;
use App\Modules\Jason\Models\AppointmentExaminer;
use App\Modules\Jason\Models\HardboundAppealDetail;
use App\Modules\Jason\Models\HardboundExaminerSignature;
use App\Modules\Jason\Models\HardboundSubmissionDetail;
use App\Modules\Jason\Notifications\ExtensionMemoReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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
    /**
     * A candidate with a signature on file.
     *
     * UTP/CGS/021 is written by the portal and signed with it, so filing or
     * resubmitting without one is refused -- see mustHaveSignature().
     */
    protected function signedUpCandidate(string $matric = '22001001', string $email = 'student@test.my'): User
    {
        $student = $this->student($matric, $email);
        $this->signatureFor($student);

        return $student;
    }

    /**
     * An examiner on record for the candidate to name.
     *
     * The viva happens before the hardbound submission, so the candidate
     * names who examined them; the list is the nomination chain's own.
     */
    protected function examinerOnRecord(string $type = 'internal', string $name = 'Dr. Internal Examiner'): int
    {
        return DB::table('examiners')->insertGetId([
            'name' => $name,
            'email' => str_replace(' ', '.', strtolower($name)).'@test.my',
            'department' => 'Computing',
            'faculty' => 'FSMC',
            'institution' => $type === 'external' ? 'Another University' : null,
            'type' => $type,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** What the submission form posts, now that nothing is uploaded. */
    protected function submissionPayload(array $overrides = []): array
    {
        return $overrides + [
            'internal_examiner_id' => $this->examinerOnRecord(),
            'thesis_title' => 'A Study of Something',
            'programme' => 'PhD Full-Time',
            'supervisor_name' => 'Dr. Aisyah Rahman',
            'country' => 'MYS',
            'intake' => 'SEP23',
            'passport_no' => 'A12345678',
            'date_of_birth' => '2000-04-17',
            'viva_date' => now()->subMonth()->toDateString(),
        ];
    }

    protected function resubmissionPayload(array $overrides = []): array
    {
        return $overrides + [
            'internal_examiner_id' => $this->examinerOnRecord(),
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

    /* ---------------- the examiner's signature ---------------- */

    /**
     * The Confirmation has three signatories, and the examiner is the one
     * without an account. The candidate names them, they are asked the
     * moment the supervisor confirms the corrections, and the Chairman
     * cannot certify until they have signed — the examiner who asked for
     * the corrections agrees before the viva chairman does.
     */
    public function test_the_chair_cannot_certify_until_the_named_examiner_has_signed(): void
    {
        Storage::fake('local');
        Mail::fake();

        $student = $this->signedUpCandidate();
        $supervisor = $this->supervisor();
        $chair = $this->chair();
        $this->signatureFor($supervisor);
        $this->signatureFor($chair);

        $this->actingAs($student)
            ->post(route('hardbound.store'), $this->submissionPayload())
            ->assertSessionHasNoErrors();

        $application = Application::where('module_type', 'hardbound_submission')->firstOrFail();

        $this->actingAs($supervisor)->post(route('hardbound.decide', $application), ['decision' => 'approve']);

        $this->assertSame('chair', $application->fresh()->current_stage);

        // Asked the moment the supervisor confirmed, not after the chairman.
        Mail::assertQueued(ExaminerSignatureRequest::class);

        $this->actingAs($chair)
            ->post(route('hardbound.decide', $application), ['decision' => 'approve'])
            ->assertSessionHas('error');

        $this->assertSame('chair', $application->fresh()->current_stage);

        $this->post($this->signingUrl($application, 'internal', 'hardbound.examiner.sign.store'), [
            'signature' => UploadedFile::fake()->image('examiner.png', 300, 100),
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(HardboundExaminerSignature::forApplication($application->id));

        $this->actingAs($chair)
            ->post(route('hardbound.decide', $application), ['decision' => 'approve'])
            ->assertSessionHas('status');

        $this->assertSame('cgs_review', $application->fresh()->current_stage);
    }

    /**
     * The signed URL is the only credential an examiner has, so it has to be
     * the whole credential: an unsigned link is refused outright, and a link
     * issued for one examiner cannot sign as another.
     */
    public function test_a_signing_link_is_refused_without_its_signature(): void
    {
        Storage::fake('local');

        $application = $this->atExaminerStage();

        // No signature on the URL at all.
        $this->get(route('hardbound.examiner.sign', [
            'application' => $application->id, 'slot' => 'internal',
        ]))->assertForbidden();

        // Properly signed, and it opens.
        $this->get($this->signingUrl($application, 'internal'))
            ->assertOk()
            ->assertSee('Dr. Internal Examiner');
    }

    /**
     * Rejecting signs nothing, and a submission still with its supervisor
     * has not reached the examiner. A link that worked once must not keep
     * working after the form has moved on.
     */
    public function test_an_examiner_cannot_sign_a_form_that_is_not_waiting_for_them(): void
    {
        Storage::fake('local');

        $student = $this->signedUpCandidate();

        // Still on the supervisor's desk, so nothing to countersign.
        $this->actingAs($student)->post(route('hardbound.store'), $this->submissionPayload());
        $application = Application::where('module_type', 'hardbound_submission')->firstOrFail();

        $this->get($this->signingUrl($application, 'internal'))->assertForbidden();
    }

    /**
     * A submission that names nobody cannot be waiting on anybody, so the
     * gate does not apply and the chain runs straight through.
     */
    public function test_a_submission_naming_no_examiner_is_not_blocked(): void
    {
        Storage::fake('local');

        $student = $this->student();
        $supervisor = $this->supervisor();
        $chair = $this->chair();
        $cgs = $this->cgs();
        $this->signatureFor($supervisor);
        $this->signatureFor($chair);

        $application = $this->submission($student);

        $this->actingAs($supervisor)->post(route('hardbound.decide', $application), ['decision' => 'approve']);
        $this->actingAs($chair)->post(route('hardbound.decide', $application), ['decision' => 'approve']);

        $this->actingAs($cgs)
            ->post(route('hardbound.decide', $application), ['decision' => 'approve'])
            ->assertSessionHas('status');

        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
    }

    /** A submission sitting where the examiner is expected to countersign. */
    protected function atExaminerStage(): Application
    {
        $student = $this->signedUpCandidate();
        $supervisor = $this->supervisor();
        $this->signatureFor($supervisor);

        $this->actingAs($student)->post(route('hardbound.store'), $this->submissionPayload());
        $application = Application::where('module_type', 'hardbound_submission')->firstOrFail();

        $this->actingAs($supervisor)->post(route('hardbound.decide', $application), ['decision' => 'approve']);

        return $application->fresh();
    }

    protected function signingUrl(Application $application, string $slot, string $route = 'hardbound.examiner.sign'): string
    {
        return URL::temporarySignedRoute($route, now()->addDay(), [
            'application' => $application->id,
            'slot' => $slot,
        ]);
    }

    /**
     * Both generated forms are read far more often than they are filed
     * away, so they open in the browser rather than landing in somebody's
     * downloads folder every time a detail is checked. The file is still
     * one query parameter away.
     */
    public function test_the_generated_forms_open_in_the_browser_not_the_downloads_folder(): void
    {
        Storage::fake('local');

        $student = $this->signedUpCandidate();
        $this->supervisor();

        $this->actingAs($student)
            ->post(route('hardbound.store'), $this->submissionPayload())
            ->assertSessionHasNoErrors();

        $application = Application::where('module_type', 'hardbound_submission')->firstOrFail();

        $this->actingAs($student)
            ->get(route('hardbound.submission-form', $application))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition',
                'inline; filename="Hardbound-Thesis-Submission-'.$application->id.'.pdf"');

        $this->actingAs($student)
            ->get(route('hardbound.submission-form', [$application, 'download' => 1]))
            ->assertOk()
            ->assertHeader('content-disposition',
                'attachment; filename="Hardbound-Thesis-Submission-'.$application->id.'.pdf"');
    }

    /**
     * A page to read them on, so nothing has to be downloaded to be
     * checked. Open to the candidate and to the roles holding a stage.
     */
    public function test_the_forms_page_frames_the_submission_form(): void
    {
        Storage::fake('local');

        $student = $this->signedUpCandidate();
        $supervisor = $this->supervisor();

        $this->actingAs($student)->post(route('hardbound.store'), $this->submissionPayload());
        $application = Application::where('module_type', 'hardbound_submission')->firstOrFail();

        foreach ([$student, $supervisor] as $reader) {
            $this->actingAs($reader)
                ->get(route('hardbound.forms', $application))
                ->assertOk()
                ->assertSee('UTP/CGS/021')
                ->assertSee(route('hardbound.submission-form', $application), false);
        }

        $this->actingAs($this->student('22009090', 'nosy@test.my'))
            ->get(route('hardbound.forms', $application))
            ->assertForbidden();
    }

    /**
     * Opening an archived document from this module must leave the same
     * trace Core's own download route leaves. Without that it would be a
     * second way to read the same file that nothing records -- the specific
     * gap the audit log was added to close.
     */
    public function test_reading_an_archived_document_inline_is_still_audited(): void
    {
        Storage::fake('local');

        $student = $this->signedUpCandidate();
        $this->supervisor();

        $this->actingAs($student)->post(route('hardbound.store'), $this->submissionPayload());
        $application = Application::where('module_type', 'hardbound_submission')->firstOrFail();

        $confirmation = $application->documents()
            ->where('doc_type', 'Confirmation of Correction to Thesis')
            ->firstOrFail();

        $before = DB::table('activity_log')->where('description', 'Viewed document')->count();

        $this->actingAs($student)
            ->get(route('hardbound.document', [$application, $confirmation]))
            ->assertOk()
            ->assertHeader('content-disposition', 'inline; filename="'.$confirmation->original_name.'"');

        $this->assertSame(
            $before + 1,
            DB::table('activity_log')->where('description', 'Viewed document')->count(),
        );
    }

    /* ---------------- the resubmit guard ---------------- */

    public function test_only_the_owner_of_a_returned_submission_may_resubmit_it_and_only_once(): void
    {
        Storage::fake('local');

        $student = $this->signedUpCandidate();
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

        $student = $this->signedUpCandidate();
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

        $student = $this->signedUpStudent();
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
     * An appeal stranded on a stage this module no longer declares -- every
     * appeal filed under the old meaning of this module was, and anything
     * would be after a future stage rename -- can never be decided, because
     * no queue can reach it. Counting it as the candidate's open appeal
     * locks them out of the module for good.
     */
    public function test_an_appeal_stuck_on_a_stage_that_no_longer_exists_does_not_block_a_new_one(): void
    {
        Storage::fake('local');

        $student = $this->signedUpStudent();
        $this->supervisor();

        Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'hardbound_appeal',
            'status' => Application::STATUS_PENDING,
            // A stage the workflow no longer has.
            'current_stage' => 'cgs_approve',
        ]);

        $this->actingAs($student)
            ->post(route('hardbound-appeal.store'), $this->appealPayload())
            ->assertSessionHasNoErrors();

        $this->assertSame(1, HardboundAppealDetail::count());
    }

    /**
     * The memo is written by the portal and never stored: an appeal is three
     * fields and the signatures already on file, and the document is drawn
     * from them whenever it is read. Nothing archived means nothing to keep,
     * which is the point of writing it this way.
     */
    public function test_an_appeal_stores_no_document_at_all(): void
    {
        Storage::fake('local');

        $student = $this->signedUpStudent();
        $this->supervisor();

        $this->actingAs($student)
            ->post(route('hardbound-appeal.store'), $this->appealPayload())
            ->assertSessionHasNoErrors();

        $appeal = Application::where('module_type', 'hardbound_appeal')->firstOrFail();

        $this->assertSame(0, $appeal->documents()->count());
        $this->assertStringContainsString(
            'experimental rig failed',
            HardboundAppealDetail::where('application_id', $appeal->id)->value('reason'),
        );
    }

    /**
     * Rendered on demand, by the candidate and by the three roles that hold
     * a stage: with nothing in the documents list this is the only way an
     * endorser reads what they are endorsing.
     */
    public function test_the_memo_is_built_on_demand_for_the_candidate_and_the_endorsers(): void
    {
        Storage::fake('local');

        $student = $this->signedUpStudent();
        $supervisor = $this->supervisor();
        $cgs = $this->cgs();

        $this->actingAs($student)->post(route('hardbound-appeal.store'), $this->appealPayload());
        $appeal = Application::where('module_type', 'hardbound_appeal')->firstOrFail();

        foreach ([$student, $supervisor, $cgs] as $reader) {
            $this->actingAs($reader)
                ->get(route('hardbound-appeal.memo', $appeal))
                ->assertOk()
                ->assertHeader('content-type', 'application/pdf');
        }

        // Not somebody else's appeal, though.
        $other = $this->student('22009999', 'other@test.my');

        $this->actingAs($other)->get(route('hardbound-appeal.memo', $appeal))->assertForbidden();
    }

    /**
     * The memo is addressed to the Dean and carries the candidate's own
     * "Yours sincerely" block, so it cannot leave over a blank rule. Gated
     * the same way an endorser's approval is.
     */
    public function test_an_appeal_cannot_be_filed_without_the_candidates_signature(): void
    {
        Storage::fake('local');

        $student = $this->student();
        $this->supervisor();

        $this->actingAs($student)
            ->post(route('hardbound-appeal.store'), $this->appealPayload())
            ->assertRedirect(route('hardbound.signature'));

        $this->assertSame(0, HardboundAppealDetail::count());

        // With one on file it goes through, and the memo carries it.
        $this->signatureFor($student);

        $this->actingAs($student)
            ->post(route('hardbound-appeal.store'), $this->appealPayload())
            ->assertSessionHasNoErrors();

        $this->assertSame(1, HardboundAppealDetail::count());
    }

    /** An appeal with no reason on it is a blank memo. */
    public function test_an_appeal_needs_a_reason(): void
    {
        Storage::fake('local');

        $this->actingAs($this->signedUpStudent())
            ->post(route('hardbound-appeal.store'), [
                'requested_until' => now()->addMonths(3)->toDateString(),
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, HardboundAppealDetail::count());
    }

    /**
     * Endorsing stamps the endorser's signature into the memo, so there has
     * to be one to stamp -- the same gate the Confirmation has, for the same
     * reason.
     */
    public function test_endorsing_an_appeal_needs_a_signature(): void
    {
        Storage::fake('local');

        $student = $this->signedUpStudent();
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

        // Still nothing stored: the endorsement is a history row and a
        // signature on file, and the memo draws both when it is opened.
        $this->assertSame(0, $appeal->documents()->count());
    }

    /**
     * Refusing ends the appeal, so the candidate has to be told why. The
     * shared decision form calls remarks optional, so without this the
     * refusal goes through silently and they are left guessing.
     */
    public function test_refusing_an_appeal_needs_remarks(): void
    {
        Storage::fake('local');

        $student = $this->signedUpStudent();
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

        $student = $this->signedUpStudent();
        $supervisor = $this->supervisor();
        $chair = $this->chair();
        $cgs = $this->cgs();
        $this->signatureFor($supervisor);
        $this->signatureFor($chair);

        $this->actingAs($student)->post(route('hardbound-appeal.store'), $this->appealPayload());
        $appeal = Application::where('module_type', 'hardbound_appeal')->firstOrFail();

        $this->actingAs($supervisor)->post(route('hardbound-appeal.decide', $appeal), ['decision' => 'approve']);
        $this->actingAs($chair)->post(route('hardbound-appeal.decide', $appeal), ['decision' => 'approve']);

        // Both endorsements are recorded, and still nothing is stored.
        $this->assertSame(0, $appeal->documents()->count());

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

    /**
     * The candidate's signature has to be on file before an appeal can be
     * filed, so every appeal test puts one there first.
     */
    protected function signedUpStudent(string $matric = '22001001', string $email = 'student@test.my'): User
    {
        $student = $this->student($matric, $email);
        $this->signatureFor($student);

        return $student;
    }

    /** What the appeal form posts: the reason, and the dates. */
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
