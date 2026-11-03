<?php

namespace App\Modules\Chloe;

use App\Modules\Chloe\Console\Commands\GenerateCandidacyDismissals;
use App\Modules\Chloe\Console\Commands\RemindOverdueLockerKeys;
use App\Modules\Chloe\Console\Commands\SendCandidacyReminders;
use App\Modules\Core\Services\ModuleRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Chloe's modules.
 *
 * Register each workflow you own here. This file is yours alone -- nobody
 * else edits it, so you will never hit a merge conflict adding a module.
 *
 *   $registry->register(new Workflows\MyThingWorkflow());
 *
 * Copy app/Modules/Norhanis as a working example: it has a workflow, a model,
 * a migration, a controller, routes and views, all wired to the shared engine.
 *
 * Console commands need registering here too -- ModuleServiceProvider only
 * auto-loads views, migrations and routes.php, not Console/Commands/. See
 * Nureen's ModuleProvider for the pattern this one follows.
 */
class ModuleProvider extends ServiceProvider
{
    public function boot(ModuleRegistry $registry): void
    {
        $registry->register(new Workflows\CandidacyAppealWorkflow());

        if ($this->app->runningInConsole()) {
            $this->commands([
                RemindOverdueLockerKeys::class,
                SendCandidacyReminders::class,
                GenerateCandidacyDismissals::class,
            ]);
        }
    }
}
