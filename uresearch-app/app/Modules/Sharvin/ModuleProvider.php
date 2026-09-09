<?php

namespace App\Modules\Sharvin;

use App\Modules\Core\Services\ModuleRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Sharvin's modules.
 *
 * Register each workflow you own here. This file is yours alone -- nobody
 * else edits it, so you will never hit a merge conflict adding a module.
 *
 *   $registry->register(new Workflows\MyThingWorkflow());
 *
 * Copy app/Modules/Norhanis as a working example: it has a workflow, a model,
 * a migration, a controller, routes and views, all wired to the shared engine.
 */
class ModuleProvider extends ServiceProvider
{
    public function boot(ModuleRegistry $registry): void
    {
        //
    }
}
