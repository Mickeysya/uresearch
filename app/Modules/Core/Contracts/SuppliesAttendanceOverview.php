<?php

namespace App\Modules\Core\Contracts;

use Carbon\CarbonInterface;

/**
 * OPTIONAL. Implemented by whichever module owns attendance (today,
 * Nureen's); consumed by Core's Students page.
 *
 * Unlike SuppliesAttendance::latestFor() (one student, the latest period),
 * this answers for MANY students at once -- one query, not one per row --
 * with the semester-so-far figure the early warning itself uses. The list
 * filters "at risk" and sorts by attendance across every student it can
 * show, so it needs the figures for all of them, not one page.
 *
 * projected_percentage is CGS-only information: the consumer decides who
 * sees it (Core's StudentDirectory shows it to the CGS team only). It is
 * never shown to the student.
 *
 * BINDING IS OPTIONAL. If nothing binds it, the Students page shows no
 * attendance column and offers no "at risk" filter.
 */
interface SuppliesAttendanceOverview
{
    /**
     * Keyed by student id; a student with no attendance on record is absent.
     *
     * @param  iterable<int>  $studentIds
     * @return array<int, array{percentage: ?float, projected_percentage: ?float, at_risk: bool, latest_period_end: ?CarbonInterface}>
     */
    public function overviewFor(iterable $studentIds): array;
}
