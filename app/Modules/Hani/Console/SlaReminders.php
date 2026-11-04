<?php

namespace App\Modules\Hani\Console;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\SlaReminderLog;
use App\Modules\Hani\Notifications\SlaReminder;
use App\Modules\Hani\Support\StageSla;
use App\Modules\Hani\Support\VivaProgress;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Reminds whoever holds the current stage when one of Hani's applications
 * (examiner nomination, Notice of Thesis Submission, softbound check,
 * re-viva) has waited there past a limit in hani.sla.
 *
 *   threshold 1   the stage's approver (the AE of the candidate's
 *                 department, or everyone holding the stage's role)
 *   threshold 2   the approver again, with the Senior Executive CGS copied
 *
 * Each threshold is sent ONCE per visit to the stage: the log row is written
 * BEFORE sending, keyed on (application, stage, when it reached the stage,
 * threshold), so a second run skips it and a list returned to the stage
 * starts again. An application already past both on its first run gets the
 * second only; the first is logged as covered, not sent.
 *
 * Stages another command already chases are in hani.sla.skip (re-viva panel
 * review: `re-viva:monitor`). Examiner replies, report deadlines, panel
 * reminders and softbound deadlines are not workflow stages and stay with
 * `viva:reminders`. Scheduled from Hani\ModuleProvider.
 */
class SlaReminders extends Command
{
    /** Where each module's queue is, for the email's button. */
    protected const QUEUES = [
        'examiner_nomination' => 'examiner-nomination.queue',
        'viva_voce' => 'viva.queue',
        'viva_softbound' => 'viva.softbound.queue',
        're_viva' => 'reviva.queue',
    ];

    protected $signature = 'hani:sla-reminders
                            {--dry-run : List what would be sent without sending or logging anything}';

    protected $description = 'Remind approvers of examiner nominations, viva notices, softbound checks and re-vivas waiting past their stage limit';

    public function handle(StageSla $sla): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sent = 0;
        $skipped = 0;

        foreach ($sla->overdue() as $row) {
            /** @var Application $application */
            $application = $row['application'];
            $level = $row['level'];
            $stage = $application->currentStage();
            $recipients = $this->recipients($application, $stage?->role, $level);
            $unit = $row['working_days'] ? 'working days' : 'days';

            if ($dryRun) {
                if ($this->alreadyLogged($application, $row, $level)) {
                    $skipped++;
                } else {
                    $this->line(sprintf(
                        '  would send sla_%d for %s #%d (%s) at %s: %d %s, limit %d, to %d recipient(s)',
                        $level, $application->module()->label(), $application->id, $application->student?->name,
                        $stage?->label ?? $row['stage'], $row['days'], $unit, $row['thresholds'][$level - 1], $recipients->count(),
                    ));
                    $sent++;
                }

                continue;
            }

            try {
                SlaReminderLog::create($this->logRow($application, $row, $level, true));
            } catch (QueryException) {
                $skipped++;

                continue;
            }

            for ($lower = 1; $lower < $level; $lower++) {
                SlaReminderLog::insertOrIgnore($this->logRow($application, $row, $lower, false));
            }

            try {
                Notification::send($recipients, $this->notification($application, $row, $level));
            } catch (\Throwable $e) {
                report($e);
            }

            $sent++;
        }

        $this->info($dryRun
            ? "Dry run: {$sent} SLA reminder(s) would be sent; {$skipped} already sent."
            : "Sent {$sent} SLA reminder(s); {$skipped} already sent.");

        return self::SUCCESS;
    }

    protected function alreadyLogged(Application $application, array $row, int $level): bool
    {
        return SlaReminderLog::where('application_id', $application->id)
            ->where('stage_key', $row['stage'])
            ->where('entered_at', $row['entered_at'])
            ->where('threshold', $level)
            ->exists();
    }

    protected function logRow(Application $application, array $row, int $level, bool $sent): array
    {
        return [
            'application_id' => $application->id,
            'stage_key' => $row['stage'],
            'entered_at' => $row['entered_at'],
            'threshold' => $level,
            'days' => $row['days'],
            'sent' => $sent,
            'sent_at' => now(),
        ];
    }

    /** @return Collection<int, User> */
    protected function recipients(Application $application, ?string $role, int $level): Collection
    {
        $approvers = match (true) {
            $role === null => collect(),
            $role === Role::ACADEMIC_EXEC => $application->student ? VivaProgress::academicExecsFor($application->student) : collect(),
            default => User::where('role', $role)->orderBy('name')->get(),
        };

        if ($level >= 2) {
            $approvers = $approvers->merge(User::where('role', Role::SENIOR_EXEC_CGS)->get());
        }

        return $approvers->unique('id')->values();
    }

    protected function notification(Application $application, array $row, int $level): SlaReminder
    {
        $stage = $application->currentStage();
        $module = $application->module()->label();
        $student = $application->student;
        $unit = $row['working_days'] ? 'working days' : 'days';
        $limit = $row['thresholds'][$level - 1];
        $since = $row['entered_at']->format('j M Y');

        $lines = [
            "{$module} {$application->reference()} for {$student?->name} ({$student?->department}) has been at \"{$stage?->label}\" since {$since}: {$row['days']} {$unit}, against a limit of {$limit}.",
            $level >= 2
                ? 'This is the second reminder; the Senior Executive CGS has been copied.'
                : 'Please act on it, or record why it is waiting. A second reminder, copied to the Senior Executive CGS, follows at '.($row['thresholds'][1] ?? $limit).' '.$unit.'.',
        ];

        $route = self::QUEUES[$application->module_type] ?? 'dashboard';

        return new SlaReminder(
            $level,
            $application,
            ($level >= 2 ? 'Still waiting' : 'Waiting')." {$row['days']} {$unit}: {$module} for {$student?->name}",
            $lines,
            'Open the queue',
            route($route, $route === 'dashboard' ? [] : ['stage' => $row['stage']]),
        );
    }
}
