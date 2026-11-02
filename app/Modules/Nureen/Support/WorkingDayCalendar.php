<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Support\WorkingDayCalendar as CoreWorkingDayCalendar;

/**
 * The Client Charter's working-day calculator -- now Core's shared
 * calendar (Core\Support\WorkingDayCalendar), so Track My Applications and
 * the charter count working days identically.
 *
 * Kept as its own class so ClientCharter, ProcessingTimeAnalytics and the
 * demo seeder need no change, and so config('nureen.charter.holidays') still
 * works: those dates are added to Core's config('calendar.holidays'). Both
 * lists are empty today, so every charter date is exactly what it was
 * (WorkingDayCalendarTest proves the two agree day by day).
 */
class WorkingDayCalendar extends CoreWorkingDayCalendar
{
    protected function holidays(): array
    {
        return array_values(array_unique([
            ...parent::holidays(),
            ...array_map(
                fn ($date) => \Carbon\CarbonImmutable::parse($date)->format('Y-m-d'),
                (array) config('nureen.charter.holidays', [])
            ),
        ]));
    }
}
