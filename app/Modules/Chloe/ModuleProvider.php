<?php

namespace App\Modules\Chloe;

use App\Modules\Chloe\Assistant\AssistantModel;
use App\Modules\Chloe\Assistant\FakeModel;
use App\Modules\Chloe\Assistant\GeminiModel;
use App\Modules\Chloe\Console\Commands\AssistantAsk;
use App\Modules\Chloe\Console\Commands\AssistantCheck;
use App\Modules\Chloe\Console\Commands\AssistantEvaluate;
use App\Modules\Chloe\Console\Commands\AssistantLogList;
use App\Modules\Chloe\Console\Commands\GenerateCandidacyDismissals;
use App\Modules\Chloe\Console\Commands\ProcessWorkstationReleases;
use App\Modules\Chloe\Console\Commands\ReleaseExpiredWorkstationSelections;
use App\Modules\Chloe\Console\Commands\RemindOverdueLockerKeys;
use App\Modules\Chloe\Console\Commands\SendCandidacyReminders;
use App\Modules\Chloe\Console\Commands\WorkstationDemoClear;
use App\Modules\Chloe\Console\Commands\WorkstationDemoLogin;
use App\Modules\Chloe\Console\Commands\WorkstationDemoSeed;
use App\Modules\Core\Services\ModuleRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
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
    /**
     * ASSISTANT_PROVIDER=fake is what every automated test runs under (see
     * .env.testing) -- this is the one place that decides which
     * implementation the container hands out, so nothing else in the
     * assistant pipeline ever has to know or care which one it got.
     */
    public function register(): void
    {
        $this->app->bind(AssistantModel::class, function () {
            return config('assistant.provider') === 'fake' ? new FakeModel : new GeminiModel;
        });
    }

    public function boot(ModuleRegistry $registry): void
    {
        $registry->register(new Workflows\CandidacyAppealWorkflow);

        // PART 6: "rate limited by config" for the assistant's three
        // routes -- per-user, not app-wide (that's providerBudgetAvailable()
        // in Assistant\Pipeline, a separate, smaller free-tier budget).
        RateLimiter::for('assistant-user', function ($request) {
            return Limit::perMinute((int) config('assistant.rate_limits.user_requests_per_minute', 10))
                ->by($request->user()?->id ?: $request->ip());
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                RemindOverdueLockerKeys::class,
                SendCandidacyReminders::class,
                GenerateCandidacyDismissals::class,
                ReleaseExpiredWorkstationSelections::class,
                ProcessWorkstationReleases::class,
                WorkstationDemoLogin::class,
                WorkstationDemoSeed::class,
                WorkstationDemoClear::class,
                AssistantCheck::class,
                AssistantAsk::class,
                AssistantLogList::class,
                AssistantEvaluate::class,
            ]);
        }
    }
}
