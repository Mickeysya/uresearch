<?php

namespace App\Modules\Nureen;

use App\Modules\Core\Contracts\SuppliesAttendance;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Nureen\Console\Commands\RemindStalledCertification;
use App\Modules\Nureen\Console\Commands\RemindStalledGaExtension;
use App\Modules\Nureen\Console\Commands\RemindStalledSupervisionRequests;
use App\Modules\Nureen\Console\Commands\SyncAttendance;
use App\Modules\Nureen\Support\AttendanceProvider;
use App\Modules\Nureen\Support\AttendanceSourceFactory;
use App\Modules\Nureen\Support\Contracts\AttendanceSource;
use App\Modules\Nureen\Support\Contracts\GeneratesQrCode;
use App\Modules\Nureen\Support\QrCode\UnavailableQrCodeGenerator;
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

        // No QR library in composer.json yet -- see SHARED_CHANGES.md. Swap
        // this binding for a real implementation of GeneratesQrCode the day
        // one is added; nothing else in this module needs to change.
        $this->app->bind(GeneratesQrCode::class, UnavailableQrCodeGenerator::class);
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
                RemindStalledGaExtension::class,
                RemindStalledCertification::class,
                SyncAttendance::class,
            ]);
        }

        // Self-scheduled rather than a line in routes/console.php: calling
        // Schedule::command() from any service provider's boot() registers
        // onto the same singleton `artisan schedule:run` reads, wherever it
        // is called from -- so none of this needs a shared file.
        // (supervision:remind-stalled's own schedule line already in
        // routes/console.php predates this approach and is left as it is;
        // the two new reminder commands follow it here instead.)
        Schedule::command('attendance:sync')->dailyAt('02:00');
        Schedule::command('ga-extension:remind-stalled')->dailyAt('08:00');
        Schedule::command('ga-certification:remind-stalled')->dailyAt('08:00');
    }
}
