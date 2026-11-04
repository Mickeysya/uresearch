<?php

namespace App\Modules\Chloe\Support;

use Illuminate\Support\Carbon;

/**
 * Term of Use clause 9/16's "five (5) working days": Monday to Friday,
 * minus whatever config('workstation.holidays') lists (empty by default).
 * One place this is computed, so the termination notice's vacate-by date
 * and anything that later needs to re-derive or display it never drift.
 */
final class WorkingDays
{
    public static function add(Carbon $from, int $days): Carbon
    {
        $holidays = array_map('strval', config('workstation.holidays', []));

        $date = $from->copy();
        $added = 0;

        while ($added < $days) {
            $date = $date->addDay();

            if ($date->isWeekend() || in_array($date->toDateString(), $holidays, true)) {
                continue;
            }

            $added++;
        }

        return $date;
    }
}
