<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\User;

/**
 * Implemented by whichever module owns study candidacy; consumed by the
 * student dashboard's "Study candidacy" card.
 *
 * Same shape as SuppliesAttendance: Core declares what it needs, the owning
 * module (Chloe's) satisfies it, and if nothing binds this the card holds an
 * honest "not available" state instead of Core importing
 * App\Modules\Chloe\Models\StudyCandidacy directly.
 *
 * BINDING IS OPTIONAL, ON PURPOSE:
 *
 *     $this->app->bind(SuppliesCandidacyStatus::class, CandidacyStatusProvider::class);
 */
interface SuppliesCandidacyStatus
{
    /**
     * This student's candidacy card, or null when no candidacy is on record
     * for them (not an error -- CGS simply hasn't registered one yet).
     *
     * @return array{
     *     daysRemaining: int,
     *     monthsIn: ?int,
     *     startedAt: ?\Carbon\CarbonInterface,
     *     expiresAt: \Carbon\CarbonInterface,
     *     extensionUsedMonths: int,
     *     extensionMaxMonths: int,
     *     statusLabel: string,
     *     tone: string,
     *     url: string,
     * }|null
     */
    public function candidacyStatusFor(User $student): ?array;
}
