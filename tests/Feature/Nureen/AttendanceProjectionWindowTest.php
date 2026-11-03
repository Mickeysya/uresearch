<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Models\AttendanceRiskState;
use App\Modules\Nureen\Support\AttendanceEarlyWarning;
use App\Modules\Nureen\Support\AttendanceRiskEvaluator;
use App\Modules\Nureen\Support\Backtest\AttendanceBacktester;
use App\Modules\Nureen\Support\Backtest\SyntheticAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * When the projection may flag: from projection_min_periods_to_flag
 * periods, up to projection_active_until of the semester. Outside that
 * window it is still computed (CGS sees the figure) but only the rule
 * flags -- in the live check AND the backtest, which share
 * AttendanceRiskEvaluator::projectionMayFlag().
 */
class AttendanceProjectionWindowTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config([
            'nureen.attendance.semester_start' => null,
            'nureen.attendance.semester_periods' => 14,
            'nureen.attendance.projection_min_periods' => 3,
            'nureen.attendance.projection_min_periods_to_flag' => 4,
            'nureen.attendance.projection_active_until' => 0.6,
        ]);
    }

    /** One weekly row per entry, 10 sessions each, then the live check. */
    protected function weeks(array $attended, string $matric = '22001001'): User
    {
        $student = $this->student($matric, "s{$matric}@test.my");
        foreach ($attended as $i => $count) {
            AttendanceRecord::recordPeriod($student->id, CarbonImmutable::parse('2026-09-04')->addWeeks($i)->toDateString(), $count, 10);
        }
        app(AttendanceEarlyWarning::class)->run([$student->id]);

        return $student;
    }

    protected function state(User $student): AttendanceRiskState
    {
        return AttendanceRiskState::where('student_id', $student->id)->sole();
    }

    public function test_the_window_runs_from_the_minimum_periods_to_the_active_share(): void
    {
        // Defaults: from period 4, up to 0.6 x 14 = 8.4, so period 8.
        $this->assertFalse(AttendanceRiskEvaluator::projectionMayFlag(3, 14));
        $this->assertTrue(AttendanceRiskEvaluator::projectionMayFlag(4, 14));
        $this->assertTrue(AttendanceRiskEvaluator::projectionMayFlag(8, 14));
        $this->assertFalse(AttendanceRiskEvaluator::projectionMayFlag(9, 14));

        // Explicit values win over config; the end is inclusive (0.5 x 14 = 7).
        $this->assertTrue(AttendanceRiskEvaluator::projectionMayFlag(7, 14, 3, 0.5));
        $this->assertFalse(AttendanceRiskEvaluator::projectionMayFlag(8, 14, 3, 0.5));
        $this->assertTrue(AttendanceRiskEvaluator::projectionMayFlag(3, 14, 3, 1.0));
        $this->assertTrue(AttendanceRiskEvaluator::projectionMayFlag(14, 14, 3, 1.0));
    }

    public function test_before_the_minimum_the_projection_is_computed_but_does_not_flag(): void
    {
        // 100, 100, 90%: projected 69.2%, but only three periods so far.
        $student = $this->weeks([10, 10, 9]);

        $this->assertFalse($this->state($student)->at_risk);
        $this->assertNull($this->state($student)->reason);
        $this->assertSame(69.2, $this->state($student)->projected_percentage);
        Notification::assertNothingSent();

        // The original setting (flag from the first projection) would have.
        config(['nureen.attendance.projection_min_periods_to_flag' => 3]);
        app(AttendanceEarlyWarning::class)->run([$student->id]);
        $this->assertSame('projected', $this->state($student)->reason);
    }

    public function test_inside_the_window_the_projection_flags(): void
    {
        $student = $this->weeks([10, 10, 9, 9]);

        $this->assertSame('projected', $this->state($student)->reason);
        $this->assertSame(75.0, $this->state($student)->projected_percentage);
    }

    public function test_after_the_active_share_only_the_rule_flags(): void
    {
        // Window ending at 0.25 x 14 = 3.5: period 4 is past it.
        config(['nureen.attendance.projection_min_periods_to_flag' => 3, 'nureen.attendance.projection_active_until' => 0.25]);

        $student = $this->weeks([10, 10, 9, 9]);
        $this->assertFalse($this->state($student)->at_risk);
        $this->assertSame(75.0, $this->state($student)->projected_percentage);

        // The rule itself still applies late in the semester.
        $belowThreshold = $this->weeks([10, 10, 9, 7], '22001002');
        $this->assertSame('current', $this->state($belowThreshold)->reason);
    }

    /**
     * The guarantee behind the backtest: replaying a student's semester in
     * memory gives, at every period, exactly the live check's verdict on
     * the periods recorded so far -- for both configurations compared.
     */
    public function test_the_backtest_replay_matches_the_live_check_period_by_period(): void
    {
        $data = (new SyntheticAttendance(2026))->generate(21, 14);

        foreach ([[3, 1.0], [4, 0.6]] as [$minToFlag, $activeUntil]) {
            config(['nureen.attendance.projection_min_periods_to_flag' => $minToFlag, 'nureen.attendance.projection_active_until' => $activeUntil]);
            $backtester = new AttendanceBacktester(14, 3, AttendanceRiskEvaluator::THRESHOLD, $minToFlag, $activeUntil);
            $flagsSeen = 0;

            foreach ($data['students'] as $matric => $periods) {
                $student = $this->student($matric.$minToFlag, "{$matric}-{$minToFlag}@test.my");
                $replay = $backtester->replay($periods);

                foreach ($periods as $i => $period) {
                    AttendanceRecord::recordPeriod($student->id, $period['end'], $period['attended'], $period['total']);
                    $live = AttendanceRiskEvaluator::assess($student->id);
                    $step = $replay['steps'][$i + 1];

                    $this->assertSame($step['rule'] || $step['projection'], $live['at_risk'], "{$matric}, period ".($i + 1).", window {$minToFlag}/{$activeUntil}");
                    $this->assertSame($step['projected'], $live['projected']);
                    $flagsSeen += (int) $live['at_risk'];
                }
            }

            // Not a vacuous match: plenty of flags were compared.
            $this->assertGreaterThan(20, $flagsSeen);
        }
    }
}
