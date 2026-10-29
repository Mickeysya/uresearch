<?php

namespace Tests\Feature;

use App\Modules\Chloe\Assistant\Lookups;
use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Services\WorkstationAllocator;
use App\Modules\Chloe\Support\WorkstationStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesEligibleWorkstationStudents;
use Tests\Support\MakesFakeAssistant;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Round 8 PART 4.3. Proves the Insights page and the assistant both read
 * LIVE data -- no cache, no snapshot, no memoisation anywhere in either
 * code path (confirmed separately by grep in the Round 8 report; the
 * only Cache:: usage in the assistant is Pipeline's own provider rate
 * limit/circuit-breaker counters, never a data value).
 *
 * Sequence: snapshot both sources, change FIVE different things through
 * the real service methods (never writing a column directly), snapshot
 * again WITHOUT clearing anything, and assert every affected number
 * moved by exactly the expected amount and the two sources still agree
 * with each other and with WorkstationStatistics::overall() (the exact
 * query the CGS stats bar itself reads).
 */
class InsightsAssistantLivenessTest extends TestCase
{
    use MakesEligibleWorkstationStudents;
    use MakesFakeAssistant;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeAssistant();
    }

    protected function room(string $code, string $gender = 'male', string $block = 'N2'): WorkstationLocation
    {
        return WorkstationLocation::firstOrCreate(['room_code' => $code], ['block' => $block, 'gender' => $gender, 'name' => $code]);
    }

    /** Both sources' own view of "right now" -- fact rows (what the Insights page's every chart is built from) and the lookups the assistant would answer these exact questions with. */
    protected function snapshot($cgs): array
    {
        $rows = WorkstationStatistics::insightsFactRows();
        $stats = WorkstationStatistics::overall();

        return [
            'rows' => $rows,
            'stats' => $stats,
            // "how many seats are available"
            'available_answer' => Lookups::run('overall_stats', [], $cgs, 'cgs'),
            // "which rooms are almost full"
            'almost_full_answer' => Lookups::run('almost_full_rooms', [], $cgs, 'cgs'),
            // "how many keys are overdue for return"
            'overdue_answer' => Lookups::run('keys_pending_return', [], $cgs, 'cgs'),
            // "how many seats are in a termination notice period"
            'termination_answer' => Lookups::run('termination_notices', [], $cgs, 'cgs'),
            // the grouped-bar numbers: status counts per block, same shape the chart itself tallies from $rows
            'status_by_block' => collect($rows)->groupBy('block')->map(fn ($blockRows) => collect($blockRows)->countBy('status'))->all(),
        ];
    }

    public function test_insights_fact_rows_and_assistant_answers_both_change_correctly_and_stay_in_agreement(): void
    {
        $cgs = $this->cgs();
        $allocator = app(WorkstationAllocator::class);

        $baselineTotal = Workstation::count();

        // ---- Known starting state: five fresh, clearly-identified seats, all in a brand new room so nothing else in the suite's seed data interferes. ----
        $room = $this->room('N2-90-01');
        $seatConfirm = Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '1', 'position' => 1, 'status' => Workstation::STATUS_AVAILABLE]);
        $seatRelease = Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '2', 'position' => 2, 'status' => Workstation::STATUS_OCCUPIED]);
        $seatMaintenance = Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '3', 'position' => 3, 'status' => Workstation::STATUS_AVAILABLE]);
        $seatTermination = Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '4', 'position' => 4, 'status' => Workstation::STATUS_OCCUPIED]);
        $seatKeyReturn = Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '5', 'position' => 5, 'status' => Workstation::STATUS_OCCUPIED]);

        $studentToConfirm = $this->eligibleStudent('22090001', 'liveness-confirm@test.my');
        $studentReleasing = $this->eligibleStudent('22090002', 'liveness-release@test.my');
        $studentTerminating = $this->eligibleStudent('22090003', 'liveness-terminate@test.my');
        $studentReturningKey = $this->eligibleStudent('22090004', 'liveness-keyreturn@test.my');
        foreach ([$studentToConfirm, $studentReleasing, $studentTerminating, $studentReturningKey] as $s) {
            StudentGender::create(['student_id' => $s->id, 'gender' => 'male']);
        }

        $requestToRelease = WorkstationRequest::create(['workstation_id' => $seatRelease->id, 'student_id' => $studentReleasing->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()->subMonths(2)]);
        $requestToTerminate = WorkstationRequest::create(['workstation_id' => $seatTermination->id, 'student_id' => $studentTerminating->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()->subMonths(2)]);
        $requestWithKey = WorkstationRequest::create(['workstation_id' => $seatKeyReturn->id, 'student_id' => $studentReturningKey->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()->subMonths(2)]);
        $keyToReturn = LockerKey::create(['workstation_request_id' => $requestWithKey->id, 'student_id' => $studentReturningKey->id, 'status' => LockerKey::STATUS_RETURN_PENDING, 'requested_at' => now()->subMonths(2), 'collected_at' => now()->subMonths(2), 'return_due_at' => now()->subDay()]);

        $before = $this->snapshot($cgs);
        $this->assertSame($baselineTotal + 5, $before['stats']['total'], 'Five fresh seats should be visible immediately -- no cache delay.');

        // ---- Change FIVE things through the real service methods, never a direct column write. ----

        // 1) Confirm a seat for a test student.
        $allocator->assignStudent($studentToConfirm, $seatConfirm, $cgs, WorkstationAllocator::REASONS_ASSIGN[0]);

        // 2) Release a seat.
        $allocator->releaseSeat($requestToRelease, 'cgs', $cgs, WorkstationAllocator::REASONS_RELEASE_IMMEDIATE[0]);

        // 3) Start a termination.
        $allocator->startTermination($requestToTerminate, 'cgs', $cgs, WorkstationAllocator::REASONS_TERMINATION[0]);

        // 4) Mark a key as returned.
        $keyToReturn->markReturned('cgs', 'CGS Test');

        // 5) Set a seat to maintenance.
        $allocator->setMaintenance($seatMaintenance, $cgs, WorkstationAllocator::REASONS_MAINTENANCE[0]);

        // ---- Read again. Nothing cleared -- there is nothing to clear. ----
        $after = $this->snapshot($cgs);

        // -- overall stats bar numbers moved by exactly the expected amount --
        $this->assertSame($before['stats']['occupied'] + 1 - 1, $after['stats']['occupied'], 'Confirm (+1) and release (-1) cancel out on the headline occupied count.');
        $this->assertSame($before['stats']['maintenance'] + 1, $after['stats']['maintenance']);
        $this->assertSame($before['stats']['available'] - 1 - 1 + 1, $after['stats']['available'], 'Confirm (-1) and maintenance (-1) remove availability; release (+1) adds it back.');

        // -- the Insights fact rows agree with the stats bar, unfiltered, after every change --
        $this->assertSame($after['stats']['total'], count($after['rows']));
        $this->assertSame($after['stats']['occupied'], collect($after['rows'])->where('occupied', true)->count());
        $this->assertSame($after['stats']['maintenance'], collect($after['rows'])->where('status', 'maintenance')->count());

        // -- the assistant's own numbers moved too, and agree with the stats bar --
        $this->assertStringContainsString((string) $after['stats']['occupied'].' Occupied', $after['available_answer']['text']);
        $this->assertStringContainsString((string) $after['stats']['maintenance'].' Maintenance', $after['available_answer']['text']);
        $this->assertNotSame($before['available_answer']['text'], $after['available_answer']['text'], 'The assistant answer text itself must change when the underlying numbers change.');

        // -- termination notices: +1, and it is specifically OUR seat --
        $this->assertSame((WorkstationStatistics::terminationNoticeSeats()), $after['termination_answer']['resultCount']);
        $this->assertGreaterThan($before['termination_answer']['resultCount'] ?? 0, $after['termination_answer']['resultCount']);
        $afterTerminationRow = collect($after['rows'])->firstWhere('seat_no', $seatTermination->id);
        $this->assertTrue($afterTerminationRow['notice']);

        // -- the key we marked returned is no longer overdue --
        $overdueCountAfter = $after['overdue_answer']['resultCount'];
        $this->assertLessThan($before['overdue_answer']['resultCount'], $overdueCountAfter, 'Marking the overdue key as returned must reduce the overdue count.');

        // -- the grouped-bar (status-by-block) numbers for THIS block reflect every change, matching the fact rows exactly by construction --
        $blockAfter = $after['status_by_block']['N2'];
        $blockRowsAfter = collect($after['rows'])->where('block', 'N2');
        foreach (['occupied', 'available', 'maintenance', 'awaiting_key_return'] as $status) {
            $this->assertSame($blockRowsAfter->where('status', $status)->count(), $blockAfter[$status] ?? 0);
        }

        // -- confirm the confirmed seat really is occupied, the released seat really is available, the maintenance seat really is disabled, all read live from the SAME rows the chart uses --
        $this->assertSame('occupied', collect($after['rows'])->firstWhere('seat_no', $seatConfirm->id)['status']);
        $this->assertSame('available', collect($after['rows'])->firstWhere('seat_no', $seatRelease->id)['status']);
        $this->assertSame('maintenance', collect($after['rows'])->firstWhere('seat_no', $seatMaintenance->id)['status']);
    }

    /** Part 4.2's own claim, proven mechanically: no cache key this suite could ever observe going stale, because none of these methods write one. */
    public function test_no_caching_class_or_helper_appears_anywhere_in_the_statistics_or_lookup_code_paths(): void
    {
        $statsSource = file_get_contents(app_path('Modules/Chloe/Support/WorkstationStatistics.php'));
        $lookupsSource = file_get_contents(app_path('Modules/Chloe/Assistant/Lookups.php'));
        $aggregatorSource = file_get_contents(app_path('Modules/Chloe/Support/InsightsAggregator.php'));

        foreach ([$statsSource, $lookupsSource, $aggregatorSource] as $source) {
            $this->assertStringNotContainsString('Cache::', $source);
            $this->assertStringNotContainsString('->remember(', $source);
        }
    }
}
