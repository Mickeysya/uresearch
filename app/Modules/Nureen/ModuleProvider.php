<?php

namespace App\Modules\Nureen;

use App\Modules\Core\Contracts\SuppliesAttendance;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Nureen\Console\Commands\RemindStalledSupervisionRequests;
use App\Modules\Nureen\Support\AttendanceProvider;
use App\Modules\Nureen\Workflows\AttendanceAppealWorkflow;
use App\Modules\Nureen\Workflows\CertificationLetterWorkflow;
use App\Modules\Nureen\Workflows\GaExtensionWorkflow;
use App\Modules\Nureen\Workflows\SupervisionWorkflow;
use Illuminate\Support\ServiceProvider;

/**
 * Nureen Nellysha (22006973) — Attendance, GA Extension, Supervision,
 * GA/GRA Certification Letter.
 */
class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        // Core's dashboards show attendance figures but must not name a
        // class in this folder -- see Core\Contracts\SuppliesAttendance.
        // This binding is what connects the two, and it is the only place
        // either side knows about the other.
        $this->app->bind(SuppliesAttendance::class, AttendanceProvider::class);
    }

    public function boot(ModuleRegistry $registry): void
    {
        $registry->register(new GaExtensionWorkflow());
        $registry->register(new AttendanceAppealWorkflow());
        $registry->register(new SupervisionWorkflow());
        $registry->register(new CertificationLetterWorkflow());

        if ($this->app->runningInConsole()) {
            $this->commands([
                RemindStalledSupervisionRequests::class,
            ]);
        }
    }
}
