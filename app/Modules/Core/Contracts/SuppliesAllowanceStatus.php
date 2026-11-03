<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\User;

/**
 * Implemented by whichever module owns GA/GRA allowance payments; consumed
 * by the student dashboard's "GA allowance" card.
 *
 * Nothing binds this today. Haziq's Allowance Eligibility module is scoped
 * but not started (scaffold only, per TODO.md) -- no payment log table
 * exists yet -- so the card renders its "not available" state at every call
 * site until a module implements and binds this.
 *
 *     $this->app->bind(SuppliesAllowanceStatus::class, YourAllowanceProvider::class);
 */
interface SuppliesAllowanceStatus
{
    /**
     * This student's allowance card, or null when they are not on an active
     * GA/GRA allowance.
     *
     * @return array{
     *     amount: float,
     *     currency: string,
     *     statusLabel: string,
     *     lastPaidAt: ?\Carbon\CarbonInterface,
     *     nextPaymentAt: ?\Carbon\CarbonInterface,
     *     note: ?string,
     *     url: string,
     * }|null
     */
    public function allowanceStatusFor(User $student): ?array;
}
