<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\AttendanceAppealDetail;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nureen\Concerns\FilesGaExtensions;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Approvers read files without downloading them, and decide in one bar:
 *   - inline preview routes for attached files (DocumentPreviewController)
 *     and the generated documents, under the same authorisation as their
 *     downloads -- the owner and the approvers, 403 for everyone else;
 *   - Word files get "Preview not available", never the file;
 *   - the decision bar on every Nureen queue (components/decision-bar),
 *     and a rejection without a reason refused server-side in all four
 *     modules.
 */
class DocumentPreviewTest extends TestCase
{
    use FilesGaExtensions;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected User $supervisor;

    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();

        $this->supervisor = $this->supervisor();
        $this->student = $this->student(attributes: ['supervisor_id' => $this->supervisor->id]);
        $this->approveGaApplication($this->student);
    }

    /** A certification at the supervisor stage, with one PDF attached. */
    protected function certification(): Application
    {
        $this->actingAs($this->student)->post(route('ga-certification.store'), [
            'appointment_type' => 'GA', 'study_level' => 'research', 'level_of_study' => 'phd', 'mode_of_study' => 'full_time',
            'identity_number' => '900101-08-1234', 'personal_email' => 'me@gmail.test', 'declaration' => '1',
            'period_start' => now()->subYear()->toDateString(), 'period_end' => now()->toDateString(),
            'course_code' => 'CCB2012', 'course_name' => 'Process Control', 'course_owner_name' => 'Dr. A', 'semester' => 'Sept 2025',
            'supporting_documents' => [UploadedFile::fake()->create('evidence.pdf', 20, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        return Application::where('module_type', 'ga_certification')->latest('id')->firstOrFail();
    }

    /** Store a file of $name on $application, as DocumentStore would. */
    protected function attach(Application $application, string $name, string $contents, string $type = 'Supporting Document'): ApplicationDocument
    {
        $path = "applications/{$application->id}/".uniqid().'.'.pathinfo($name, PATHINFO_EXTENSION);
        Storage::disk('local')->put($path, $contents);

        return ApplicationDocument::create([
            'application_id' => $application->id, 'doc_type' => $type, 'original_name' => $name,
            'path' => $path, 'mime_type' => 'application/octet-stream', 'size_bytes' => strlen($contents),
        ]);
    }

    /* -----------------------------------------------------------------
     | Attached files
     |------------------------------------------------------------------*/

    public function test_a_pdf_previews_inline_for_the_owner_and_the_current_approver(): void
    {
        $application = $this->certification();
        $document = $application->documents()->sole();

        foreach ([$this->student, $this->supervisor] as $user) {
            $this->actingAs($user)->get(route('nureen.documents.view', $document))
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('Content-Disposition', 'inline; filename=evidence.pdf');
        }
    }

    public function test_an_image_previews_inline_with_its_own_type(): void
    {
        $application = $this->certification();
        $png = $this->attach($application, 'scan.png', UploadedFile::fake()->image('scan.png', 20, 20)->getContent());

        $response = $this->actingAs($this->supervisor)->get(route('nureen.documents.view', $png))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
    }

    public function test_an_earlier_approver_still_previews_after_deciding(): void
    {
        $application = $this->certification();
        $document = $application->documents()->sole();
        $this->actingAs($this->supervisor)->post(route('ga-certification.decide', $application), ['decision' => 'approve']);

        $this->actingAs($this->supervisor)->get(route('nureen.documents.view', $document))->assertOk();
        $this->actingAs($this->cgs())->get(route('nureen.documents.view', $document))->assertOk();
    }

    public function test_anyone_who_could_not_download_it_cannot_preview_it(): void
    {
        $application = $this->certification();
        $document = $application->documents()->sole();

        foreach ([$this->student('22009999', 'other@test.my'), $this->chair(), $this->seniorDirector()] as $user) {
            $this->actingAs($user)->get(route('nureen.documents.view', $document))->assertForbidden();
            // The rule really is the download's.
            $this->actingAs($user)->get(route('documents.show', $document))->assertForbidden();
        }
    }

    public function test_a_word_file_shows_preview_not_available_and_never_the_file(): void
    {
        $application = $this->certification();
        $docx = $this->attach($application, 'letter.docx', 'PK-fake-docx-bytes');

        $response = $this->actingAs($this->supervisor)->get(route('nureen.documents.view', $docx))
            ->assertOk()
            ->assertSee('Preview not available for Word files.')
            ->assertSee($docx->url(), false);

        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
        $this->assertStringNotContainsString('PK-fake-docx-bytes', $response->getContent());
    }

    public function test_another_modules_documents_are_not_served_here(): void
    {
        $travel = Application::create([
            'student_id' => $this->student->id, 'submitted_by_id' => $this->student->id,
            'module_type' => 'travel', 'status' => Application::STATUS_PENDING, 'current_stage' => 'supervisor', 'submitted_at' => now(),
        ]);
        $document = $this->attach($travel, 'ticket.pdf', '%PDF-1.4');

        $this->actingAs($this->student)->get(route('nureen.documents.view', $document))->assertNotFound();
    }

    /* -----------------------------------------------------------------
     | Generated documents
     |------------------------------------------------------------------*/

    public function test_the_ga_extension_form_previews_inline_for_the_owner_and_approvers_only(): void
    {
        $this->actingAs($this->student)->post(route('ga-extension.store'), $this->gaExtensionFields() + $this->gaExtensionDocuments())->assertSessionHasNoErrors();
        $application = Application::where('module_type', 'ga_extension')->sole();

        foreach ([$this->student, $this->supervisor, $this->cgs()] as $user) {
            $response = $this->actingAs($user)->get(route('ga-extension.form-pdf.view', $application))->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        }

        $this->actingAs($this->student('22009999', 'other@test.my'))->get(route('ga-extension.form-pdf.view', $application))->assertForbidden();
        $this->actingAs($this->supervisor('another@test.my'))->get(route('ga-extension.form-pdf.view', $application))->assertForbidden();

        // The download is unchanged.
        $download = $this->actingAs($this->student)->get(route('ga-extension.form-pdf', $application));
        $this->assertStringStartsWith('attachment;', $download->headers->get('Content-Disposition'));
    }

    public function test_the_certification_attachment_previews_inline_for_its_owner_only(): void
    {
        $application = $this->certification();

        $response = $this->actingAs($this->student)->get(route('ga-certification.attachment.view', $application))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));

        $this->actingAs($this->student('22009999', 'other@test.my'))->get(route('ga-certification.attachment.view', $application))->assertForbidden();
        $this->actingAs($this->supervisor)->get(route('ga-certification.attachment.view', $application))->assertForbidden();
    }

    public function test_the_draft_letters_preview_inline_and_download_on_request(): void
    {
        // Certification at the Senior Director.
        $application = $this->certification();
        $this->actingAs($this->supervisor)->post(route('ga-certification.decide', $application), ['decision' => 'approve']);
        $this->actingAs($this->cgs())->post(route('ga-certification.decide', $application), ['decision' => 'approve']);
        $director = $this->seniorDirector();

        $inline = $this->actingAs($director)->get(route('ga-certification.letter.preview', $application))->assertOk();
        $this->assertStringStartsWith('inline;', $inline->headers->get('Content-Disposition'));
        $download = $this->actingAs($director)->get(route('ga-certification.letter.preview', [$application, 'download' => 1]))->assertOk();
        $this->assertStringStartsWith('attachment;', $download->headers->get('Content-Disposition'));
        $this->actingAs($this->student)->get(route('ga-certification.letter.preview', $application))->assertForbidden();

        $this->actingAs($director)->get(route('ga-certification.queue', ['stage' => 'senior_director']))
            ->assertSee('Certification letter (draft preview)')
            ->assertSee('Approve &amp; issue letter', false);
    }

    /* -----------------------------------------------------------------
     | The approver's row
     |------------------------------------------------------------------*/

    public function test_each_queue_row_lists_documents_with_view_and_download_and_one_decision_bar(): void
    {
        $application = $this->certification();
        $document = $application->documents()->sole();

        $this->actingAs($this->supervisor)->get(route('ga-certification.queue'))
            ->assertOk()
            ->assertSee('data-preview-url="'.$document->viewUrl().'"', false)
            ->assertSee('href="'.$document->url().'"', false)
            ->assertSee('data-decision-bar', false)
            ->assertSee('Remarks (optional)')
            ->assertSee('class="file-preview"', false);
    }

    public function test_ga_extension_shows_return_to_supervisor_in_the_bar_for_cgs_only(): void
    {
        $this->actingAs($this->student)->post(route('ga-extension.store'), $this->gaExtensionFields() + $this->gaExtensionDocuments());
        $application = Application::where('module_type', 'ga_extension')->sole();

        $this->actingAs($this->supervisor)->get(route('ga-extension.queue'))->assertDontSee('Return to supervisor');

        $this->actingAs($this->supervisor)->post(route('ga-extension.decide', $application), ['decision' => 'approve']);
        $this->actingAs($this->cgs())->get(route('ga-extension.queue', ['stage' => 'cgs_verify']))
            ->assertSee('Return to supervisor')
            ->assertSee('formaction="'.route('ga-extension.return', $application).'"', false);
    }

    /* -----------------------------------------------------------------
     | A rejection needs a reason
     |------------------------------------------------------------------*/

    public function test_rejecting_without_remarks_fails_server_side_in_every_module(): void
    {
        // Certification.
        $certification = $this->certification();
        $this->actingAs($this->supervisor)->post(route('ga-certification.decide', $certification), ['decision' => 'reject', 'remarks' => ' '])
            ->assertSessionHasErrors(['remarks' => 'Say why this request is being rejected — the student is owed a reason.']);
        $this->assertSame(Application::STATUS_PENDING, $certification->fresh()->status);

        // GA Extension.
        $this->actingAs($this->student)->post(route('ga-extension.store'), $this->gaExtensionFields() + $this->gaExtensionDocuments());
        $extension = Application::where('module_type', 'ga_extension')->sole();
        $this->actingAs($this->supervisor)->post(route('ga-extension.decide', $extension), ['decision' => 'reject'])->assertSessionHasErrors('remarks');
        $this->assertSame(Application::STATUS_PENDING, $extension->fresh()->status);

        // Attendance appeal.
        $appeal = Application::create(['student_id' => $this->student->id, 'submitted_by_id' => $this->student->id, 'module_type' => 'attendance_appeal',
            'status' => Application::STATUS_PENDING, 'current_stage' => 'cgs_review', 'submitted_at' => now()]);
        AttendanceAppealDetail::create(['application_id' => $appeal->id, 'reason' => 'I was on fieldwork for most of that month.']);
        $cgs = $this->cgs();
        $this->actingAs($cgs)->post(route('attendance-appeal.decide', $appeal), ['decision' => 'reject'])->assertSessionHasErrors('remarks');
        $this->assertSame(Application::STATUS_PENDING, $appeal->fresh()->status);

        // Supervision (already required; unchanged).
        $supervision = Application::create(['student_id' => $this->student->id, 'submitted_by_id' => $this->student->id, 'module_type' => 'supervision',
            'status' => Application::STATUS_PENDING, 'current_stage' => 'supervisor', 'submitted_at' => now()]);
        SupervisionDetail::create(['application_id' => $supervision->id, 'requested_supervisor_id' => $this->supervisor->id, 'justification' => 'Shared research interest.',
            'appointment_role' => SupervisionDetail::ROLE_MAIN, 'appointment_type' => 'new', 'cgs_stage_snapshot' => 'endorse']);
        $this->actingAs($this->supervisor)->post(route('supervision.decide', $supervision), ['decision' => 'reject'])->assertSessionHasErrors('remarks');
        $this->assertSame(Application::STATUS_PENDING, $supervision->fresh()->status);

        // With a reason, each rejection goes through.
        $this->actingAs($this->supervisor)->post(route('ga-certification.decide', $certification), ['decision' => 'reject', 'remarks' => 'The course owner did not sign the evidence.'])->assertSessionHasNoErrors();
        $this->assertSame(Application::STATUS_REJECTED, $certification->fresh()->status);
        $this->actingAs($cgs)->post(route('attendance-appeal.decide', $appeal), ['decision' => 'reject', 'remarks' => 'No evidence of fieldwork dates.'])->assertSessionHasNoErrors();
        $this->assertSame(Application::STATUS_REJECTED, $appeal->fresh()->status);
    }

    public function test_approving_still_needs_no_remarks(): void
    {
        $certification = $this->certification();

        $this->actingAs($this->supervisor)->post(route('ga-certification.decide', $certification), ['decision' => 'approve'])->assertSessionHasNoErrors();

        $this->assertSame('cgs_verify', $certification->fresh()->current_stage);
    }

    public function test_a_refused_rejection_comes_back_to_its_own_row_asking_for_a_reason(): void
    {
        $first = $this->certification();
        $second = $this->certification();

        $this->actingAs($this->supervisor)
            ->from(route('ga-certification.queue'))
            ->post(route('ga-certification.decide', $second), ['decision' => 'reject', 'remarks' => '', '_row' => $second->id])
            ->assertRedirect(route('ga-certification.queue'));

        $page = $this->actingAs($this->supervisor)->get(route('ga-certification.queue'))->getContent();

        // Only the refused row says "Reason (required)" and carries the error.
        // (The attribute as rendered; the component's script also names the selector.)
        $this->assertSame(1, preg_match_all('/data-decision-bar\s+data-decision-error/', $page));
        $this->assertMatchesRegularExpression('/id="nureen-decision-'.$second->id.'"[^>]*data-decision-error/', $page);
        $this->assertStringContainsString('Say why this request is being rejected', $page);
        $this->assertStringNotContainsString('id="nureen-decision-'.$first->id.'" data-decision-bar data-decision-error', $page);
        $this->assertSame(GaCertificationDetail::count(), 2);
    }
}
