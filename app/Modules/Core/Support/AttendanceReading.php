<?php

namespace App\Modules\Core\Support;

use Illuminate\Support\Carbon;

/**
 * One student's attendance for one period, as Core understands it.
 *
 * Core's dashboards need five facts about attendance. They used to get them
 * by importing Nureen's Eloquent model directly, which meant Core -- the
 * shared foundation every module builds on -- had a hard reference to one
 * teammate's folder. This is the shape Core actually depends on instead;
 * whoever supplies attendance maps their own storage onto it.
 *
 * The property names are deliberately the model's column names rather than
 * camelCase: the dashboard views already read `$attendance->percentage`,
 * `->at_risk`, `->period_end` and the two session counts, and there was no
 * reason to churn four Blade files to rename fields nobody sees.
 */
final class AttendanceReading
{
    public function __construct(
        public readonly float $percentage,
        public readonly Carbon $period_end,
        public readonly int $sessions_attended,
        public readonly int $sessions_total,
        public readonly bool $at_risk,
    ) {}
}
