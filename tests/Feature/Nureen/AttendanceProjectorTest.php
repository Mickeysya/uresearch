<?php

namespace Tests\Feature\Nureen;

use App\Modules\Nureen\Support\AttendanceProjector;
use PHPUnit\Framework\TestCase;

/**
 * Support\AttendanceProjector is pure -- no database, config or clock -- so
 * these run on plain PHPUnit, without booting the app.
 */
class AttendanceProjectorTest extends TestCase
{
    /** @return array<int, array{attended: int, total: int}> */
    private function periods(array $attended, int $total = 20): array
    {
        return array_map(fn ($a) => ['attended' => $a, 'total' => $total], $attended);
    }

    public function test_a_steady_decline_is_extended_over_the_rest_of_the_semester(): void
    {
        $result = (new AttendanceProjector(3))->project($this->periods([19, 18, 17, 16]), 14);

        $this->assertSame(87.5, $result['current']);
        $this->assertSame(62.5, $result['projected']);
        $this->assertSame(-5.0, $result['slope']);
        $this->assertSame(4, $result['periods_used']);
        $this->assertSame(10, $result['periods_remaining']);
    }

    public function test_steady_attendance_projects_where_it_is(): void
    {
        $result = (new AttendanceProjector(3))->project($this->periods([18, 18, 18, 18]), 14);

        $this->assertSame(90.0, $result['projected']);
        $this->assertSame(0.0, $result['slope']);
    }

    public function test_too_few_periods_gives_no_projection(): void
    {
        $this->assertNull((new AttendanceProjector(3))->project($this->periods([19, 18]), 14));
    }

    public function test_a_zero_session_period_is_ignored_without_dividing_by_zero(): void
    {
        $periods = $this->periods([19, 18]);
        $periods[] = ['attended' => 0, 'total' => 0];  // a public-holiday week
        $periods = array_merge($periods, $this->periods([17, 16]));

        $result = (new AttendanceProjector(3))->project($periods, 14);

        $this->assertNotNull($result);
        $this->assertSame(4, $result['periods_used']);
        // The holiday week still passed: 5 of 14 periods are behind us.
        $this->assertSame(9, $result['periods_remaining']);
        $this->assertSame(87.5, $result['current']);

        // A semester with only empty periods is "too few", not an error.
        $this->assertNull((new AttendanceProjector(3))->project(array_fill(0, 5, ['attended' => 0, 'total' => 0]), 14));
    }

    public function test_a_rising_trend_never_projects_above_100(): void
    {
        $result = (new AttendanceProjector(3))->project($this->periods([10, 15, 20]), 14);

        $this->assertGreaterThan(0, $result['slope']);
        $this->assertLessThanOrEqual(100.0, $result['projected']);
    }

    public function test_a_falling_trend_never_projects_below_0(): void
    {
        $result = (new AttendanceProjector(3))->project($this->periods([20, 10, 0]), 14);

        $this->assertGreaterThanOrEqual(0.0, $result['projected']);
    }

    public function test_at_the_end_of_the_semester_the_projection_is_the_current_figure(): void
    {
        $result = (new AttendanceProjector(3))->project($this->periods([19, 18, 17, 16]), 4);

        $this->assertSame(0, $result['periods_remaining']);
        $this->assertSame($result['current'], $result['projected']);
    }

    /* -----------------------------------------------------------------
     | Period lengths must not be mixed
     |------------------------------------------------------------------*/

    public function test_period_length_is_recognised_from_the_end_dates(): void
    {
        $this->assertSame('weekly', AttendanceProjector::periodLength(['2026-10-09', '2026-10-16', '2026-10-23']));
        $this->assertSame('monthly', AttendanceProjector::periodLength(['2026-07-31', '2026-08-31', '2026-09-30']));
        $this->assertSame('unknown', AttendanceProjector::periodLength(['2026-10-09']));

        // Monthly then weekly, and a missed fortnight: both inconsistent.
        $this->assertNull(AttendanceProjector::periodLength(['2026-08-31', '2026-09-30', '2026-10-09', '2026-10-16']));
        $this->assertNull(AttendanceProjector::periodLength(['2026-10-02', '2026-10-09', '2026-10-23']));
    }

    public function test_the_projector_refuses_to_mix_period_lengths(): void
    {
        $mixed = [
            ['attended' => 38, 'total' => 40, 'end' => '2026-08-31'],
            ['attended' => 36, 'total' => 40, 'end' => '2026-09-30'],
            ['attended' => 9, 'total' => 10, 'end' => '2026-10-09'],
            ['attended' => 8, 'total' => 10, 'end' => '2026-10-16'],
        ];

        $projector = new AttendanceProjector(3);

        $this->assertSame(AttendanceProjector::INCONSISTENT_PERIODS, $projector->unavailableReason($mixed));
        $this->assertNull($projector->project($mixed, 14));

        // The same counts on consistent weekly dates project normally.
        $weekly = array_map(fn ($p, $end) => ['end' => $end] + $p, $mixed, ['2026-09-18', '2026-09-25', '2026-10-02', '2026-10-09']);
        $this->assertNull($projector->unavailableReason($weekly));
        $this->assertNotNull($projector->project($weekly, 14));

        $this->assertSame(AttendanceProjector::TOO_FEW_PERIODS, $projector->unavailableReason(array_slice($weekly, 0, 2)));
    }
}

