<?php

namespace App\Modules\Nureen;

use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Nureen\Workflows\GaExtensionWorkflow;
use Illuminate\Support\ServiceProvider;

/**
 * Nureen Nellysha (22006973) — Attendance, GA Extension, Supervision,
 * GA/GRA Certification Letter.
 */
class ModuleProvider extends ServiceProvider
{
    public function boot(ModuleRegistry $registry): void
    {
        $registry->register(new GaExtensionWorkflow());

        // Still to build:
        // $registry->register(new AttendanceAppealWorkflow());
        // $registry->register(new SupervisionWorkflow());
        // $registry->register(new CertificationLetterWorkflow());
    }
}
