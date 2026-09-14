<?php

namespace Tests\Feature\Nureen;

use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Notifications\AttendanceAtRisk;
use App\Modules\Nureen\Support\AttendanceSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Attendance ingestion — `docs/scope/nureen.md` Module 2.
 *
 * The most exposed surface in the portal: a spreadsheet a human filled in by
 * hand, so it is the thing most likely to be fed something unexpected. The
 * template and the importer are tested together on purpose — they read the
 * same AttendanceSheet::COLUMNS, and the round-trip test below is what stops
 * them drifting apart.
 */
class AttendanceTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function sheet(array $rows, string $name = 'attendance.csv'): UploadedFile
    {
        $csv = implode(',', AttendanceSheet::COLUMNS)."\n";

        foreach ($rows as $row) {
            $csv .= implode(',', $row)."\n";
        }

        $path = tempnam(sys_get_temp_dir(), 'att').'.csv';
        file_put_contents($path, $csv);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    /* -----------------------------------------------------------------
     | The template CGS starts from
     |------------------------------------------------------------------*/

    public function test_cgs_can_download_the_template_in_both_formats(): void
    {
        $this->actingAs($this->cgs());

        $xlsx = $this->get(route('attendance.template'));
        $xlsx->assertOk();
        $this->assertStringContainsString('.xlsx', $xlsx->headers->get('content-disposition'));

        $csv = $this->get(route('attendance.template', ['format' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString('.csv', $csv->headers->get('content-disposition'));

        // The header row must be exactly what upload() demands, or the
        // template is worse than useless.
        $this->assertStringContainsString(
            implode(',', AttendanceSheet::COLUMNS),
            $csv->streamedContent()
        );
    }

    public function test_a_student_cannot_download_the_template(): void
    {
        $this->actingAs($this->student());

        $this->get(route('attendance.template'))->assertForbidden();
    }

    /**
     * The round trip that matters: the file CGS is handed, filled in and sent
     * straight back, must import cleanly. This is what stops the template and
     * the importer drifting apart.
     */
    public function test_the_downloaded_template_imports_without_edits(): void
    {
        $this->student('22001001', 'one@test.my');
        $this->student('22001002', 'two@test.my');
        $this->actingAs($this->cgs());

        $this->post(route('attendance.upload'), [
            'csv_file' => $this->sheet(AttendanceSheet::sampleRows()),
        ])->assertRedirect();

        $this->assertSame(2, AttendanceRecord::count());
        // 18/20 — derived, never read from the sheet.
        $this->assertSame('90.00', AttendanceRecord::orderBy('id')->first()->percentage);
    }

    /* -----------------------------------------------------------------
     | The import itself
     |------------------------------------------------------------------*/

    public function test_a_bad_row_is_skipped_with_a_reason_and_the_good_rows_still_save(): void
    {
        $this->student('22001001');
        $this->actingAs($this->cgs());

        $response = $this->post(route('attendance.upload'), [
            'csv_file' => $this->sheet([
                ['22001001', '2026-08-31', 18, 20],      // fine
                ['99999999', '2026-08-31', 18, 20],      // nobody
                ['22001001', 'not-a-date', 18, 20],      // unparseable
                ['22001001', '2026-07-31', 25, 20],      // attended > total
                ['22001001', '2026-06-30', 5, 0],        // zero sessions
            ]),
        ]);

        $response->assertRedirect();

        // The good row is saved rather than the whole upload failing.
        $this->assertSame(1, AttendanceRecord::count());

        $warning = session('warning');
        $this->assertNotNull($warning, 'Skipped rows must be reported, not silently dropped.');
        $this->assertStringContainsString('Row 3', $warning);
        $this->assertStringContainsString('99999999', $warning);
    }

    public function test_a_header_that_does_not_match_is_rejected_outright(): void
    {
        $this->actingAs($this->cgs());

        $path = tempnam(sys_get_temp_dir(), 'bad').'.csv';
        file_put_contents($path, "student,date,went,total\n22001001,2026-08-31,18,20\n");

        $this->post(route('attendance.upload'), [
            'csv_file' => new UploadedFile($path, 'bad.csv', 'text/csv', null, true),
        ])->assertRedirect();

        $this->assertNotNull(session('error'));
        $this->assertSame(0, AttendanceRecord::count());
    }

    /**
     * The field that actually breaks real uploads. A CSV opened and re-saved
     * through Excel comes back with the date rewritten into the local format,
     * which used to reach MySQL raw and fail the whole import under strict
     * mode.
     */
    public function test_dates_are_accepted_in_the_formats_excel_writes(): void
    {
        $this->assertSame('2026-08-31', AttendanceSheet::parseDate('2026-08-31'));
        $this->assertSame('2026-08-31', AttendanceSheet::parseDate('31/08/2026'));
        $this->assertSame('2026-08-31', AttendanceSheet::parseDate('31-08-2026'));
        $this->assertSame('2026-08-31', AttendanceSheet::parseDate(new \DateTimeImmutable('2026-08-31')));

        // Day-first, because every date the portal shows is day-first.
        $this->assertSame('2026-04-03', AttendanceSheet::parseDate('03/04/2026'));

        // And nonsense stays nonsense rather than rolling over into a date.
        $this->assertNull(AttendanceSheet::parseDate('31/13/2026'));
        $this->assertNull(AttendanceSheet::parseDate('not-a-date'));
        $this->assertNull(AttendanceSheet::parseDate(''));
    }

    public function test_reuploading_a_period_replaces_it_rather_than_duplicating(): void
    {
        $this->student('22001001');
        $this->actingAs($this->cgs());

        $this->post(route('attendance.upload'), ['csv_file' => $this->sheet([['22001001', '2026-08-31', 10, 20]])]);
        $this->post(route('attendance.upload'), ['csv_file' => $this->sheet([['22001001', '2026-08-31', 18, 20]])]);

        $this->assertSame(1, AttendanceRecord::count());
        $this->assertSame('90.00', AttendanceRecord::first()->percentage);
    }

    /* -----------------------------------------------------------------
     | The early warning the module exists for
     |------------------------------------------------------------------*/

    public function test_falling_below_the_threshold_alerts_the_student_and_supervisor(): void
    {
        Notification::fake();

        $supervisor = $this->supervisor();
        $student = $this->student();
        $student->update(['supervisor_id' => $supervisor->id]);

        $this->actingAs($this->cgs());

        // 12/20 = 60%, well under the mandatory 80%.
        $this->post(route('attendance.upload'), ['csv_file' => $this->sheet([['22001001', '2026-08-31', 12, 20]])]);

        $this->assertTrue(AttendanceRecord::first()->at_risk);
        Notification::assertSentTo($student, AttendanceAtRisk::class);
        Notification::assertSentTo($supervisor, AttendanceAtRisk::class);
    }
}
