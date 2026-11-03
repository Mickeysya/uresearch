<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\User;

/**
 * Implemented by whichever module owns viva scheduling; consumed by the
 * student dashboard's "Viva" card.
 *
 * Nothing binds this today. Viva scheduling is not yet built -- Hani's
 * Re-viva work tracks the post-viva correction cycle (ReVivaDetail), not an
 * initial viva date -- so the card renders its "not scheduled yet" state at
 * every call site until a module implements and binds this, same graceful
 * degradation as SuppliesAttendance before Nureen existed.
 *
 *     $this->app->bind(SuppliesVivaStatus::class, YourVivaProvider::class);
 */
interface SuppliesVivaStatus
{
    /**
     * This student's viva card, or null when nothing is scheduled.
     *
     * @return array{
     *     daysRemaining: int,
     *     date: \Carbon\CarbonInterface,
     *     time: ?string,
     *     url: string,
     * }|null
     */
    public function vivaStatusFor(User $student): ?array;
}
