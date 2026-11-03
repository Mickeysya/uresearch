<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\ClaimsDetail;
use App\Modules\Norhanis\Models\TravelDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\MakesUsers;
use Tests\TestCase;
use ZipArchive;

/**
 * The downloads for one application: the summary PDF, the ZIP of that
 * summary with every attached file, and a preview of each PDF or image.
 * Every approver in the chain gets them -- CGS for any submitted
 * application, the supervisor for their own students, the Chair for their
 * own department and the Dean once it reaches them.
 *
 * Which files go in the ZIP is Core's per-document rule, so CGS gets no file
 * before the application reaches CGS.
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

    public function test_the_supervisor_downloads_the_summary_and_their_students_files(): void
    {
        $supervisor = $this->supervisor();
        $application = $this->claim($this->student('22001001', 'aina@test.my', ['name' => 'Aina Zulkifli', 'supervisor_id' => $supervisor->id]));

        $this->actingAs($supervisor)->get(route('norhanis.pack.pdf', $application))->assertOk();

        $zip = $this->actingAs($supervisor)->get(route('norhanis.pack.zip', $application))->assertOk();
        $this->assertStringContainsString($application->reference().'-documents.zip', $zip->headers->get('Content-Disposition'));
        $this->assertSame([
            '00-Application-Summary.pdf',
            '01-receipt-receipt.pdf',
            '02-proof-of-payment-payment.pdf',
            '03-invoice-invoice.pdf',
        ], $this->zipEntries($zip));

        // Both recorded, like a single file view is.
        $this->assertSame(2, Activity::where('log_name', 'document')->where('causer_id', $supervisor->id)->count());
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

        $chair = $this->user(Role::CHAIR, ['email' => 'chair@test.my', 'department' => 'Computer & Information Sciences']);

        foreach ($routes($application) as $url) {
            // Approvers only: not even the student whose application it is.
            $this->actingAs($student)->get($url)->assertForbidden();
            // Another supervisor's student.
            $this->actingAs($other)->get($url)->assertForbidden();
            // The Dean has no part in claims.
            $this->actingAs($dean)->get($url)->assertForbidden();
            // Still with the supervisor: it has not reached the Chair.
            $this->actingAs($chair)->get($url)->assertForbidden();
        }

        // A draft has not been submitted to anyone.
        foreach ($routes($draft) as $url) {
            $this->actingAs($supervisor)->get($url)->assertForbidden();
        }
    }

    public function test_the_chair_downloads_once_it_reaches_them_and_only_for_their_department(): void
    {
        $supervisor = $this->supervisor();
        $chair = $this->user(Role::CHAIR, ['email' => 'chair@test.my', 'department' => 'Computer & Information Sciences']);
        $otherChair = $this->user(Role::CHAIR, ['email' => 'other.chair@test.my', 'department' => 'Chemical Engineering']);

        $application = $this->claim($this->student('22001003', 'c@test.my', [
            'supervisor_id' => $supervisor->id, 'department' => 'Computer & Information Sciences',
        ]));
        app(WorkflowEngine::class)->decide($application, $supervisor, 'approve');

        $this->actingAs($chair)->get(route('norhanis.pack.pdf', $application))->assertOk();
        $this->assertCount(4, $this->zipEntries($this->actingAs($chair)->get(route('norhanis.pack.zip', $application))->assertOk()));
        $this->actingAs($chair)->get(route('claims.queue'))
            ->assertOk()
            ->assertSee(route('norhanis.pack.zip', $application));

        // Another department's Chair holds the same role, not this student.
        $this->actingAs($otherChair)->get(route('norhanis.pack.zip', $application))->assertForbidden();

        // Once the Chair has decided it, they can still download it.
        app(WorkflowEngine::class)->decide($application->fresh(), $chair, 'approve');
        $this->actingAs($chair)->get(route('norhanis.pack.pdf', $application))->assertOk();
    }

    public function test_the_dean_downloads_international_travel_once_it_reaches_them(): void
    {
        $supervisor = $this->supervisor();
        $chair = $this->user(Role::CHAIR, ['email' => 'chair@test.my', 'department' => 'Computer & Information Sciences']);
        $dean = $this->dean();
        $engine = app(WorkflowEngine::class);

        $travel = $this->internationalTravel($this->student('22001004', 'd@test.my', [
            'supervisor_id' => $supervisor->id, 'department' => 'Computer & Information Sciences',
        ]));

        $this->actingAs($dean)->get(route('norhanis.pack.zip', $travel))->assertForbidden();

        $engine->decide($travel, $supervisor, 'approve');
        $engine->decide($travel->fresh(), $chair, 'approve');
        $engine->decide($travel->fresh(), $this->cgs(), 'approve');

        $this->assertSame(['00-Application-Summary.pdf', '01-supporting-document-programme.pdf'], $this->zipEntries(
            $this->actingAs($dean)->get(route('norhanis.pack.zip', $travel))->assertOk(),
        ));
    }

    public function test_a_pdf_or_image_previews_in_the_browser_and_other_files_do_not(): void
    {
        $supervisor = $this->supervisor();
        $application = $this->claim($this->student('22001001', 'aina@test.my', ['supervisor_id' => $supervisor->id]));
        $receipt = $application->documents()->where('doc_type', 'Receipt')->first();

        $preview = $this->actingAs($supervisor)->get(route('norhanis.pack.preview', $receipt))->assertOk();
        $this->assertSame('application/pdf', $preview->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', $preview->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $preview->headers->get('X-Content-Type-Options'));
        $this->assertSame(1, Activity::where('log_name', 'document')->where('description', 'Previewed document')->count());

        // Someone else's supervisor, and the student, get no preview.
        $this->actingAs($this->supervisor('other.sup@test.my'))->get(route('norhanis.pack.preview', $receipt))->assertForbidden();
        $this->actingAs($application->student)->get(route('norhanis.pack.preview', $receipt))->assertForbidden();

        // A Word file is not opened in the browser; it is downloaded instead.
        $word = app(DocumentStore::class)->attach($application, UploadedFile::fake()->create('notes.docx', 10), 'Notes');
        $this->actingAs($supervisor)->get(route('norhanis.pack.preview', $word))->assertStatus(415);
    }

    public function test_the_queue_card_lists_each_file_to_open_in_a_new_tab_or_download(): void
    {
        $supervisor = $this->supervisor();
        $application = $this->claim($this->student('22001001', 'aina@test.my', ['supervisor_id' => $supervisor->id]));
        $receipt = $application->documents()->where('doc_type', 'Receipt')->first();

        $queue = $this->actingAs($supervisor)->get(route('claims.queue'))
            ->assertOk()
            ->assertSee('Download summary (PDF)')
            ->assertSee('Download all files (ZIP)')
            ->assertSee('Documents (3)')
            ->assertSee($receipt->url());
        $html = $queue->getContent();

        // Each file's name opens it in a new tab, leaving the queue as it was.
        $this->assertStringContainsString('href="'.route('norhanis.pack.preview', $receipt).'"
                                   target="_blank"', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        // Core's plain list of the same files is hidden on this card.
        $this->assertStringContainsString('.queue-row-body:has(.pv-inline) > .doc-list { display: none; }', $html);
        $this->assertSame(1, substr_count($html, '.queue-row-body:has(.pv-inline)'));

        $this->actingAs($this->cgs())->get(route('norhanis.overview', ['module' => 'claims']))
            ->assertOk()
            ->assertSee(route('norhanis.pack.pdf', $application))
            ->assertSee(route('norhanis.pack.zip', $application));
    }

    public function test_a_reader_outside_the_scope_keeps_cores_list_only(): void
    {
        $supervisor = $this->supervisor();
        $this->claim($this->student('22001001', 'aina@test.my', ['supervisor_id' => $supervisor->id]));

        // Core's queues are scoped by role: another supervisor sees the row,
        // but gets none of this module's downloads or previews.
        $this->actingAs($this->supervisor('other.sup@test.my'))->get(route('claims.queue'))
            ->assertOk()
            ->assertDontSee('Download summary (PDF)')
            ->assertDontSee('pv-inline');
    }

    public function test_the_documents_page_lists_every_file_and_opens_each_on_its_own(): void
    {
        $supervisor = $this->supervisor();
        $application = $this->claim($this->student('22001001', 'aina@test.my', ['supervisor_id' => $supervisor->id]));
        [$receipt, $payment] = $application->documents()->orderBy('id')->take(2)->get()->all();

        // The list: every file, each opening on its own and downloadable,
        // with a way back to this application in the queue. Nothing framed.
        $list = $this->actingAs($supervisor)->get(route('norhanis.pack.documents', $application))
            ->assertOk()
            ->assertSee('3 documents')
            ->assertSee(route('norhanis.pack.documents', [$application, 'document' => $payment->id]))
            ->assertSee($payment->url())
            ->assertSee(route('claims.queue', ['q' => '#'.$application->id]));
        $this->assertStringNotContainsString('<iframe', $list->getContent());

        // One file: framed through the preview route, with Download and a
        // way back to the list.
        $this->actingAs($supervisor)->get(route('norhanis.pack.documents', [$application, 'document' => $receipt->id]))
            ->assertOk()
            ->assertSee('src="'.route('norhanis.pack.preview', $receipt).'"', false)
            ->assertSee($receipt->url())
            ->assertSee('All documents');

        // A file that cannot be shown offers the download instead.
        $word = app(DocumentStore::class)->attach($application, UploadedFile::fake()->create('notes.docx', 10), 'Notes');
        $this->actingAs($supervisor)->get(route('norhanis.pack.documents', [$application, 'document' => $word->id]))
            ->assertOk()
            ->assertSee('cannot be shown in the browser')
            ->assertSee($word->url());

        // Same rule as the downloads.
        $this->actingAs($this->supervisor('other.sup@test.my'))->get(route('norhanis.pack.documents', $application))->assertForbidden();
        $this->actingAs($application->student)->get(route('norhanis.pack.documents', $application))->assertForbidden();
    }

    /** An international travel application with its supporting document, submitted. */
    protected function internationalTravel(User $student): Application
    {
        $application = Application::create([
            'student_id' => $student->id,
            'module_type' => 'travel',
            'status' => Application::STATUS_DRAFT,
        ]);

        TravelDetail::create([
            'application_id' => $application->id,
            'type_of_request' => 'data_collection',
            'travel_start_date' => now()->addDays(30),
            'travel_end_date' => now()->addDays(33),
            'duration_days' => 4,
            'reason_for_travel' => 'Conference',
            'destination_address' => 'Singapore',
            'is_international' => true,
            'contact_person_email' => 'contact@test.my',
            'is_ga_gra' => false,
        ]);

        app(DocumentStore::class)->attach($application, UploadedFile::fake()->create('programme.pdf', 10, 'application/pdf'), 'Supporting Document');

        return app(WorkflowEngine::class)->submit($application);
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
