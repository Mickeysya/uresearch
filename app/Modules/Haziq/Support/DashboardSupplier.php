<?php

namespace App\Modules\Haziq\Support;

use App\Modules\Core\Contracts\SuppliesPostgraduateProgress;
use App\Modules\Core\Models\User;
use App\Modules\Haziq\Http\Controllers\AllowanceController;
use App\Modules\Haziq\Http\Controllers\StageGateController;
use App\Modules\Norhanis\Http\Controllers\TaHourController;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Models\StageGate;

/**
 * Satisfies Core\Contracts\SuppliesPostgraduateProgress so the shared
 * dashboards can show Stage Gate / Allowance / TA Hours figures without
 * Core ever naming a class in this folder — see that contract's own
 * docblock. Bound in Haziq's own ModuleProvider.
 *
 * Deliberately a thin router to each controller's own public summary
 * method rather than a second implementation of any of this module's
 * business logic — every number here is the exact same figure that
 * controller's own page would show, by construction, not a parallel
 * calculation that could quietly drift from it.
 */
class DashboardSupplier implements SuppliesPostgraduateProgress
{
    public function __construct(
        protected AllowanceController $allowance,
        protected StageGateController $stageGates,
        protected TaHourController $taHours,
    ) {}

    public function allowanceSummaryFor(User $student): ?array
    {
        return $this->allowance->summaryFor($student);
    }

    public function riskiestOpenStageGateFor(User $student): ?int
    {
        return $this->stageGates->riskiestOpenGateFor($student);
    }

    public function taHoursThisMonthFor(User $student): array
    {
        return $this->taHours->thisMonthFor($student);
    }

    public function stageGateBottleneckCount(): int
    {
        return StageGate::where('completion_status', '!=', 'completed')
            ->whereIn('timeline_status', ['grace_period', 'grace_expired'])
            ->count();
    }

    public function allowanceAnomalyCount(): int
    {
        return $this->allowance->anomalyCount();
    }

    public function activeCandidatureCount(): int
    {
        return GraApplicationDetail::where('candidature_status', 'active')->count()
            + GaApplicationDetail::where('candidature_status', 'active')->count();
    }

    public function terminatedCandidatureCount(): int
    {
        return GraApplicationDetail::where('candidature_status', 'terminated')->count()
            + GaApplicationDetail::where('candidature_status', 'terminated')->count();
    }
}
