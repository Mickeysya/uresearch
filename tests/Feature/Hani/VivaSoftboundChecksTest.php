<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\VivaEligibility;
use App\Modules\Hani\Models\VivaSoftboundCheck;
use App\Modules\Hani\Models\VivaSoftboundDetail;
use App\Modules\Hani\Support\Demo\FictionalPdfs;
use App\Modules\Hani\Support\Pdf\PdfReader;
use App\Modules\Hani\Support\Similarity\SimilaritySource;
use App\Modules\Hani\Support\Similarity\UploadedReportSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\FilesVivaNotices;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Softbound "System checks": the Turnitin report read by the built-in PDF
 * reader from a FICTIONAL ULearnX-layout fixture, the fallback when it cannot
 * be read, the warnings the AE sees, and the 25% limit on the figure read.
 */
class VivaSoftboundChecksTest extends TestCase
{
    use FilesVivaNotices;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected const TITLE = 'Deep Learning-Based Anomaly Detection for Offshore Pipeline Sensor Data';

    protected User $student;

    protected User $ae;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();

        $this->student = $this->student(attributes: ['programme' => 'MSc Full-Time', 'department' => 'Computing']);
        $this->ae = $this->academicExec();
        $this->ae->update(['department' => 'Computing']);

        VivaEligibility::create(['student_id' => $this->student->id] + array_fill_keys([
            'research_methodology_passed', 'publication_accepted', 'registered_current_semester',
            'no_outstanding_fees', 'within_candidacy_period',
        ], true));

