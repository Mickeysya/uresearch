<?php

namespace App\Modules\Chloe;

use App\Modules\Chloe\Assistant\AssistantModel;
use App\Modules\Chloe\Assistant\FakeModel;
use App\Modules\Chloe\Assistant\GeminiModel;
use App\Modules\Chloe\Console\Commands\AssistantAsk;
use App\Modules\Chloe\Console\Commands\BackfillCandidacyAppeals;
use App\Modules\Chloe\Console\Commands\CandidacyDemoClear;
use App\Modules\Chloe\Console\Commands\CandidacyDemoSeed;
use App\Modules\Chloe\Console\Commands\CheckCandidacyAllowance;
use App\Modules\Chloe\Console\Commands\CloseExpiredCandidacyAppeals;
use App\Modules\Chloe\Console\Commands\AssistantCatalogue;
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
use App\Modules\Chloe\Console\Commands\VerifyCandidacyMemo;
use App\Modules\Chloe\Console\Commands\WorkstationDemoSeed;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyStaffProfile;
use App\Modules\Chloe\Policies\CandidacyAppealPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
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

        // One policy for every appeal route (pages, downloads, signature images, actions).
        Gate::policy(CandidacyAppeal::class, CandidacyAppealPolicy::class);
        Gate::policy(CandidacyStaffProfile::class, CandidacyAppealPolicy::class);

        // PART 6: "rate limited by config" for the assistant's three
        // routes -- per-user, not app-wide (that's providerBudgetAvailable()
        // in Assistant\Pipeline, a separate, smaller free-tier budget).
        RateLimiter::for('assistant-user', function ($request) {
            return Limit::perMinute((int) config('assistant.rate_limits.user_requests_per_minute', 10))
                ->by($request->user()?->id ?: $request->ip());
        });

        // The live-update pollers (see Http/Controllers/Cgs/WorkstationLiveController):
        // one open page polls ~4 times a minute, so 60 a minute per user
        // leaves room for several tabs and still stops a runaway loop.
        RateLimiter::for('workstation-live', function ($request) {
            return Limit::perMinute((int) config('assistant.rate_limits.live_requests_per_minute', 60))
                ->by('live:'.($request->user()?->id ?: $request->ip()));
        });

        // routes/console.php is a shared file, so this module schedules its own job here.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('candidacy:close-expired-appeals')->dailyAt('07:20')->timezone('Asia/Kuala_Lumpur')->withoutOverlapping();
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
                AssistantCatalogue::class,
                BackfillCandidacyAppeals::class,
                CheckCandidacyAllowance::class,
                CloseExpiredCandidacyAppeals::class,
                CandidacyDemoSeed::class,
                CandidacyDemoClear::class,
                VerifyCandidacyMemo::class,
                AssistantAsk::class,
                AssistantLogList::class,
                AssistantEvaluate::class,
            ]);
        }
    }
}
