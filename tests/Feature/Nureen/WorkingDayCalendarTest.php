<?php

namespace Tests\Feature\Nureen;

use App\Modules\Nureen\Support\WorkingDayCalendar;
use Carbon\Carbon;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\TestCase;

/**
 * The one working-day calculator every Client Charter figure is counted
 * on -- see Support\WorkingDayCalendar. November 2026: the 2nd is a Monday,
 * the 6th a Friday, the 7th and 8th the weekend.
 */
class WorkingDayCalendarTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;

    protected function calendar(): WorkingDayCalendar
    {
        return app(WorkingDayCalendar::class);
    }

    public function test_weekends_are_skipped(): void
    {
        $this->assertFalse($this->calendar()->isWorkingDay(Carbon::parse('2026-11-07')));
        $this->assertFalse($this->calendar()->isWorkingDay(Carbon::parse('2026-11-08')));

        // Friday + 1 is Monday, and Friday to Monday is one working day.
        $this->assertSame('2026-11-09', $this->calendar()->addWorkingDays(Carbon::parse('2026-11-06'), 1)->format('Y-m-d'));
        $this->assertSame(1, $this->calendar()->workingDaysBetween(Carbon::parse('2026-11-06 16:00'), Carbon::parse('2026-11-09 09:00')));

        // Reaching a stage on a Saturday: the clock starts on Monday.
        $this->assertSame('2026-11-09', $this->calendar()->addWorkingDays(Carbon::parse('2026-11-07'), 1)->format('Y-m-d'));
    }

    public function test_a_configured_holiday_is_skipped(): void
    {
        config(['nureen.charter.holidays' => ['2026-11-10']]);

        $this->assertFalse($this->calendar()->isWorkingDay(Carbon::parse('2026-11-10')));
        $this->assertSame('2026-11-11', $this->calendar()->addWorkingDays(Carbon::parse('2026-11-09'), 1)->format('Y-m-d'));
        $this->assertSame(2, $this->calendar()->workingDaysBetween(Carbon::parse('2026-11-09'), Carbon::parse('2026-11-12')));
    }

    public function test_a_due_date_landing_on_a_friday_rolls_correctly(): void
    {
        // Tuesday + 3 lands exactly on Friday...
        $this->assertSame('2026-11-06', $this->calendar()->addWorkingDays(Carbon::parse('2026-11-03 15:30'), 3)->format('Y-m-d'));

        // ...Wednesday + 3 rolls over the weekend to Monday...
        $this->assertSame('2026-11-09', $this->calendar()->addWorkingDays(Carbon::parse('2026-11-04'), 3)->format('Y-m-d'));

        // ...and Friday + 3 is the following Wednesday.
        $this->assertSame('2026-11-11', $this->calendar()->addWorkingDays(Carbon::parse('2026-11-06'), 3)->format('Y-m-d'));
    }

    public function test_zero_working_days_is_the_same_day(): void
    {
        $this->assertSame('2026-11-04', $this->calendar()->addWorkingDays(Carbon::parse('2026-11-04 17:00'), 0)->format('Y-m-d'));
        $this->assertSame(0, $this->calendar()->workingDaysBetween(Carbon::parse('2026-11-04 09:00'), Carbon::parse('2026-11-04 17:00')));
    }
}
