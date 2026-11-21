<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Support\LetterRenderer;
use App\Modules\Nureen\Support\PdfText;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * nureen:regenerate-certification-letters: old-template letters are
 * re-rendered over the same stored file, a missing reference is assigned,
 * current letters are left alone, and nobody is emailed.
 */
class RegenerateCertificationLettersTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected User $director;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
        Mail::fake();
        LetterRenderer::forgetLogo();

        $this->director = $this->user(Role::SENIOR_DIRECTOR_CGS, ['name' => 'Prof. Approving Director', 'email' => 'director@test.my']);
    }

    /** An approved certification, with the Senior Director's row in approval_history. */
    protected function approvedCertification(string $matric, ?string $reference): Application
    {
        $student = $this->student($matric, "{$matric}@test.my", ['name' => "Student {$matric}"]);
        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_certification',
            'status' => Application::STATUS_APPROVED,
            'submitted_at' => now()->subDays(10),
        ]);
        GaCertificationDetail::create([
            'application_id' => $application->id,
            'appointment_type' => 'GA',
            'period_start' => '2026-01-05',
            'period_end' => '2026-05-29',
            'purpose' => 'Scholarship renewal',
            'semester' => 'January 2026',
            'course_code' => 'CDB1023',
            'course_name' => 'Thermodynamics',
            'course_owner_name' => 'Dr. Lim Wei Chun',
            'reference_number' => $reference,
        ]);
        ApprovalHistory::create([
            'application_id' => $application->id,
            'approver_id' => $this->director->id,
            'stage_key' => 'senior_director',
            'stage_label' => 'Senior Director CGS',
            'decision' => 'approved',
        ]);

        return $application;
    }

    /** A letter in the first template's words: UResearch reference, "endorsed through", no logo. */
    protected function storeOldLetter(Application $application): ApplicationDocument
    {
        $pdf = Pdf::loadHTML(
            '<h1>Universiti Teknologi PETRONAS</h1>'
            ."<p>Ref: UResearch/GA/{$application->id}</p>"
            .'<p>This letter is generated and digitally endorsed through UResearch 2.0 and does not require a physical signature.</p>'
        )->output();

        return app(DocumentStore::class)->storeGenerated($application, $pdf, "certification-{$application->id}.pdf", LetterRenderer::CERTIFICATION_DOC_TYPE);
    }

    protected function storeCurrentLetter(Application $application): ApplicationDocument
    {
        return app(DocumentStore::class)->storeGenerated(
            $application, app(LetterRenderer::class)->certification($application),
            "certification-{$application->id}.pdf", LetterRenderer::CERTIFICATION_DOC_TYPE,
        );
    }

    protected function text(ApplicationDocument $document): string
    {
        return PdfText::compact(Storage::disk('local')->get($document->fresh()->path));
    }

    public function test_it_reads_the_old_and_current_templates_apart(): void
    {
        $old = $this->storeOldLetter($this->approvedCertification('22001001', null));
        $current = $this->storeCurrentLetter($this->approvedCertification('22001002', 'CGS/GACERT/2026/0001'));

        $this->assertStringContainsString('Ref:UResearch/GA/', $this->text($old));
        $this->assertStringContainsString('endorsedthroughUResearch', $this->text($old));
        $this->assertStringContainsString('CGS/GACERT/2026/0001', $this->text($current));
        $this->assertStringNotContainsString('endorsedthrough', $this->text($current));
    }

    public function test_dry_run_lists_the_letters_and_changes_nothing(): void
    {
        $application = $this->approvedCertification('22001001', null);
        $document = $this->storeOldLetter($application);
        $before = Storage::disk('local')->get($document->path);

        $this->artisan('nureen:regenerate-certification-letters', ['--dry-run' => true])
            ->expectsOutputToContain($application->reference())
            ->expectsOutputToContain('Dry run: nothing was changed.')
            ->assertSuccessful();

        $this->assertSame($before, Storage::disk('local')->get($document->path));
        $this->assertNull(GaCertificationDetail::where('application_id', $application->id)->value('reference_number'));
    }

    public function test_it_regenerates_old_letters_in_place_and_emails_no_one(): void
    {
        // Old template and no reference.
        $noReference = $this->approvedCertification('22001001', null);
        $oldDoc = $this->storeOldLetter($noReference);

        // Old template, but already given a CGS reference: keeps it.
        $referenced = $this->approvedCertification('22001002', 'CGS/GACERT/2026/0001');
        $referencedDoc = $this->storeOldLetter($referenced);

        // Made by the current template: left exactly as it is.
        $current = $this->approvedCertification('22001003', 'CGS/GACERT/2026/0002');
        $currentDoc = $this->storeCurrentLetter($current);
        $currentBytes = Storage::disk('local')->get($currentDoc->path);

        // Approved, but no letter on file: listed, nothing created.
        $noLetter = $this->approvedCertification('22001004', null);

        $documentCount = ApplicationDocument::count();

        $this->artisan('nureen:regenerate-certification-letters')
            ->expectsOutputToContain('2 letter(s) regenerated in place. No one was emailed.')
            ->expectsOutputToContain('No letter on file to replace')
            ->assertSuccessful();

        // A reference was assigned, with the generator's format.
        $assigned = GaCertificationDetail::where('application_id', $noReference->id)->value('reference_number');
        $this->assertMatchesRegularExpression('#^CGS/GACERT/\d{4}/\d{4}$#', $assigned);
        $this->assertNotSame('CGS/GACERT/2026/0001', $assigned);
        $this->assertSame('CGS/GACERT/2026/0001', GaCertificationDetail::where('application_id', $referenced->id)->value('reference_number'));

        // Same rows, same paths, new contents in the current template.
        $this->assertSame($documentCount, ApplicationDocument::count());
        foreach ([[$oldDoc, $assigned], [$referencedDoc, 'CGS/GACERT/2026/0001']] as [$document, $reference]) {
            $fresh = $document->fresh();
            $this->assertSame($document->path, $fresh->path);
            $text = $this->text($document);
            $this->assertStringContainsString($reference, $text);
            $this->assertStringContainsString('ProfApprovingDirector', str_replace('.', '', $text));
            $this->assertStringNotContainsString('endorsedthroughUResearch', $text);
            $this->assertStringNotContainsString('Ref:UResearch/', $text);
            $this->assertSame(strlen(Storage::disk('local')->get($fresh->path)), (int) $fresh->size_bytes);
        }

        // The existing link still serves it, to the student who owns it.
        $this->actingAs($noReference->student)->get(route('documents.show', $oldDoc))->assertOk();

        $this->assertSame($currentBytes, Storage::disk('local')->get($currentDoc->path));
        $this->assertSame(0, $noLetter->documents()->count());
        $this->assertNull(GaCertificationDetail::where('application_id', $noLetter->id)->value('reference_number'));

        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_a_second_run_finds_nothing_to_do(): void
    {
        $this->storeOldLetter($this->approvedCertification('22001001', null));

        $this->artisan('nureen:regenerate-certification-letters')->assertSuccessful();
        $this->artisan('nureen:regenerate-certification-letters')
            ->expectsOutputToContain('No certification letter needs regenerating.')
            ->assertSuccessful();
    }
}