        $this->actingAs($this->student)->post(route('viva.notice.store'), $this->noticeFields(['thesis_title' => self::TITLE]))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->ae)->post(route('viva.decide', Application::where('module_type', 'viva_voce')->sole()), ['decision' => 'approve']);
    }

    protected function form(array $overrides = []): array
    {
        $pdf = fn (string $name) => UploadedFile::fake()->create($name, 50, 'application/pdf');

        return $overrides + [
            'field_of_study' => 'Data analytics',
            'contact_number' => '013-337 2001',
            'personal_email' => 'student@example.com',
            'session' => 'September',
            'year' => now()->year,
            'thesis_title' => self::TITLE,
            'similarity_percent' => 12,
            'word_count' => 1200,
            'declaration' => 1,
            'format_confirmation' => $pdf('format.pdf'),
            'thesis' => UploadedFile::fake()->createWithContent('thesis.pdf', FictionalPdfs::thesis(self::TITLE, 1200, 3)),
            'turnitin_report' => UploadedFile::fake()->createWithContent('turnitin.pdf', FictionalPdfs::turnitinReport(self::TITLE)),
        ];
    }

    protected function results(): array
    {
        return collect(VivaSoftboundCheck::sole()->results)->keyBy('key')->all();
    }

    public function test_the_built_in_reader_extracts_the_ulearn_layout(): void
    {
        $this->assertSame('built-in reader', app(PdfReader::class)->engine());

        foreach (['Helvetica', 'DejaVu Sans'] as $font) {   // standard font, then a ToUnicode font
            $reading = app(UploadedReportSource::class)->read(FictionalPdfs::turnitinReport(self::TITLE, font: $font));

            $this->assertTrue($reading->ok, $font.': '.$reading->failure);
            $this->assertSame(self::TITLE, $reading->title, $font);
            $this->assertSame([12.0, 10.0, 5.0, 3.0], [$reading->index, $reading->internet, $reading->publications, $reading->studentPapers]);
            $this->assertCount(5, $reading->sources, 'top five only');
            $this->assertSame(['rank' => 1, 'source' => 'www.example.com', 'type' => 'Internet Source', 'percent' => '4%', 'value' => 4.0], $reading->sources[0]);
            $this->assertSame('Student Paper', $reading->sources[1]['type']);
            $this->assertSame('<1%', $reading->sources[4]['percent']);
            $this->assertSame(['quotes' => 'On', 'bibliography' => 'On', 'matches' => '< 10 words'], $reading->exclusions);
            $this->assertNull($reading->submittedAt, 'the report itself has no date');
            $this->assertNull($reading->submissionId);
        }

        $withReceipt = app(UploadedReportSource::class)->read(FictionalPdfs::turnitinReport(self::TITLE,
            receipt: ['date' => '03-Nov-2026 10:15AM (UTC+0800)', 'id' => '2401234567']));
        $this->assertSame('2401234567', $withReceipt->submissionId);
        $this->assertSame('2026-11-03', $withReceipt->submittedOn()->toDateString());
        $this->assertSame(self::TITLE, $withReceipt->title, 'the receipt page is not mistaken for the title');
    }

    public function test_the_form_reads_the_chosen_report_to_prefill_the_index(): void
    {
        $this->actingAs($this->student)->get(route('viva.softbound.create'))
            ->assertOk()
            ->assertSee('full similarity report')
            ->assertSee('ULearnX (postgraduate students)')
            ->assertSee('https://ulearnx.utp.edu.my');
        $this->assertSame('https://ulearnx.utp.edu.my', config('hani.similarity.ulearn_url'));

        $this->actingAs($this->student)->postJson(route('viva.softbound.similarity-preview'), [
            'turnitin_report' => UploadedFile::fake()->createWithContent('turnitin.pdf', FictionalPdfs::turnitinReport(self::TITLE, 18)),
        ])->assertOk()->assertJson(['ok' => true, 'index' => 18, 'internet' => 10, 'over_limit' => false]);

        $this->actingAs($this->student)->postJson(route('viva.softbound.similarity-preview'), [
            'turnitin_report' => UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\nnot really a report"),
        ])->assertOk()->assertJson(['ok' => false]);

        // Nothing is stored by a preview.
        $this->assertSame([], Storage::disk('local')->allFiles('applications/'.(Application::max('id') + 1)));
    }

    public function test_a_readable_report_and_thesis_pass_the_system_checks(): void
    {
        $this->actingAs($this->student)->post(route('viva.softbound.store'), $this->form())->assertSessionHasNoErrors();

        $detail = VivaSoftboundDetail::sole();
        $this->assertSame('extracted', $detail->similarity_source);
        $this->assertEquals(12, $detail->similarity_extracted);

        $check = VivaSoftboundCheck::sole();
        $this->assertSame(VivaSoftboundCheck::DONE, $check->status);
        $results = $this->results();

        $this->assertSame('pass', $results['similarity_read']['status']);
        $this->assertSame('pass', $results['report_title']['status']);
        $this->assertSame('pass', $results['single_source']['status']);
        $this->assertSame('info', $results['exclusions']['status']);
        $this->assertStringContainsString('Exclude quotes On · Exclude bibliography On · Exclude matches < 10 words', $results['exclusions']['detail']);
        $this->assertSame('not_checked', $results['report_age']['status']);
        $this->assertSame('Not checked — no submission date in this report.', $results['report_age']['detail']);
        $this->assertSame('pass', $results['thesis_file']['status']);
        $this->assertSame('3 pages.', $results['page_count']['detail']);
        $this->assertSame('pass', $results['word_count']['status']);
        $this->assertSame('pass', $results['thesis_title']['status']);
        $this->assertSame('pass', $check->overall());

        $this->actingAs($this->ae)->get(route('viva.softbound.queue'))
            ->assertOk()
            ->assertSee('System checks')
            ->assertSee('Read from the report')
            ->assertSee('Not checked — no submission date in this report.');
    }

    public function test_mismatches_and_a_large_single_source_are_warned(): void
    {
        $report = FictionalPdfs::turnitinReport('A Completely Different Study of Coastal Erosion', 14, [9, 4, 1], [
            ['www.example.com', 'Internet Source', '7%'],
            ['eprints.example.org', 'Internet Source', '2%'],
        ]);

        $this->actingAs($this->student)->post(route('viva.softbound.store'), $this->form([
            'similarity_percent' => 9.5,          // declared, but the report says 14
            'word_count' => 3000,                 // the PDF has about 1,200
            'turnitin_report' => UploadedFile::fake()->createWithContent('turnitin.pdf', $report),
            'thesis' => UploadedFile::fake()->createWithContent('thesis.pdf', FictionalPdfs::thesis('Something Else Entirely About Rivers', 1200, 3)),
        ]))->assertSessionHasNoErrors();

        $results = $this->results();
        $this->assertSame('warning', $results['similarity_read']['status']);
        $this->assertStringContainsString('declared 9.5%, which does not match', $results['similarity_read']['detail']);
        $this->assertSame('warning', $results['report_title']['status']);
        $this->assertSame('warning', $results['single_source']['status']);
        $this->assertStringContainsString('www.example.com (Internet Source, 7%)', $results['single_source']['detail']);
        $this->assertSame('warning', $results['word_count']['status']);
        $this->assertSame('warning', $results['thesis_title']['status']);
        $this->assertSame('warning', VivaSoftboundCheck::sole()->overall());
    }

    public function test_an_unreadable_report_falls_back_to_the_declared_figure(): void
    {
        $this->actingAs($this->student)->post(route('viva.softbound.store'), $this->form([
            'similarity_percent' => 17,
            'turnitin_report' => UploadedFile::fake()->createWithContent('turnitin.pdf', "%PDF-1.4\n1 0 obj << >> endobj"),
            'thesis' => UploadedFile::fake()->createWithContent('thesis.pdf', 'Plain text pretending to be a PDF'),
        ]))->assertSessionHasNoErrors();

        $detail = VivaSoftboundDetail::sole();
        $this->assertSame('self_declared', $detail->similarity_source);
        $this->assertNull($detail->similarity_extracted);
        $this->assertEquals(17, $detail->similarity_percent);

        $results = $this->results();
        $this->assertSame('not_checked', $results['similarity_read']['status']);
        $this->assertStringContainsString('Not checked automatically', $results['similarity_read']['detail']);
        $this->assertStringContainsString("declared 17% is used (Self-declared)", $results['similarity_read']['detail']);
        $this->assertSame('warning', $results['thesis_file']['status']);
        $this->assertStringContainsString('Not a real PDF', $results['thesis_file']['detail']);

        $this->actingAs($this->ae)->get(route('viva.softbound.queue'))->assertSee('Self-declared');
    }

    public function test_the_25_percent_limit_holds_on_the_figure_read_from_the_report(): void
    {
        $this->actingAs($this->student)->post(route('viva.softbound.store'), $this->form([
            'similarity_percent' => 20,
            'turnitin_report' => UploadedFile::fake()->createWithContent('turnitin.pdf', FictionalPdfs::turnitinReport(self::TITLE, 31, [20, 8, 3])),
        ]))->assertSessionHasErrors(['similarity_percent' => 'Your Turnitin report shows a similarity index of 31%, above the 25% limit. Revise the thesis and generate a new report before submitting.']);

        $this->actingAs($this->student)->post(route('viva.softbound.store'), $this->form(['similarity_percent' => 26]))
            ->assertSessionHasErrors('similarity_percent');

        $this->assertSame(0, Application::where('module_type', 'viva_softbound')->count());
    }

    public function test_the_turnitin_api_stub_and_simulated_source_say_what_they_are(): void
    {
        config(['hani.similarity.source' => 'turnitin_api']);
        $reading = app(SimilaritySource::class)->read('anything');
        $this->assertFalse($reading->ok);
        $this->assertStringContainsString('needs a UTP licence', $reading->failure);

        config(['hani.similarity.source' => 'simulated']);
        $reading = app(SimilaritySource::class)->read('same bytes');
        $this->assertTrue($reading->ok);
        $this->assertSame('simulated', $reading->source);
        $this->assertEquals($reading->index, app(SimilaritySource::class)->read('same bytes')->index, 'deterministic');
    }
}
