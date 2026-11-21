<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\CgsDashboard;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Support\AttendanceRiskEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The CGS dashboard's labels: the Attendance Alerts bands (Good / Watch /
 * Below 80%, on the attendance rule's own lines, no overlap), the "Pending
 * at CGS" wording, and the month-over-month arrow's minimum baseline.
 */
class CgsDashboardTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    /** One student whose latest period is $attended of 100 sessions, i.e. $attended%. */
    protected function studentAt(int $attended, string $matric): void
    {
        $student = $this->student($matric, "s{$matric}@test.my");
        AttendanceRecord::recordPeriod($student->id, '2026-10-30', $attended, 100);
    }

    public function test_every_tracked_student_is_in_exactly_one_band_and_the_counts_add_up(): void
    {
        // 85% is the Good line and 80% the Watch line, each inclusive.
        $this->assertSame(80.0, AttendanceRiskEvaluator::THRESHOLD);
        $this->assertSame(85.0, AttendanceRiskEvaluator::THRESHOLD + AttendanceRiskEvaluator::EARLY_WARNING_BAND);

        $this->studentAt(92, '22001001'); // Good
        $this->studentAt(85, '22001002'); // Good (on the line)
        $this->studentAt(84, '22001003'); // Watch
        $this->studentAt(80, '22001004'); // Watch (on the line)
        $this->studentAt(79, '22001005'); // Below 80%

        $alerts = (new CgsDashboard($this->cgs()))->attendanceAlerts();
        $bands = $alerts['bands']->keyBy('tone');

        $this->assertSame(['Good (85% and above)', 'Watch (80% to below 85%)', 'Below 80%'], $alerts['bands']->pluck('label')->all());
        $this->assertSame(2, $bands['good']['count']);
        $this->assertSame(2, $bands['warn']['count']);
        $this->assertSame(1, $bands['critical']['count']);

        $this->assertSame(5, $alerts['total']);
        $this->assertSame($alerts['total'], $alerts['bands']->sum('count'));
        // The footer is the Below band, not a second, overlapping figure.
        $this->assertSame(1, $alerts['belowThreshold']);
    }

    public function test_the_card_shows_the_three_bands_and_the_footer(): void
    {
        $this->studentAt(90, '22001001');
        $this->studentAt(82, '22001002');
        $this->studentAt(70, '22001003');

        $this->actingAs($this->cgs())->get(route('dashboard'))->assertOk()
            ->assertSee('3 students tracked')
            ->assertSee('Good (85% and above)')
            ->assertSee('Watch (80% to below 85%)')
            ->assertSee('Below 80%')
            ->assertSee('1 student is below the 80% threshold.')
            ->assertDontSee('Warning (75% – 84%)')
            ->assertDontSee('Critical (&lt; 75%)', false);
    }

    public function test_pending_is_worded_as_waiting_at_cgs(): void
    {
        $this->actingAs($this->cgs())->get(route('dashboard'))->assertOk()
            ->assertSee('Pending at CGS')
            ->assertDontSee('Pending My Action')
            ->assertDontSee('pending your action');
    }

    public function test_the_trend_needs_at_least_five_last_month(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00'));
        $student = $this->student();
        $submit = fn (string $at) => Application::create([
            'student_id' => $student->id, 'module_type' => 'travel',
            'status' => Application::STATUS_PENDING, 'current_stage' => 'supervisor', 'submitted_at' => $at,
        ]);

        // 4 last month, 40 this month: no arrow, rather than "↑ 900%".
        foreach (range(1, 4) as $i) {
            $submit('2026-10-10 09:00');
        }
        foreach (range(1, 40) as $i) {
            $submit('2026-11-10 09:00');
        }

        $cgs = $this->cgs();
        $this->assertNull((new CgsDashboard($cgs))->totalApplications()['delta']);

        // A fifth last month is enough to compare: 5 -> 40 is +700%.
        $submit('2026-10-11 09:00');

        $this->assertSame(700.0, (new CgsDashboard($cgs))->totalApplications()['delta']);
    }
}
