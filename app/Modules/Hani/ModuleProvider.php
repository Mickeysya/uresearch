<?php

namespace App\Modules\Hani;

use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Hani\Workflows\ExaminerNominationWorkflow;
use Illuminate\Support\ServiceProvider;

/**
 * Nur Hani Sofia (22001418) — Examiner Nomination & Matching,
 * Conflict Detection, Re-viva Monitoring.
 */
class ModuleProvider extends ServiceProvider
{
    public function boot(ModuleRegistry $registry): void
    {
        $registry->register(new ExaminerNominationWorkflow());

        // Still to build:
        // $registry->register(new ReVivaWorkflow());
    }
}
