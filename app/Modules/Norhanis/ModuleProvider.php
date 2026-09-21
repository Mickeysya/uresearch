<?php

namespace App\Modules\Norhanis;

use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Norhanis\Console\Commands\RemindRpdCandidates;
use App\Modules\Norhanis\Workflows\ClaimsWorkflow;
use App\Modules\Norhanis\Workflows\PublicationWorkflow;
use App\Modules\Norhanis\Workflows\RpdAppealWorkflow;
use App\Modules\Norhanis\Workflows\RpdDismissalWorkflow;
use App\Modules\Norhanis\Workflows\TravelWorkflow;
use Illuminate\Support\ServiceProvider;

/**
 * Norhanis Erna Natasha (22006318) — Travel, Publication, Claims, RPD.
 *
 * Register each workflow you own here. This is the ONLY file in the project
 * you share with nobody, so adding a module never conflicts with a teammate.
 */
class ModuleProvider extends ServiceProvider
{
    public function boot(ModuleRegistry $registry): void
    {
        $registry->register(new TravelWorkflow());
        $registry->register(new ClaimsWorkflow());
        $registry->register(new PublicationWorkflow());
        $registry->register(new RpdAppealWorkflow());
        $registry->register(new RpdDismissalWorkflow());

        if ($this->app->runningInConsole()) {
            $this->commands([
                RemindRpdCandidates::class,
            ]);
        }
    }
}
