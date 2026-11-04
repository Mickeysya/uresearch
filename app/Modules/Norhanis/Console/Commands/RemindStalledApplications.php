<?php

namespace App\Modules\Norhanis\Console\Commands;

use App\Modules\Core\Models\Application;
use App\Modules\Norhanis\Models\StageReminder;
use App\Modules\Norhanis\Notifications\ApplicationTakingLonger;
use App\Modules\Norhanis\Notifications\StalledApplicationReminder;
use App\Modules\Norhanis\Support\ApproverNotifier;
use App\Modules\Norhanis\Support\StageDurations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reminds approvers about Travel, Claims and Publication applications that
 * have waited on their stage longer than the stage should take, and tells
 * the student the approver has been reminded.
 *
 * "Longer than it should take" is the stage's client charter days, the
 * same rule as the "Past charter" mark on the queue. Scheduled every weekday
 * morning from ModuleProvider.
 *
 * Never spams: an application is reminded about at most once every
 * REPEAT_DAYS working days on the same stage, and the student is told only
 * with the first reminder for each stage. It only sends email -- an
 * application's status and stage are never touched.
 */
class RemindStalledApplications extends Command
{
    protected $signature = 'norhanis:remind-stalled';

    protected $description = 'Remind approvers about stalled Travel, Claims and Publication applications, and tell the students';

    /** Working days between two reminders about the same application and stage. */
    public const REPEAT_DAYS = 2;

    public function handle(StageDurations $durations, ApproverNotifier $notifier): int
    {
        $reminded = 0;

        $applications = Application::query()
            ->with('student')
            ->where('status', Application::STATUS_PENDING)
            ->whereIn('module_type', StageDurations::MODULES)
            ->orderBy('id');

        foreach ($applications->cursor() as $application) {
            $current = collect($durations->estimate($application)['steps'] ?? [])->firstWhere('state', 'current');

            if (! $current || ! $current['overdue']) {
                continue;
            }

            $stage = $current['stage'];
            $earlier = StageReminder::where('application_id', $application->id)->where('stage_key', $stage->key);
            $last = (clone $earlier)->latest('sent_at')->value('sent_at');

            if ($last && StageDurations::workingDaysBetween($last, now()) < self::REPEAT_DAYS) {
                continue;
            }

            $firstForStage = ! $earlier->exists();
            $waited = (int) ceil($current['waited']);

            foreach ($notifier->holders($application, $stage->role) as $approver) {
                $this->safely(fn () => $approver->notify(new StalledApplicationReminder($application, $stage, $waited)));
            }

            if ($firstForStage) {
                $this->safely(fn () => $application->student?->notify(new ApplicationTakingLonger($application, $stage)));
            }

            StageReminder::create(['application_id' => $application->id, 'stage_key' => $stage->key, 'sent_at' => now()]);
            $reminded++;
        }

        $this->info("Reminded approvers about {$reminded} stalled ".($reminded === 1 ? 'application' : 'applications').'.');

        return self::SUCCESS;
    }

    /** Best effort, as ApproverNotifier's own sends are: one bad address must not stop the run. */
    protected function safely(callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::warning('Norhanis stalled reminder failed', ['error' => $e->getMessage()]);
        }
    }
}
