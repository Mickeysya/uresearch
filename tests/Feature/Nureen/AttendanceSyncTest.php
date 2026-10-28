<?php

namespace Tests\Feature\Nureen;

use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Models\AttendanceSyncRun;
use App\Modules\Nureen\Notifications\AttendanceAtRisk;
use App\Modules\Nureen\Support\AttendanceSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Automated attendance ingestion -- the supervisor's "CGS staff lacks
 * automation" feedback, for Attendance specifically.
 *
 * Three real sources feed the same AttendanceIngestor the manual upload
 * already used (AttendanceTest covers that path and is untouched by this
 * work): InboxFolderSource (this file), PushEndpointSource
 * (AttendanceIngestEndpointTest), SimulatedSource (this file). All three
 * share the idempotency guarantee AttendanceRecord::recordPeriod() already
 * gave the manual upload -- running a sync twice never duplicates a period,
 * it reports the second run as "updated" instead of "added".
 */
class AttendanceSyncTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected string $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        // A fresh folder per test, not the real storage/app path, so tests
        // never collide with each other or with a real demo run.
        $this->inbox = sys_get_temp_dir().'/attendance-inbox-test-'.uniqid();
        config(['nureen.attendance.source' => 'inbox', 'nureen.attendance.inbox_path' => $this->inbox]);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->inbox)) {
            File::deleteDirectory($this->inbox);
        }

        parent::tearDown();
    }

    protected function writeCsv(array $rows, string $name = 'export.csv'): void
    {
        File::ensureDirectoryExists($this->inbox);

        $csv = implode(',', AttendanceSheet::COLUMNS)."\n";
        foreach ($rows as $row) {
            $csv .= implode(',', $row)."\n";
        }

        File::put($this->inbox.'/'.$name, $csv);
    }

    /* -----------------------------------------------------------------
     | InboxFolderSource
     |------------------------------------------------------------------*/

    public function test_a_file_in_the_inbox_is_ingested_and_moved_to_processed(): void
    {
        $this->student('22001001');
        $this->writeCsv([['22001001', '2026-08-31', 18, 20]]);

        $this->artisan('attendance:sync')->assertSuccessful();

        $this->assertSame(1, AttendanceRecord::count());
        $this->assertFileExists($this->inbox.'/processed/export.csv');
        $this->assertFileDoesNotExist($this->inbox.'/export.csv');
    }

    public function test_a_file_with_a_bad_header_is_moved_to_failed_with_a_reason(): void
    {
        File::ensureDirectoryExists($this->inbox);
        File::put($this->inbox.'/bad.csv', "student,date,went,total\n22001001,2026-08-31,18,20\n");

        $this->artisan('attendance:sync')->assertSuccessful();

        $this->assertSame(0, AttendanceRecord::count());
        $this->assertFileExists($this->inbox.'/failed/bad.csv');
        $this->assertFileExists($this->inbox.'/failed/bad.csv.reason.txt');
    }

    public function test_running_sync_twice_does_not_duplicate_a_record(): void
    {
        $this->student('22001001');
        $this->writeCsv([['22001001', '2026-08-31', 10, 20]], 'first.csv');
        $this->artisan('attendance:sync')->assertSuccessful();

        $this->writeCsv([['22001001', '2026-08-31', 18, 20]], 'second.csv');
        $this->artisan('attendance:sync')->assertSuccessful();

        $this->assertSame(1, AttendanceRecord::count());
        $this->assertSame('90.00', AttendanceRecord::first()->percentage);

        $runs = AttendanceSyncRun::orderBy('id')->get();
        $this->assertSame(1, $runs[0]->rows_added);
        $this->assertSame(0, $runs[0]->rows_updated);
        $this->assertSame(0, $runs[1]->rows_added);
        $this->assertSame(1, $runs[1]->rows_updated);
    }

    public function test_an_invalid_row_is_rejected_with_a_reason_and_good_rows_still_import(): void
    {
        $this->student('22001001');
        $this->writeCsv([
            ['22001001', '2026-08-31', 18, 20], // fine
            ['99999999', '2026-08-31', 18, 20], // nobody
        ]);

        $this->artisan('attendance:sync')->assertSuccessful();

        $this->assertSame(1, AttendanceRecord::count());

        $run = AttendanceSyncRun::sole();
        $this->assertSame(1, $run->rows_added);
        $this->assertSame(1, $run->rows_rejected);
        $this->assertStringContainsString('99999999', $run->rejection_reasons[0]);
    }

    public function test_risk_evaluation_and_the_at_risk_notification_run_after_a_sync(): void
    {
        Notification::fake();

        $supervisor = $this->supervisor();
        $student = $this->student();
        $student->update(['supervisor_id' => $supervisor->id]);

        // 12/20 = 60%, well under the mandatory 80%.
        $this->writeCsv([['22001001', '2026-08-31', 12, 20]]);

        $this->artisan('attendance:sync')->assertSuccessful();

        $this->assertTrue(AttendanceRecord::first()->at_risk);
        Notification::assertSentTo($student, AttendanceAtRisk::class);
        Notification::assertSentTo($supervisor, AttendanceAtRisk::class);
    }

    /* -----------------------------------------------------------------
     | SimulatedSource
     |------------------------------------------------------------------*/

    public function test_the_simulated_source_generates_one_record_per_student_and_is_idempotent(): void
    {
        config(['nureen.attendance.source' => 'simulated']);

        $this->student('22001001', 'one@test.my');
        $this->student('22001002', 'two@test.my');

        $this->artisan('attendance:sync')->assertSuccessful();
        $this->assertSame(2, AttendanceRecord::count());

        // Running again this week must not add a second record per student.
        $this->artisan('attendance:sync')->assertSuccessful();
        $this->assertSame(2, AttendanceRecord::count());
    }

    /* -----------------------------------------------------------------
     | Sync Now (on-demand trigger from the upload page)
     |------------------------------------------------------------------*/

    public function test_cgs_can_trigger_a_sync_on_demand_from_the_upload_page(): void
    {
        $this->student('22001001');
        $this->writeCsv([['22001001', '2026-08-31', 18, 20]]);

        $this->actingAs($this->cgs())
            ->post(route('attendance.sync-now'))
            ->assertRedirect();

        $this->assertSame(1, AttendanceRecord::count());
        $this->assertNotNull(session('status'));
    }

    public function test_a_student_cannot_trigger_a_sync(): void
    {
        $this->actingAs($this->student())
            ->post(route('attendance.sync-now'))
            ->assertForbidden();
    }

    public function test_the_upload_page_shows_the_active_source_and_last_sync_summary(): void
    {
        $this->student('22001001');
        $this->writeCsv([['22001001', '2026-08-31', 18, 20]]);
        $this->artisan('attendance:sync');

        $this->actingAs($this->cgs())
            ->get(route('attendance.upload.form'))
            ->assertOk()
            ->assertSee('Inbox Folder')
            ->assertSee('Manual Fallback');
    }
}
