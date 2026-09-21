<?php

namespace App\Modules\Norhanis;

use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Norhanis\Console\Commands\SendRpdReminders;
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

        // RPD candidacy. Reminders are a scheduled command (rpd:remind) and
        // register nothing here -- only the two approval chains are workflows.
        $registry->register(new RpdAppealWorkflow());
        $registry->register(new RpdDismissalWorkflow());

        // The 3/2/1-month reminder scheduler. Scheduled from
        // routes/console.php; registered here because module folders are
        // outside the path Laravel auto-discovers commands from.
        if ($this->app->runningInConsole()) {
            $this->commands([
                SendRpdReminders::class,
            ]);
        }
    }
}
