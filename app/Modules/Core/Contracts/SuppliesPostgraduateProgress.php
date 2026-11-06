<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\User;

/**
 * Implemented by whichever module owns the GRA/GA appointment lifecycle
 * (Stage Gates, Allowance Eligibility, TA Hours); consumed by Core's
 * dashboards. Same inverted-dependency shape as SuppliesAttendance — see
 * that contract's own docblock for why this exists at all. Core declares
 * what it needs for the "Postgraduate Progress" panel/cards; the owning
 * module satisfies it, and the arrow points from the module to Core like
 * every other one in the system.
 *
 * BINDING IS OPTIONAL, ON PURPOSE. A module binds this in its own
 * ModuleProvider:
 *
 *     $this->app->bind(SuppliesPostgraduateProgress::class, DashboardSupplier::class);
 *
 * If nothing binds it, Core's dashboards hold that panel's skeleton rather
 * than erroring or showing a false zero.
 */
interface SuppliesPostgraduateProgress
{
    /**
     * This student's allowance payable this calendar month, or null when
     * they have no qualifying appointment at all. $amount can itself be
     * null within a non-null array — that's a real, different state (an
     * ongoing termination, say) from "nothing to show at all."
     *
     * @return array{amount: float|null, forMonth: string}|null
     */
    public function allowanceSummaryFor(User $student): ?array;

    /** 0–100, whichever of this student's own open Stage Gates is closest to its grace period running out. Null if none are open. */
    public function riskiestOpenStageGateFor(User $student): ?int;

    /** @return array{hours: float, cap: float} */
    public function taHoursThisMonthFor(User $student): array;

    /** Portal-wide: Stage Gates currently past their deadline, not yet resolved. */
    public function stageGateBottleneckCount(): int;

    /** Portal-wide: students whose allowance is currently accruing 3+ months unpaid. */
    public function allowanceAnomalyCount(): int;

    /** Portal-wide: appointments with an active candidature right now. */
    public function activeCandidatureCount(): int;

    /** Portal-wide: appointments terminated right now (awaiting appeal or otherwise unresolved). */
    public function terminatedCandidatureCount(): int;
}
