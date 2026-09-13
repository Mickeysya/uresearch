<?php

namespace App\Modules\Jason;

use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Jason\Workflows\AppointmentLetterWorkflow;
use App\Modules\Jason\Workflows\HardboundAppealWorkflow;
use App\Modules\Jason\Workflows\HardboundSubmissionWorkflow;
use Illuminate\Support\ServiceProvider;

/**
 * Jason's modules.
 *
 * Register each workflow you own here. This file is yours alone -- nobody
 * else edits it, so you will never hit a merge conflict adding a module.
 *
 * Copy app/Modules/Norhanis as a working example: it has a workflow, a model,
 * a migration, a controller, routes and views, all wired to the shared engine.
 */
class ModuleProvider extends ServiceProvider
{
    public function boot(ModuleRegistry $registry): void
    {
        $registry->register(new AppointmentLetterWorkflow());
        $registry->register(new HardboundSubmissionWorkflow());
        $registry->register(new HardboundAppealWorkflow());
    }
}
