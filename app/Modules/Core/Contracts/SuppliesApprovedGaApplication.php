<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Support\Carbon;

/**
 * Implemented by whichever module owns the GA application lifecycle
 * (today, Haziq's ga_application); consumed by Nureen's GA Extension,
 * confirmed explicitly 2026-11-07: a student may only apply for a GA
 * Extension once their GA application is approved, and the extension's
 * "current end date" is read from that application rather than typed by
 * hand -- see GaExtensionController.
 *
 * Same inversion as SuppliesAttendance: the arrow points from the owning
 * module to Core, never module to module directly, which is why this
 * contract lives here instead of Nureen importing a Haziq model.
 *
 *     $this->app->bind(SuppliesApprovedGaApplication::class, YourProvider::class);
 *
 * BINDING IS OPTIONAL. If nothing binds it, GA Extension is unreachable
 * for everyone (abort_unless fails closed) rather than silently skipping
 * the check -- see GaExtensionController::approvedGaApplication().
 */
interface SuppliesApprovedGaApplication
{
    /**
     * This student's most recently approved GA application, if any.
     *
     * @return array{application: Application, service_end_date: Carbon}|null
     */
    public function approvedFor(User $student): ?array;

    /**
     * The write side of the same relationship, added 2026-11-10 for GA
     * Extension: once an extension is finally approved, the student's
     * currently-approved GA application's own service period needs to
     * actually move to the new end date -- otherwise anything reading
     * approvedFor() (this module's own "is this student still an active
     * GA" checks, Norhanis' Travel TA plan) keeps seeing the *original*
     * end date forever, and the student reads as no longer an active GA
     * the moment that original date passes, extension or not.
     *
     * Returns false (does nothing) if the student has no currently
     * approved GA application to extend -- same fail-closed shape as
     * approvedFor() returning null.
     */
    public function extendEndDate(User $student, Carbon $newEndDate): bool;
}
