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
 *
 * TWO PERCENTAGES. `percentage` and the session counts are the LATEST
 * PERIOD (one week, for weekly data). `semester_percentage` is the semester
 * so far -- the figure the 80% requirement is measured on and the CGS
 * At-Risk list shows. It is optional: a supplier that does not work it out
 * leaves it null, and the views fall back to the latest period through
 * headline(). Views label which one they show ("Semester so far" /
 * "Latest week"), so the two can never be mistaken for each other.
 */
final class AttendanceReading
{
    public function __construct(
        public readonly float $percentage,
        public readonly Carbon $period_end,
        public readonly int $sessions_attended,
        public readonly int $sessions_total,
        public readonly bool $at_risk,
        public readonly ?float $semester_percentage = null,
    ) {}

    /** The figure to lead with: the semester so far when known, else the latest period. */
    public function headline(): float
    {
        return $this->semester_percentage ?? $this->percentage;
    }

    /** "Semester so far" or "Latest week" -- which figure headline() is. */
    public function headlineLabel(): string
    {
        return $this->semester_percentage !== null ? 'Semester so far' : 'Latest week';
    }

    /** "70.8%" -- one decimal at most, no trailing zeros, as every attendance figure is shown. */
    public static function format(float $percentage): string
    {
        return rtrim(rtrim(number_format($percentage, 1), '0'), '.').'%';
    }
}
