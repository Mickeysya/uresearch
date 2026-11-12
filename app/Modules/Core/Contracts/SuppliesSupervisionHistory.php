<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\User;
use Carbon\CarbonInterface;

/**
 * OPTIONAL. Implemented by whichever module owns supervisor appointments
 * (today, Nureen's Supervision); consumed by Core's Students page.
 *
 * users.supervisor_id already says who the CURRENT main supervisor is.
 * This adds what only the appointments know: who co-supervises, who
 * supervised before, and -- for scoping a supervisor's view to their own
 * students -- which students someone co-supervises.
 *
 * BINDING IS OPTIONAL. If nothing binds it, the Students page shows the
 * main supervisor only, and a supervisor sees only students whose main
 * supervisor they are.
 */
interface SuppliesSupervisionHistory
{
    /**
     * @return array{
     *     co: array<int, array{user: User, since: ?CarbonInterface}>,
     *     previous: array<int, array{user: User, until: ?CarbonInterface, note: string}>,
     * }
     */
    public function supervisorsFor(User $student): array;

    /** @return array<int, int> ids of the students $supervisor actively co-supervises */
    public function coSupervisedStudentIds(User $supervisor): array;
}
