<?php

namespace Tests\Feature\Nureen;

use App\Modules\Nureen\Models\AttendanceIngestQueueEntry;
use App\Modules\Nureen\Models\AttendanceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The API-key-protected ingestion endpoint (one of the three AttendanceSource
 * options, alongside the inbox folder and the simulated source --
 * AttendanceSyncTest covers those two).
 *
 * This route sits outside `auth`, authenticated by a shared key instead of a
 * session -- see routes.php and SHARED_CHANGES.md for the CSRF exemption a
 * real external caller needs in bootstrap/app.php. Laravel's test client does
 * not enforce CSRF (the same as every other POST test in this suite), so
 * this is fully exercised regardless of whether that line has landed yet.
 */
class AttendanceIngestEndpointTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nureen.attendance.ingest_key' => 'test-secret-key']);
    }

    public function test_a_request_without_the_api_key_is_rejected(): void
    {
        $this->postJson(route('attendance.ingest'), [
            'records' => [['matric_no' => '22001001', 'period_end' => '2026-08-31', 'sessions_attended' => 18, 'sessions_total' => 20]],
        ])->assertUnauthorized();

        $this->assertSame(0, AttendanceIngestQueueEntry::count());
    }

    public function test_a_request_with_the_wrong_api_key_is_rejected(): void
    {
        $this->withHeaders(['X-Api-Key' => 'not-the-key'])
            ->postJson(route('attendance.ingest'), [
                'records' => [['matric_no' => '22001001', 'period_end' => '2026-08-31', 'sessions_attended' => 18, 'sessions_total' => 20]],
            ])->assertUnauthorized();

        $this->assertSame(0, AttendanceIngestQueueEntry::count());
    }

    public function test_a_valid_request_stages_records_without_ingesting_them_immediately(): void
    {
        $this->student('22001001');

        $this->withHeaders(['X-Api-Key' => 'test-secret-key'])
            ->postJson(route('attendance.ingest'), [
                'records' => [['matric_no' => '22001001', 'period_end' => '2026-08-31', 'sessions_attended' => 18, 'sessions_total' => 20]],
            ])
            ->assertStatus(202)
            ->assertJson(['staged' => 1]);

        $this->assertSame(1, AttendanceIngestQueueEntry::count());
        // Staged, not yet ingested -- that happens on the next sync.
        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_an_empty_records_array_is_rejected(): void
    {
        $this->withHeaders(['X-Api-Key' => 'test-secret-key'])
            ->postJson(route('attendance.ingest'), ['records' => []])
            ->assertStatus(422);
    }

    public function test_the_next_sync_run_consumes_staged_records_and_marks_them_processed(): void
    {
        config(['nureen.attendance.source' => 'api']);
        $this->student('22001001');

        $this->withHeaders(['X-Api-Key' => 'test-secret-key'])
            ->postJson(route('attendance.ingest'), [
                'records' => [['matric_no' => '22001001', 'period_end' => '2026-08-31', 'sessions_attended' => 18, 'sessions_total' => 20]],
            ]);

        $this->artisan('attendance:sync')->assertSuccessful();

        $this->assertSame(1, AttendanceRecord::count());
        $this->assertNotNull(AttendanceIngestQueueEntry::sole()->processed_at);

        // A second sync with nothing new staged must not re-ingest it.
        $this->artisan('attendance:sync')->assertSuccessful();
        $this->assertSame(1, AttendanceRecord::count());
    }
}
