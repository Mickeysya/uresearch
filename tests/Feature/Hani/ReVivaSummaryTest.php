<?php

namespace Tests\Feature\Hani;

use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ReVivaSummary;
use App\Modules\Hani\Support\Demo\FictionalPdfs;
use App\Modules\Hani\Support\Pdf\PdfReader;
use App\Modules\Hani\Support\SummaryOfCorrections;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\BuildsReVivas;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Prompt 3 item c2 (nice-to-have): the digital Summary of Corrections, its
 * checks against the corrected thesis, the PDF, and logging with it.
 */
class ReVivaSummaryTest extends TestCase
{
    use BuildsReVivas;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
        Carbon::setTestNow('2027-03-15 09:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function thesis(int $pages = 6): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('corrected-thesis.pdf', FictionalPdfs::thesis('Thesis of 22001001', 1600, $pages, 'Student 22001001'));
    }

    protected function save(array $viva, array $rows, string $intent, ?UploadedFile $thesis = null)
    {
        return $this->actingAs($viva['student'])->post(route('reviva.summary.save'), array_filter([
            'rows' => $rows,
            'thesis' => $thesis,
            'intent' => $intent,
        ]));
    }

    public function test_page_references_are_read_as_people_write_them(): void
    {
        $this->assertSame(['pages' => [12], 'roman' => []], SummaryOfCorrections::parsePages('12'));
        $this->assertSame(['pages' => [12, 13, 14], 'roman' => []], SummaryOfCorrections::parsePages('pp. 12-14'));
        $this->assertSame(['pages' => [3, 5, 7, 8, 9], 'roman' => []], SummaryOfCorrections::parsePages('3, 5, 7-9'));
        $this->assertSame(['pages' => [3], 'roman' => ['iv']], SummaryOfCorrections::parsePages('iv, 3'));
        $this->assertNull(SummaryOfCorrections::parsePages('chapter two'));
        $this->assertNull(SummaryOfCorrections::parsePages(''));
    }

    public function test_the_checks_name_every_problem_and_a_clean_summary_becomes_the_pdf(): void
    {
        $viva = $this->categoryFourViva();
        $external = Examiner::find($viva['panel']->external_main_id);

        $this->actingAs($viva['student'])->get(route('reviva.mine'))->assertSee('Start your Summary of Corrections');
        $this->actingAs($viva['student'])->get(route('reviva.summary.edit'))
            ->assertOk()
            ->assertSee('Examiner 2 (External)')
            ->assertDontSee($external->name);

        $this->save($viva, [
            ['examiner' => 1, 'comment' => 'Clarify the research gap.', 'action' => '', 'pages' => '3'],
            ['examiner' => 2, 'comment' => 'Justify the sample size.', 'action' => 'Added section 3.4.', 'pages' => '9'],
            ['examiner' => 2, 'comment' => '', 'action' => '', 'pages' => ''],
        ], 'submit', $this->thesis(6))->assertSessionHas('error');

        $summary = ReVivaSummary::sole();
        $this->assertSame(6, $summary->thesis_pages, 'read with the built-in PDF reader');
        $this->assertNull($summary->submitted_at);
        $this->assertCount(2, $summary->rows, 'blank rows are dropped');

        $this->actingAs($viva['student'])->get(route('reviva.summary.edit'))
            ->assertSee('Examiner 1 (Internal), comment 1: the action taken is missing.')
            ->assertSee('Examiner 2 (External), comment 1: page 9 is beyond the thesis&#039;s 6 pages.', false);

        $this->save($viva, [
            ['examiner' => 1, 'comment' => 'Clarify the research gap.', 'action' => 'Rewrote section 1.2.', 'pages' => '3-4'],
            ['examiner' => 2, 'comment' => 'Justify the sample size.', 'action' => 'Added section 3.4.', 'pages' => '5'],
        ], 'submit')->assertSessionHas('status');
        $this->assertNotNull($summary->fresh()->submitted_at);

        $pdf = $this->actingAs($viva['student'])->get(route('reviva.summary.pdf'))->assertOk()->getContent();
        $text = preg_replace('/\s+/', ' ', implode("\n", (array) app(PdfReader::class)->text($pdf)));
        $this->assertStringContainsString('Clarify the research gap.', $text);
        $this->assertStringContainsString('Added section 3.4.', $text);
        $this->assertStringContainsString($viva['supervisor']->name, $text);
    }

    public function test_the_senior_executive_logs_with_the_students_digital_summary(): void
    {
        $viva = $this->categoryFourViva();
        $this->save($viva, [
            ['examiner' => 1, 'comment' => 'Clarify the gap.', 'action' => 'Rewrote 1.2.', 'pages' => '2'],
            ['examiner' => 2, 'comment' => 'Justify n.', 'action' => 'Added 3.4.', 'pages' => '5'],
        ], 'submit', $this->thesis());

        Carbon::setTestNow('2027-10-01 09:00');
        $this->actingAs($this->senior())->get(route('reviva.create', ['student' => $viva['student']->id]))
            ->assertSee('Use the Summary of Corrections');

        $this->logReViva($viva['student'], ['summary_of_corrections' => null, 'use_digital_summary' => 1])->assertSessionHasNoErrors();

        $round = $this->latestRound($viva['student']);
        $doc = $round->application->documents()->where('doc_type', 'Summary of Corrections')->sole();
        $this->assertSame('summary-of-corrections.pdf', $doc->original_name);
        $this->assertSame($round->id, ReVivaSummary::sole()->used_by_detail_id);
    }

    public function test_no_digital_summary_means_the_upload_is_required_and_only_expected_students_may_open_it(): void
    {
        $viva = $this->categoryFourViva();
        Carbon::setTestNow('2027-10-01 09:00');

        $this->logReViva($viva['student'], ['summary_of_corrections' => null, 'use_digital_summary' => 1])
            ->assertSessionHasErrors('summary_of_corrections');
        $this->logReViva($viva['student'], ['summary_of_corrections' => null])->assertSessionHasErrors('summary_of_corrections');

        $this->actingAs($this->student('22009999', 'nobody@test.my'))->get(route('reviva.summary.edit'))->assertForbidden();
    }
}
