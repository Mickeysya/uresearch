<?php

namespace App\Modules\Hani\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Working days: Monday to Friday, less the public holidays in
 * hani.scheduling.public_holidays (the same list the viva scheduler warns
 * on). The correction letter gives examiners "twenty (20) working days",
 * so the re-viva clocks count these, not calendar days.
 */
final class WorkingDays
{
    public static function isWorkingDay(CarbonInterface $day): bool
    {
        return ! $day->isWeekend() && ! array_key_exists($day->toDateString(), (array) config('hani.scheduling.public_holidays', []));
    }

    /** Working days after $from, up to and including $to. 0 when $to is not after $from. */
    public static function between(CarbonInterface $from, CarbonInterface $to): int
    {
        $day = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $count = 0;

        while ($day->lt($end)) {
            $day->addDay();
            if (self::isWorkingDay($day)) {
                $count++;
            }
        }

        return $count;
    }

    /** The date $days working days after $from. */
    public static function add(CarbonInterface $from, int $days): Carbon
    {
        $day = Carbon::parse($from)->startOfDay();

        while ($days > 0) {
            $day->addDay();
            if (self::isWorkingDay($day)) {
                $days--;
            }
        }

        return $day;
    }
}
