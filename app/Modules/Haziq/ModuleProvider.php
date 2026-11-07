<?php

namespace App\Modules\Haziq;

use App\Modules\Core\Contracts\SuppliesPostgraduateProgress;
use App\Modules\Core\Contracts\SuppliesTeachingAssistantStatus;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Haziq\Console\Commands\EvaluateStageGates;
use App\Modules\Haziq\Support\DashboardSupplier;
use App\Modules\Haziq\Support\TeachingAssistantStatus;
use App\Modules\Haziq\Workflows\GaApplicationWorkflow;
use App\Modules\Haziq\Workflows\GraApplicationWorkflow;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Haziq's modules: GRA Application (+ Extension), GA Application, and the
 * Stage Gate / Allowance monitoring that runs against both.
 *
 * Allowance Eligibility (Module 3 in docs/scope/haziq.md) is deliberately
 * not a WorkflowModule -- nobody submits anything to it, it is a reporting
 * view over stage_gates + allowance_payment_logs. See
 * Http\Controllers\AllowanceController.
 */
class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        // Core's dashboards show Stage Gate/Allowance/TA Hours figures but
        // must not name a class in this folder — see Core\Contracts\
        // SuppliesPostgraduateProgress. This binding is what connects the
        // two, and it is the only place either side knows about the other.
        $this->app->bind(SuppliesPostgraduateProgress::class, DashboardSupplier::class);

        // Same seam for other modules' forms: whether a student is a GA/GRA
        // today, and their TA hours -- see Core\Contracts\SuppliesTeachingAssistantStatus.
        $this->app->bind(SuppliesTeachingAssistantStatus::class, TeachingAssistantStatus::class);
    }

    public function boot(ModuleRegistry $registry): void
    {
        $registry->register(new GraApplicationWorkflow());
        $registry->register(new GaApplicationWorkflow());

        if ($this->app->runningInConsole()) {
            $this->commands([
                EvaluateStageGates::class,
            ]);
        }

        // Scheduled from here rather than routes/console.php, which every
        // other module also appends a line to -- keeping this entirely
        // inside the module avoids touching a file outside it.
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('stage-gate:evaluate')->dailyAt('07:30');
        });
    }
}
