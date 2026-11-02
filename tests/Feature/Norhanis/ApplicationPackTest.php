<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\ClaimsDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\MakesUsers;
use Tests\TestCase;
use ZipArchive;

/**
 * The downloads for one application. CGS gets the summary PDF and the ZIP
 * of that summary with every attached file; the supervisor gets a ZIP of
 * their own student's files with no summary; Chair and Dean get nothing.
 *
 * Which application is the applications list's rule; which files go in the
 * ZIP is Core's per-document rule, so CGS gets no file before the
 * application reaches CGS.
 */
class ApplicationPackTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_the_supervisor_downloads_their_students_files_but_not_the_summary(): void
    {
        $supervisor = $this->supervisor();
        $application = $this->claim($this->student('22001001', 'aina@test.my', ['name' => 'Aina Zulkifli', 'supervisor_id' => $supervisor->id]));

        $this->actingAs($supervisor)->get(route('norhanis.pack.pdf', $application))->assertForbidden();

        $zip = $this->actingAs($supervisor)->get(route('norhanis.pack.zip', $application))->assertOk();
        $this->assertStringContainsString($application->reference().'-files.zip', $zip->headers->get('Content-Disposition'));
        $this->assertSame([
            '01-receipt-receipt.pdf',
            '02-proof-of-payment-payment.pdf',
            '03-invoice-invoice.pdf',
        ], $this->zipEntries($zip));

        // Recorded, like a single file view is.
        $this->assertSame(1, Activity::where('log_name', 'document')->where('causer_id', $supervisor->id)->count());
    }

    public function test_cgs_downloads_the_summary_and_every_file(): void
    {
        $supervisor = $this->supervisor();
        $chair = $this->user(Role::CHAIR, ['email' => 'chair@test.my', 'department' => 'Computer & Information Sciences']);
        $cgs = $this->cgs();
        $engine = app(WorkflowEngine::class);

        $application = $this->claim($this->student('22001001', 'aina@test.my', [
            'name' => 'Aina Zulkifli', 'supervisor_id' => $supervisor->id, 'department' => 'Computer & Information Sciences',
        ]));
        $engine->decide($application, $supervisor, 'approve');
        $engine->decide($application->fresh(), $chair, 'approve');

        $pdf = $this->actingAs($cgs)->get(route('norhanis.pack.pdf', $application))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString($application->reference().'-summary.pdf', $pdf->headers->get('Content-Disposition'));

        $this->assertSame([
            '00-Application-Summary.pdf',
            '01-receipt-receipt.pdf',
            '02-proof-of-payment-payment.pdf',
            '03-invoice-invoice.pdf',
        ], $this->zipEntries($this->actingAs($cgs)->get(route('norhanis.pack.zip', $application))->assertOk()));
    }

    public function test_cgs_gets_the_files_only_once_the_application_reaches_cgs(): void
    {
        $supervisor = $this->supervisor();
        $chair = $this->user(Role::CHAIR, ['email' => 'chair@test.my', 'department' => 'Computer & Information Sciences']);
        $cgs = $this->cgs();
        $engine = app(WorkflowEngine::class);

        $application = $this->claim($this->student('22001001', 'aina@test.my', [
            'name' => 'Aina Zulkifli', 'supervisor_id' => $supervisor->id, 'department' => 'Computer & Information Sciences',
        ]));

        // Still with the supervisor: CGS sees it on the list, so it gets the
        // summary -- but Core's document rule keeps the files back.
        $this->assertSame(['00-Application-Summary.pdf'], $this->zipEntries(
            $this->actingAs($cgs)->get(route('norhanis.pack.zip', $application))->assertOk(),
        ));

        $engine->decide($application, $supervisor, 'approve');
        $engine->decide($application->fresh(), $chair, 'approve');

        // Now on CGS's own stage: every file comes with it.
        $this->assertCount(4, $this->zipEntries(
            $this->actingAs($cgs)->get(route('norhanis.pack.zip', $application))->assertOk(),
        ));
    }

    public function test_only_approvers_who_may_see_the_application_can_download_it(): void
    {
        $supervisor = $this->supervisor();
        $other = $this->supervisor('other.sup@test.my');
        $student = $this->student('22001001', 'aina@test.my', ['name' => 'Aina Zulkifli', 'supervisor_id' => $supervisor->id]);
        $application = $this->claim($student);
        $draft = $this->claim($student, submit: false);

        $dean = $this->dean();
        $routes = fn (Application $a) => [route('norhanis.pack.pdf', $a), route('norhanis.pack.zip', $a)];

        foreach ($routes($application) as $url) {
            // Approvers only: not even the student whose application it is.
            $this->actingAs($student)->get($url)->assertForbidden();
            // Another supervisor's student.
            $this->actingAs($other)->get($url)->assertForbidden();
            // The Dean has no part in claims.
            $this->actingAs($dean)->get($url)->assertForbidden();
        }

        // A draft has not been submitted to anyone.
        foreach ($routes($draft) as $url) {
            $this->actingAs($supervisor)->get($url)->assertForbidden();
        }
    }

    public function test_the_chair_has_no_downloads_even_for_an_application_on_their_stage(): void
    {
        $supervisor = $this->supervisor();
        $chair = $this->user(Role::CHAIR, ['email' => 'chair@test.my', 'department' => 'Computer & Information Sciences']);

        $application = $this->claim($this->student('22001003', 'c@test.my', [
            'supervisor_id' => $supervisor->id, 'department' => 'Computer & Information Sciences',
        ]));
        app(WorkflowEngine::class)->decide($application, $supervisor, 'approve');

        $this->actingAs($chair)->get(route('norhanis.pack.pdf', $application))->assertForbidden();
        $this->actingAs($chair)->get(route('norhanis.pack.zip', $application))->assertForbidden();
        $this->actingAs($chair)->get(route('claims.queue'))
            ->assertOk()
            ->assertDontSee(route('norhanis.pack.zip', $application));
        $this->actingAs($chair)->get(route('norhanis.overview', ['module' => 'claims']))
            ->assertOk()
            ->assertDontSee(route('norhanis.pack.zip', $application));
    }

    public function test_the_buttons_appear_on_the_queue_and_the_list(): void
    {
        $supervisor = $this->supervisor();
        $application = $this->claim($this->student('22001001', 'aina@test.my', ['supervisor_id' => $supervisor->id]));

        // The supervisor: the files button only.
        $this->actingAs($supervisor)->get(route('claims.queue'))
            ->assertOk()
            ->assertSee("Download student's files (ZIP)", false)
            ->assertDontSee('Download summary (PDF)')
            ->assertSee(route('norhanis.pack.zip', $application));

        $this->actingAs($supervisor)->get(route('norhanis.overview', ['module' => 'claims']))
            ->assertOk()
            ->assertSee(route('norhanis.pack.zip', $application))
            ->assertDontSee(route('norhanis.pack.pdf', $application));

        // CGS: both.
        $this->actingAs($this->cgs())->get(route('norhanis.overview', ['module' => 'claims']))
            ->assertOk()
            ->assertSee(route('norhanis.pack.pdf', $application))
            ->assertSee(route('norhanis.pack.zip', $application));
    }

    /** A claims application with its three required files, submitted. */
    protected function claim(User $student, bool $submit = true): Application
    {
        $application = Application::create([
            'student_id' => $student->id,
            'module_type' => 'claims_student',
            'status' => Application::STATUS_DRAFT,
        ]);

        ClaimsDetail::create([
            'application_id' => $application->id,
            'bank_name' => 'Maybank',
            'bank_account_no' => '1234567890',
            'total_claims' => 500,
        ]);

        $documents = app(DocumentStore::class);
        $documents->attach($application, UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'), 'Receipt');
        $documents->attach($application, UploadedFile::fake()->create('payment.pdf', 10, 'application/pdf'), 'Proof of Payment');
        $documents->attach($application, UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'), 'Invoice');

        return $submit ? app(WorkflowEngine::class)->submit($application) : $application;
    }

    /** @return array<int, string> the entry names, in order */
    protected function zipEntries($response): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->getFile()->getPathname()));

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        return $names;
    }
}
