<?php

namespace App\Modules\Hani;

use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Hani\Support\Matching\ExaminerMatcher;
use App\Modules\Hani\Support\Matching\TfIdfMatcher;
use App\Modules\Hani\Workflows\ExaminerNominationWorkflow;
use App\Modules\Hani\Workflows\ReVivaWorkflow;
use App\Modules\Hani\Workflows\VivaSoftboundWorkflow;
use App\Modules\Hani\Workflows\VivaVoceWorkflow;
use Illuminate\Support\ServiceProvider;

/**
 * Nur Hani Sofia (22001418) — Viva Voce Assessment, Examiner Nomination &
 * Matching, Conflict Detection, Re-viva Monitoring.
 */
class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/hani.php', 'hani');

        // The examiner suggestions' ranking method. TF-IDF in plain PHP by
        // default; anything implementing the contract (an embeddings model,
        // say) can be bound here instead -- see README.md.
        $this->app->bind(ExaminerMatcher::class, TfIdfMatcher::class);
    }

    public function boot(ModuleRegistry $registry): void
    {
        $registry->register(new ExaminerNominationWorkflow());
        $registry->register(new ReVivaWorkflow());
        $registry->register(new VivaVoceWorkflow());
        $registry->register(new VivaSoftboundWorkflow());
    }
}
