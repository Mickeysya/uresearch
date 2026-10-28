<?php

namespace App\Modules\Nureen;

use App\Modules\Core\Contracts\SuppliesAttendance;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Nureen\Console\Commands\RemindStalledSupervisionRequests;
use App\Modules\Nureen\Console\Commands\SyncAttendance;
use App\Modules\Nureen\Support\AttendanceProvider;
use App\Modules\Nureen\Support\AttendanceSourceFactory;
use App\Modules\Nureen\Support\Contracts\AttendanceSource;
use App\Modules\Nureen\Workflows\AttendanceAppealWorkflow;
use App\Modules\Nureen\Workflows\CertificationLetterWorkflow;
use App\Modules\Nureen\Workflows\GaExtensionWorkflow;
use App\Modules\Nureen\Workflows\SupervisionWorkflow;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Nureen Nellysha (22006973) — Attendance, GA Extension, Supervision,
 * GA/GRA Certification Letter.
 */
class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/nureen.php', 'nureen');

        // Core's dashboards show attendance figures but must not name a
        // class in this folder -- see Core\Contracts\SuppliesAttendance.
        // This binding is what connects the two, and it is the only place
        // either side knows about the other.
        $this->app->bind(SuppliesAttendance::class, AttendanceProvider::class);

        // Which AttendanceSource attendance:sync pulls from -- see
        // config/nureen.php and Support\AttendanceSourceFactory.
        $this->app->bind(AttendanceSource::class, fn () => AttendanceSourceFactory::make());
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
                SyncAttendance::class,
            ]);
        }

        // Self-scheduled rather than a line in routes/console.php: calling
        // Schedule::command() from any service provider's boot() registers
        // onto the same singleton `artisan schedule:run` reads, wherever it
        // is called from -- so the nightly automated ingestion needs no
        // shared file. (supervision:remind-stalled's line already in
        // routes/console.php predates this and is left as it is.)
        Schedule::command('attendance:sync')->dailyAt('02:00');
    }
}
