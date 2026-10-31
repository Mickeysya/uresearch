<?php

namespace App\Modules\Nureen\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The one working-day calculator behind the Client Charter.
 *
 * Monday to Friday, minus any date listed in
 * config('nureen.charter.holidays'). Stage due dates, the student's
 * expected decision date, reminder timing and the compliance report all
 * count on this class -- see Support\ClientCharter -- so they can never
 * disagree with each other about what "3 working days" means.
 *
 * Everything works on calendar DATES in the app's timezone, not on
 * timestamps: an application that reaches a stage at 4:59pm and one that
 * reaches it at 9am have the same due date, which is how a charter written
 * in working days is read by the people it is written for.
 */
class WorkingDayCalendar
{
    public function isWorkingDay(CarbonInterface $date): bool
    {
        return ! $date->isWeekend()
            && ! in_array($date->format('Y-m-d'), $this->holidays(), true);
    }

    /**
     * The date $days working days after $from. $from itself never counts,
     * so a stage reached on a Monday with 3 charter days is due on the
     * Thursday, and one reached on a Friday is due on the Wednesday. 0
     * returns $from's own date -- a same-day stage.
     */
    public function addWorkingDays(CarbonInterface $from, int $days): CarbonImmutable
    {
        $date = CarbonImmutable::instance($from)->startOfDay();

        while ($days > 0) {
            $date = $date->addDay();

            if ($this->isWorkingDay($date)) {
                $days--;
            }
        }

        return $date;
    }

    /** The first working day strictly after $date. */
    public function nextWorkingDay(CarbonInterface $date): CarbonImmutable
    {
        return $this->addWorkingDays($date, 1);
    }

    /** $date itself if it is a working day, otherwise the next one. */
    public function onOrAfter(CarbonInterface $date): CarbonImmutable
    {
        $date = CarbonImmutable::instance($date)->startOfDay();

        return $this->isWorkingDay($date) ? $date : $this->nextWorkingDay($date);
    }

    /**
     * Working days after $from, up to and including $to -- 0 when $to is
     * on or before $from. The inverse of addWorkingDays(): for a working
     * day $to, workingDaysBetween($from, addWorkingDays($from, $n)) === $n.
     */
    public function workingDaysBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $date = CarbonImmutable::instance($from)->startOfDay();
        $end = CarbonImmutable::instance($to)->startOfDay();
        $count = 0;

        while ($date->lt($end)) {
            $date = $date->addDay();

            if ($this->isWorkingDay($date)) {
                $count++;
            }
        }

        return $count;
    }

    /** @return array<int, string> */
    protected function holidays(): array
    {
        return array_map(
            fn ($date) => CarbonImmutable::parse($date)->format('Y-m-d'),
            (array) config('nureen.charter.holidays', [])
        );
    }
}
