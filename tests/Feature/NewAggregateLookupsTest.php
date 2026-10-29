<?php

namespace Tests\Feature;

use App\Modules\Chloe\Assistant\Lookups;
use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationHistory;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Services\WorkstationAllocator;
use App\Modules\Chloe\Support\WorkstationStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesEligibleWorkstationStudents;
use Tests\Support\MakesFakeAssistant;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Round 8 PART 5.1/5.3/7. The seven new aggregate lookups: available to
 * CGS, listed in config('assistant.aggregate_lookups') (no personal
 * data, so the optional second model call may phrase them), and every
 * number they report equal to the exact same WorkstationStatistics call
 * the Insights page's own data builder makes -- by construction, not by
 * coincidence.
 */
class NewAggregateLookupsTest extends TestCase
{
    use MakesEligibleWorkstationStudents;
    use MakesFakeAssistant;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeAssistant();
        $this->seedVariedData();
    }

    protected function seedVariedData(): void
    {
        $room = WorkstationLocation::firstOrCreate(['room_code' => 'N2-91-01'], ['block' => 'N2', 'gender' => 'male', 'name' => 'N2-91-01']);
        $seat = Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '1', 'position' => 1, 'status' => Workstation::STATUS_OCCUPIED]);
        $student = $this->eligibleStudent('22091001', 'lookup-test@test.my', ['programme' => 'PhD New Lookup Test']);
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $request = WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $student->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()->subMonths(8)]);
        StudyCandidacy::where('student_id', $student->id)->update(['candidacy_expiry_date' => now()->addMonths(2)]);
        LockerKey::create(['workstation_request_id' => $request->id, 'student_id' => $student->id, 'status' => LockerKey::STATUS_RETURN_PENDING, 'requested_at' => now()->subMonths(8), 'collected_at' => now()->subMonths(8), 'return_due_at' => now()->addDays(5)]);

        // Round 8 PART 5.1 follow-up: a seat already released, whose
        // former occupant's key is still outstanding -- Workstation
        // status becomes Awaiting Key Return, NOT a confirmed request
        // any more. The Keys chart's population includes this seat;
        // lockerKeyOverviewForSeatHolders() (scoped to confirmed
        // requests only) does not -- this is the exact case that
        // caught the mismatch.
        $vacatedRoom = WorkstationLocation::firstOrCreate(['room_code' => 'N2-91-02'], ['block' => 'N2', 'gender' => 'male', 'name' => 'N2-91-02']);
        $vacatedSeat = Workstation::create(['workstation_location_id' => $vacatedRoom->id, 'seat_code' => '1', 'position' => 1, 'status' => Workstation::STATUS_OCCUPIED]);
        $formerStudent = $this->eligibleStudent('22091002', 'lookup-test-vacated@test.my', ['programme' => 'PhD New Lookup Test']);
        StudentGender::create(['student_id' => $formerStudent->id, 'gender' => 'male']);
        $vacatedRequest = WorkstationRequest::create(['workstation_id' => $vacatedSeat->id, 'student_id' => $formerStudent->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()->subMonths(5)]);
        LockerKey::create(['workstation_request_id' => $vacatedRequest->id, 'student_id' => $formerStudent->id, 'status' => LockerKey::STATUS_COLLECTED, 'requested_at' => now()->subMonths(5), 'collected_at' => now()->subMonths(5)]);
        // A separate, dedicated actor row -- not $this->cgs() -- that
        // helper always INSERTs a fresh row (see MakesUsers::user()),
        // and every test method below also calls $this->cgs() itself;
        // calling it here too would insert 'cgs@test.my' twice and
        // violate its unique email constraint.
        $releaseActor = \App\Modules\Core\Models\User::create([
            'name' => 'Seed Release Actor', 'email' => 'lookup-test-release-actor@test.my',
            'password' => 'password', 'role' => \App\Modules\Core\Support\Role::NON_EXEC_CGS,
        ]);
        app(WorkstationAllocator::class)->releaseSeat($vacatedRequest, WorkstationHistory::ACTOR_CGS, $releaseActor, 'System error');
    }

    public static function lookupProvider(): array
    {
        return [
            'programme_breakdown' => ['programme_breakdown'],
            'gender_comparison' => ['gender_comparison'],
            'key_status_overview' => ['key_status_overview'],
            'candidacy_expiry_distribution' => ['candidacy_expiry_distribution'],
            'length_of_stay_distribution' => ['length_of_stay_distribution'],
            'seat_status_by_block' => ['seat_status_by_block'],
            'return_outlook' => ['return_outlook'],
        ];
    }

    /** @dataProvider lookupProvider */
    public function test_every_new_lookup_is_cgs_only_and_in_the_aggregate_list(string $name): void
    {
        $this->assertTrue(Lookups::isAllowed($name, \App\Modules\Core\Support\Role::NON_EXEC_CGS));
        $this->assertFalse(Lookups::isAllowed($name, \App\Modules\Core\Support\Role::STUDENT));
        $this->assertContains($name, config('assistant.aggregate_lookups'), "{$name} must be in aggregate_lookups -- it returns no personal data.");
    }

    /** @dataProvider lookupProvider */
    public function test_every_new_lookup_answer_contains_no_pii(string $name): void
    {
        $cgs = $this->cgs();
        $result = Lookups::run($name, [], $cgs, 'cgs');

        $this->assertStringNotContainsString('Ahmad Danial', $result['text']);
        $this->assertStringNotContainsString('22091001', $result['text']);
        $this->assertStringNotContainsString('@', $result['text']);
    }

    public function test_programme_breakdown_matches_the_insights_pages_own_statistics_function(): void
    {
        $cgs = $this->cgs();
        $result = Lookups::run('programme_breakdown', [], $cgs, 'cgs');
        $expected = WorkstationStatistics::programmeBreakdown(8);

        foreach ($expected as $row) {
            $this->assertStringContainsString("{$row['label']}: {$row['count']} seat holder(s)", $result['text']);
        }
    }

    public function test_gender_comparison_matches_occupancy_by_gender(): void
    {
        $cgs = $this->cgs();
        $result = Lookups::run('gender_comparison', [], $cgs, 'cgs');
        $expected = WorkstationStatistics::occupancyByGender();

        foreach ($expected as $row) {
            $this->assertStringContainsString("{$row['occupied']}/{$row['total']} occupied ({$row['occupied_percent']}%)", $result['text']);
        }
    }

    public function test_seat_status_by_block_matches_the_same_source_as_the_grouped_bar_chart(): void
    {
        $cgs = $this->cgs();
        $result = Lookups::run('seat_status_by_block', [], $cgs, 'cgs');
        $expected = WorkstationStatistics::seatStatusByBlock();
        $rows = WorkstationStatistics::insightsFactRows();

        foreach ($expected as $block => $s) {
            $this->assertStringContainsString("Block {$block}: {$s['occupied']} Occupied", $result['text']);
            // Same shape the chart itself would tally from the SAME fact rows.
            $chartOccupied = collect($rows)->where('block', $block)->where('status', 'occupied')->count();
            $this->assertSame($chartOccupied, $s['occupied']);
        }
    }

    public function test_return_outlook_matches_key_return_outlook(): void
    {
        $cgs = $this->cgs();
        $result = Lookups::run('return_outlook', [], $cgs, 'cgs');
        $expected = WorkstationStatistics::keyReturnOutlook();

        $this->assertStringContainsString("{$expected['due_7_days']} key(s) due within 7 days", $result['text']);
        $this->assertStringContainsString("{$expected['overdue']} overdue", $result['text']);
    }

    /**
     * Round 8 PART 5.1 follow-up: found live -- key_status_overview
     * originally called lockerKeyOverviewForSeatHolders(), which scopes
     * to CONFIRMED requests only and so silently excludes a seat
     * already Awaiting Key Return (its former occupant's key is still
     * outstanding). The Keys chart's own population (every fact row
     * with key !== 'none') DOES include those, so the two disagreed on
     * Return Pending (0 vs the chart's real count). This test tallies
     * directly from insightsFactRows() -- the exact same rows
     * keysGroups() in chloe-workstation-insights.js groups by block and
     * bar -- independently of any WorkstationStatistics helper, so it
     * cannot pass by the lookup and the test sharing the same bug.
     */
    public function test_key_status_overview_matches_a_tally_taken_directly_from_the_same_fact_rows_the_keys_chart_groups(): void
    {
        $cgs = $this->cgs();
        $result = Lookups::run('key_status_overview', [], $cgs, 'cgs');

        $rows = collect(WorkstationStatistics::insightsFactRows())->where('key', '!=', 'none');
        $expected = [
            'not_requested' => $rows->where('key', 'not_requested')->count(),
            'pending_collection' => $rows->where('key', 'pending_collection')->count(),
            'collected' => $rows->where('key', 'collected')->count(),
            'return_pending' => $rows->where('key', 'return_pending')->count(),
        ];

        $this->assertGreaterThan(0, $expected['return_pending'], 'Fixture must include at least one Awaiting Key Return seat for this test to be meaningful.');
        $this->assertStringContainsString("Among {$rows->count()} current seat holder(s): {$expected['not_requested']} Not Requested, {$expected['pending_collection']} Pending Collection, {$expected['collected']} Collected, {$expected['return_pending']} Return Pending.", $result['text']);
    }

    public function test_block_and_gender_parameters_narrow_the_scope_and_invalid_block_asks_back(): void
    {
        $cgs = $this->cgs();

        $n2Only = Lookups::run('key_status_overview', ['block' => 'N2'], $cgs, 'cgs');
        $expected = WorkstationStatistics::keyStatusOverviewFromFactRows(null, 'N2');
        $this->assertStringContainsString("Among ".array_sum($expected)." current seat holder(s)", $n2Only['text']);

        $invalid = Lookups::run('key_status_overview', ['block' => 'X9'], $cgs, 'cgs');
        $this->assertSame('asked_back', $invalid['outcome']);
    }

    public function test_the_payload_given_to_a_second_model_call_for_a_new_lookup_contains_only_aggregated_numbers(): void
    {
        $cgs = $this->cgs();
        $result = Lookups::run('seat_status_by_block', [], $cgs, 'cgs');

        // The exact text Pipeline would hand the model for the optional
        // second call IS this structured text -- assert it is built
        // purely from WorkstationStatistics::seatStatusByBlock()'s own
        // numbers (counts and block codes), never a name/ID/email.
        $this->assertMatchesRegularExpression('/^(Block (N2|J2): \d+ Occupied, \d+ Available, \d+ Maintenance, \d+ Awaiting Key Return \(of \d+\)(; )?)+$/', $result['text']);
    }
}
