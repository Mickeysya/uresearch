<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\AttendanceReading;
use Illuminate\Support\Collection;

/**
 * Implemented by whichever module owns attendance; consumed by Core's
 * dashboards.
 *
 * This exists to invert a dependency that was pointing the wrong way. Core is
 * the shared foundation -- every module depends on it, and it is supposed to
 * depend on none of them, which is what keeps the six folders from tangling.
 * But three Core dashboards imported `App\Modules\Nureen\Models\
 * AttendanceRecord` directly, behind a `class_exists()` guard that was really
 * an admission the reference should not have been there: Core knew the name
 * of a class in someone else's folder, and would have fataled if Nureen
 * renamed it.
 *
 * Now Core declares what it needs and Nureen satisfies it. The arrow points
 * from the module to Core, like every other arrow in the system.
 *
 * BINDING IS OPTIONAL, ON PURPOSE. A module binds this in its own
 * ModuleProvider:
 *
 *     $this->app->bind(SuppliesAttendance::class, AttendanceProvider::class);
 *
 * If nothing binds it, Core's dashboards hold the attendance panel's skeleton
 * exactly as they did when the class was missing -- same graceful
 * degradation, expressed as a contract instead of a string class name.
 */
interface SuppliesAttendance
{
    /** This student's most recent period, or null if nothing is on file. */
    public function latestFor(User $student): ?AttendanceReading;

    /**
     * Where this student's attendance is heading, as a percentage, or null
     * when there is too little history to have a trend at all. The rule is
     * the supplying module's business -- Core only renders the number.
     */
    public function projectionFor(User $student): ?float;

    /**
     * The most recent reading for every student, one each -- the portal-wide
     * view behind the CGS bands and the admin average.
     *
     * @return Collection<int, AttendanceReading>
     */
    public function latestPerStudent(): Collection;
}
