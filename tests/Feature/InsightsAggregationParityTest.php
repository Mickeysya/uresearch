<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Models\WorkstationTerminationNotice;
use App\Modules\Chloe\Support\InsightsAggregator;
use App\Modules\Chloe\Support\WorkstationStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Round 5 PART G.4/H. Proves public/js/chloe-insights-aggregate.js (the
 * real, client-side aggregation) computes EXACTLY what
 * App\Modules\Chloe\Support\InsightsAggregator (the PHP reference)
 * computes, for the same rows and the same filter scenarios -- by
 * actually running the JS file under Node as a subprocess, something
 * PHPUnit's own test client cannot do for a browser page. Skipped
 * cleanly (not a failure) if Node is not on the PATH, since Node is a
 * dev-time nice-to-have here, not a project dependency.
 */
class InsightsAggregationParityTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Process::run('node --version')->successful()) {
            $this->markTestSkipped('Node is not available in this environment.');
        }
    }

    protected function seedVariedRows(): void
    {
        $maleRoom = WorkstationLocation::firstOrCreate(['room_code' => 'N2-09-01'], ['block' => 'N2', 'gender' => 'male', 'name' => 'N2-09-01']);
        $femaleRoom = WorkstationLocation::firstOrCreate(['room_code' => 'J2-09-01'], ['block' => 'J2', 'gender' => 'female', 'name' => 'J2-09-01']);

        // An available seat, a maintenance seat, and three occupied
        // seats spanning different programmes/expiries/stays/notices,
        // so every dimension has more than one category to compare.
        Workstation::create(['workstation_location_id' => $maleRoom->id, 'seat_code' => '1', 'position' => 1, 'status' => Workstation::STATUS_AVAILABLE]);
        Workstation::create(['workstation_location_id' => $maleRoom->id, 'seat_code' => '2', 'position' => 2, 'status' => Workstation::STATUS_DISABLED]);

        $seatA = Workstation::create(['workstation_location_id' => $maleRoom->id, 'seat_code' => '3', 'position' => 3, 'status' => Workstation::STATUS_OCCUPIED]);
        $studentA = $this->student('22005001', 'parity-a@test.my', ['programme' => 'MSc Alpha']);
        $requestA = WorkstationRequest::create(['workstation_id' => $seatA->id, 'student_id' => $studentA->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()->subMonths(8)]);
        StudyCandidacy::create(['student_id' => $studentA->id, 'status' => StudyCandidacy::STATUS_ACTIVE, 'programme_start_date' => now()->subMonths(8), 'candidacy_expiry_date' => now()->addMonths(2)]);
        LockerKey::create(['workstation_request_id' => $requestA->id, 'student_id' => $studentA->id, 'status' => LockerKey::STATUS_COLLECTED, 'requested_at' => now()->subMonths(8), 'collected_at' => now()->subMonths(8)]);

        $seatB = Workstation::create(['workstation_location_id' => $femaleRoom->id, 'seat_code' => '4', 'position' => 1, 'status' => Workstation::STATUS_OCCUPIED]);
        $studentB = $this->student('22005002', 'parity-b@test.my', ['programme' => 'PhD Beta']);
        $requestB = WorkstationRequest::create(['workstation_id' => $seatB->id, 'student_id' => $studentB->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()->subMonths(14)]);
        StudyCandidacy::create(['student_id' => $studentB->id, 'status' => StudyCandidacy::STATUS_ACTIVE, 'programme_start_date' => now()->subMonths(14), 'candidacy_expiry_date' => now()->addMonths(9)]);
        WorkstationTerminationNotice::create(['workstation_request_id' => $requestB->id, 'reason' => 'completed', 'notice_date' => now(), 'vacate_by_date' => now()->addMonth(), 'status' => WorkstationTerminationNotice::STATUS_ACTIVE]);
        LockerKey::create(['workstation_request_id' => $requestB->id, 'student_id' => $studentB->id, 'status' => LockerKey::STATUS_REQUESTED, 'requested_at' => now()->subMonths(14)]);

        $seatC = Workstation::create(['workstation_location_id' => $maleRoom->id, 'seat_code' => '5', 'position' => 4, 'status' => Workstation::STATUS_OCCUPIED]);
        $studentC = $this->student('22005003', 'parity-c@test.my', ['programme' => 'MSc Alpha']);
        $requestC = WorkstationRequest::create(['workstation_id' => $seatC->id, 'student_id' => $studentC->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()->subMonths(20)]);
        StudyCandidacy::create(['student_id' => $studentC->id, 'status' => StudyCandidacy::STATUS_ACTIVE, 'programme_start_date' => now()->subMonths(20), 'candidacy_expiry_date' => now()->addMonths(5)]);
    }

    /** @return array<string, array<int, string>> every one of the 11 dimensions present, all empty, unless overridden by $set */
    protected function dims(array $set = []): array
    {
        return array_merge([
            'block' => [], 'room' => [], 'gender' => [], 'status' => [], 'programme' => [], 'key' => [],
            'expiry' => [], 'stay' => [], 'release' => [], 'source' => [], 'due' => [],
        ], $set);
    }

    protected function scenarios(): array
    {
        $nowMonth = now()->format('Y-m');

        return [
            ['op' => 'context', 'dim' => 'block'],
            ['op' => 'context', 'dim' => 'programme'],
            ['op' => 'context', 'dim' => 'expiry'],
            ['op' => 'value', 'dim' => 'key', 'filters' => $this->dims(['block' => ['N2']])],
            ['op' => 'value', 'dim' => 'stay', 'filters' => $this->dims(['gender' => ['female']])],
            ['op' => 'highlightCounts', 'dim' => 'programme', 'filters' => $this->dims(), 'highlight' => $this->dims(['gender' => ['male']])],
            ['op' => 'highlightCounts', 'dim' => 'status', 'filters' => $this->dims(['block' => ['N2']]), 'highlight' => $this->dims(['programme' => ['MSc Alpha']])],
            ['op' => 'summaryCounts', 'filters' => $this->dims(['programme' => ['MSc Alpha']]), 'highlight' => $this->dims()],
            ['op' => 'summaryCounts', 'filters' => $this->dims(['block' => ['N2']]), 'highlight' => $this->dims(['status' => ['occupied']])],
            ['op' => 'filteredRowsCount', 'filters' => $this->dims(['key' => ['collected', 'pending_collection']])],
            [
                'op' => 'forecastMonths',
                'filters' => $this->dims(),
                'roomScopeFilters' => ['block' => [], 'room' => [], 'gender' => []],
                'nowMonth' => $nowMonth,
                'maxMonths' => 36,
            ],
            [
                'op' => 'groupPeriods',
                'filters' => $this->dims(),
                'roomScopeFilters' => ['block' => [], 'room' => [], 'gender' => []],
                'nowMonth' => $nowMonth,
                'maxMonths' => 36,
                'grain' => 'quarter',
            ],
        ];
    }

    public function test_the_js_aggregation_matches_the_php_reference_for_every_scenario(): void
    {
        $this->seedVariedRows();
        $rows = WorkstationStatistics::insightsFactRows();
        $scenarios = $this->scenarios();

        $phpResults = array_map(fn ($s) => $this->runPhpReference($rows, $s), $scenarios);

        $inputPath = tempnam(sys_get_temp_dir(), 'insights_parity_').'.json';
        File::put($inputPath, json_encode(['rows' => $rows, 'scenarios' => $scenarios]));

        $runnerPath = base_path('tests/node/insights_parity_runner.js');
        $process = Process::run(['node', $runnerPath, $inputPath]);
        File::delete($inputPath);

        $this->assertTrue($process->successful(), 'Node runner failed: '.$process->errorOutput());

        $jsResults = json_decode($process->output(), true);

        $this->assertNotNull($jsResults, 'Node runner did not print valid JSON: '.$process->output());
        $this->assertCount(count($phpResults), $jsResults);

        foreach ($phpResults as $i => $expected) {
            $this->assertEquals($expected, $jsResults[$i], "Scenario #{$i} ({$scenarios[$i]['op']}) diverged between PHP and JS.");
        }
    }

    protected function runPhpReference(array $rows, array $scenario): mixed
    {
        return match ($scenario['op']) {
            'context' => InsightsAggregator::context($rows, $scenario['dim']),
            'value' => InsightsAggregator::value($rows, $scenario['dim'], $scenario['filters']),
            'highlightCounts' => InsightsAggregator::highlightCounts(InsightsAggregator::filteredRows($rows, $scenario['filters']), $scenario['dim'], $scenario['highlight']),
            'summaryCounts' => InsightsAggregator::summaryCounts(InsightsAggregator::filteredRows($rows, $scenario['filters']), $scenario['filters'], $scenario['highlight']),
            'forecastMonths' => InsightsAggregator::forecastMonths($rows, $scenario['filters'], $scenario['roomScopeFilters'], $scenario['nowMonth'], $scenario['maxMonths']),
            'groupPeriods' => InsightsAggregator::groupPeriods(InsightsAggregator::forecastMonths($rows, $scenario['filters'], $scenario['roomScopeFilters'], $scenario['nowMonth'], $scenario['maxMonths'])['months'], $scenario['grain']),
            'filteredRowsCount' => count(InsightsAggregator::filteredRows($rows, $scenario['filters'])),
        };
    }
}
