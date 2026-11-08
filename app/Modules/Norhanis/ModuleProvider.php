<?php

namespace App\Modules\Norhanis;

use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Norhanis\Console\Commands\RemindStalledApplications;
use App\Modules\Norhanis\Console\Commands\SeedDemoApplications;
use App\Modules\Norhanis\Support\Drafts;
use App\Modules\Norhanis\Support\StageDurations;
use App\Modules\Nureen\Support\Drafts as NureenDrafts;
use App\Modules\Norhanis\Workflows\ClaimsWorkflow;
use App\Modules\Norhanis\Workflows\PublicationWorkflow;
use App\Modules\Norhanis\Workflows\TravelWorkflow;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Norhanis Erna Natasha (22006318) — Travel, Publication, Claims.
 *
 * Register each workflow you own here. This is the ONLY file in the project
 * you share with nobody, so adding a module never conflicts with a teammate.
 */
class ModuleProvider extends ServiceProvider
{
    public function register(): void
    {
        // config('norhanis.charter.<module>.<stage>'): CGS's client charter days.
        $this->mergeConfigFrom(__DIR__.'/Config/charter.php', 'norhanis.charter');

        // config('norhanis.cgs.officer'): the CGS officer for these modules.
        $this->mergeConfigFrom(__DIR__.'/Config/cgs.php', 'norhanis.cgs');
        // config('norhanis.ta.*'): the Travel form's TA plan.
        $this->mergeConfigFrom(__DIR__.'/Config/ta.php', 'norhanis.ta');

        // One per request, so the stage history is read once however many
        // applications a page estimates.
        $this->app->singleton(StageDurations::class);
    }

    public function boot(ModuleRegistry $registry): void
    {
        $registry->register(new TravelWorkflow());
        $registry->register(new ClaimsWorkflow());
        $registry->register(new PublicationWorkflow());

        // Saved drafts are listed on Nureen's My Drafts page with hers. Only
        // saved drafts: a Publication waiting for sign-offs is a draft too.
        if (method_exists(NureenDrafts::class, 'list')) {
            foreach (Drafts::MODULES as $module) {
                NureenDrafts::list($module, fn ($query) => Drafts::scope($query));
            }
        }

        // Registered here because module folders are outside the path
        // Laravel auto-discovers commands from.
        if ($this->app->runningInConsole()) {
            $this->commands([
                // Example students and applications, for development only.
                SeedDemoApplications::class,
                RemindStalledApplications::class,
            ]);
        }

        // Every weekday morning, from here rather than routes/console.php so
        // nothing outside this folder changes -- the same scheduler reads it.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('norhanis:remind-stalled')->weekdays()->dailyAt('08:00');
        });
    }
}
