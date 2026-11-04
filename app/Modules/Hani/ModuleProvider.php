<?php

namespace App\Modules\Hani;

use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Hani\Console\ExaminersSweep;
use App\Modules\Hani\Console\VivaReminders;
use App\Modules\Hani\Support\Matching\ExaminerMatcher;
use App\Modules\Hani\Support\Matching\TfIdfMatcher;
use App\Modules\Hani\Support\Similarity\SimilaritySource;
use App\Modules\Hani\Support\Similarity\SimulatedSource;
use App\Modules\Hani\Support\Similarity\TurnitinApiSource;
use App\Modules\Hani\Support\Similarity\UploadedReportSource;
use App\Modules\Hani\Workflows\ExaminerNominationWorkflow;
use App\Modules\Hani\Workflows\ReVivaWorkflow;
use App\Modules\Hani\Workflows\VivaSoftboundWorkflow;
use App\Modules\Hani\Workflows\VivaVoceWorkflow;
use Illuminate\Console\Scheduling\Schedule;
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

        // Where a softbound thesis's similarity figures come from; see
        // config('hani.similarity.source') and Support\Similarity.
        $this->app->bind(SimilaritySource::class, fn ($app) => $app->make(match (config('hani.similarity.source')) {
            'turnitin_api' => TurnitinApiSource::class,
            'simulated' => SimulatedSource::class,
            default => UploadedReportSource::class,
        }));

        $this->bindReleasedExaminers();
    }

    /**
     * Hands the released examiner lists to whoever asks through Core's
     * contract -- Jason's appointment letters. The contract is proposed in
     * SHARED_CHANGES.md and not in Core yet; until it is, nothing is bound and
     * nothing breaks. interface_exists() autoloads, so this is safe to call
     * at register time.
     */
    public function bindReleasedExaminers(): void
    {
        $contract = 'App\\Modules\\Core\\Contracts\\SuppliesReleasedExaminers';

        if (interface_exists($contract)) {
            $this->app->bind($contract, \App\Modules\Hani\Support\ReleasedExaminerBinding::class);
        }
    }

    public function boot(ModuleRegistry $registry): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExaminersSweep::class, VivaReminders::class]);
        }

        // Scheduled from here, not routes/console.php (a shared file): the
        // scheduler is resolved only when `schedule:run`/`schedule:work` asks
        // for it, and this adds the job then. SHARED_CHANGES.md has the
        // one-line routes/console.php equivalent if the team prefers it there
        // -- add one or the other, not both.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('examiners:sweep')
                ->dailyAt((string) config('hani.sweep.daily_at', '07:30'))
                ->withoutOverlapping();

            $schedule->command('viva:reminders')
                ->dailyAt((string) config('hani.reminders.daily_at', '07:45'))
                ->withoutOverlapping();
        });

        $registry->register(new ExaminerNominationWorkflow());
        $registry->register(new ReVivaWorkflow());
        $registry->register(new VivaVoceWorkflow());
        $registry->register(new VivaSoftboundWorkflow());
    }
}
