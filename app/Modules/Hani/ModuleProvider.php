<?php

namespace App\Modules\Hani;

use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Hani\Console\ExaminersSweep;
use App\Modules\Hani\Console\ReVivaMonitor;
use App\Modules\Hani\Console\SlaReminders;
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
        $this->app->bind(\App\Modules\Core\Contracts\SuppliesReleasedExaminers::class, \App\Modules\Hani\Support\ReleasedExaminerProvider::class);
    }

    public function boot(ModuleRegistry $registry): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExaminersSweep::class, VivaReminders::class, ReVivaMonitor::class, SlaReminders::class]);
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

            $schedule->command('re-viva:monitor')
                ->dailyAt((string) config('hani.reviva.monitor_daily_at', '08:00'))
                ->withoutOverlapping();

            $schedule->command('hani:sla-reminders')
                ->dailyAt((string) config('hani.sla.daily_at', '08:15'))
                ->withoutOverlapping();
        });

        $this->quietEngineEmailsToReVivaStudents();

        $registry->register(new ExaminerNominationWorkflow());
        $registry->register(new ReVivaWorkflow());
        $registry->register(new VivaVoceWorkflow());
        $registry->register(new VivaSoftboundWorkflow());
    }

    /**
     * Re-viva sends its own student emails at the milestones (submitted,
     * returned, accepted, sent to the examiners, result), so the engine's
     * stage emails to the student ("approved at the Dean Approval stage")
     * are cancelled for re-viva only, through Laravel's NotificationSending
     * event. Other modules and other recipients (the Senior Executive told
     * of a Dean's return) are untouched.
     */
    protected function quietEngineEmailsToReVivaStudents(): void
    {
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Notifications\Events\NotificationSending::class, function ($event) {
            $notification = $event->notification;

            if (! $notification instanceof \App\Modules\Core\Notifications\ApplicationDecided
                && ! $notification instanceof \App\Modules\Core\Notifications\ApplicationReturned) {
                return null;
            }

            $application = (fn () => $this->application ?? null)->call($notification);

            return $application?->module_type === 're_viva' && $event->notifiable->id === $application->student_id ? false : null;
        });
    }
}
