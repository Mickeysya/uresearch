<?php

namespace App\Modules\Hani\Console;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\VivaExaminerProgress;
use App\Modules\Hani\Models\VivaReminderLog;
use App\Modules\Hani\Notifications\VivaReminder;
use App\Modules\Hani\Support\VivaChecklist;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * The viva's daily chasing, which the AE does by hand today.
 *
 * Examiner progress:
 *   ack_chaser       a letter went out N days ago and the examiner has
 *                    neither accepted nor rejected -> the AE
 *   report_overdue   the report is past due (4 weeks Master / 6 PhD after
 *                    the examiner received the thesis) -> the AE
 *
 * Each reminder is sent ONCE. As with `rpd:remind` and `examiners:sweep`,
 * the log row is written BEFORE sending, and the unique key on (viva, kind,
 * reference) makes the database decide that a second run skips it.
 * Scheduled from Hani\ModuleProvider; see SHARED_CHANGES.md for the
 * routes/console.php alternative.
 */
class VivaReminders extends Command
{
    protected $signature = 'viva:reminders
                            {--dry-run : List what would be sent without sending or logging anything}';

    protected $description = 'Send the viva reminders: examiner replies and reports';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sent = 0;
        $skipped = 0;

        foreach ($this->due() as $reminder) {
            [$notice, $kind, $reference, $users, $emails, $notification] = $reminder;
            $who = count($users) + count($emails);

            if ($dryRun) {
                $this->line(sprintf('  would send %s for viva #%d (%s) to %d recipient(s)', $kind, $notice->id, $notice->student?->name, $who));
                $sent++;

                continue;
            }

            try {
                VivaReminderLog::create([
                    'viva_application_id' => $notice->id,
                    'kind' => $kind,
                    'reference' => $reference,
                    'sent_at' => now(),
                ]);
            } catch (QueryException) {
                $skipped++;

                continue;
            }

            try {
                Notification::send($users, $notification);

                foreach ($emails as $email => $name) {
                    Notification::route('mail', [$email => $name])->notify($notification);
                }
            } catch (\Throwable $e) {
                report($e);
            }

            $sent++;
        }

        $this->info($dryRun
            ? "Dry run: {$sent} viva reminder(s) would be sent."
            : "Sent {$sent} viva reminder(s); {$skipped} already sent.");

        return self::SUCCESS;
    }

    /**
     * Every reminder due today, sent or not; the log decides.
     *
     * @return iterable<int, array{0: Application, 1: string, 2: string, 3: array<int, User>, 4: array<string, string>, 5: VivaReminder}>
     */
    protected function due(): iterable
    {
        yield from $this->examinerReplies();
        yield from $this->overdueReports();
    }

    protected function examinerReplies(): iterable
    {
        $days = (int) config('hani.examiner_progress.ack_chase_days', 14);

        foreach ($this->currentRows()->whereNotNull('letter_sent_at')->whereNull('acknowledgement')
            ->where('letter_sent_at', '<=', now()->subDays($days)->toDateString())->get() as $row) {
            $notice = $row->notice;

            yield [$notice, 'ack_chaser', 'progress:'.$row->id, $this->academicExecs($notice), [], new VivaReminder(
                'ack_chaser', $notice,
                'No reply from an examiner: '.$notice->student?->name,
                [
                    "{$row->sideLabel()} examiner {$row->examiner->name} was sent the appointment letter on {$row->letter_sent_at->format('j M Y')} and has not accepted or rejected it ({$days} days or more).",
                    'Please follow up with the examiner. If they decline, record "Rejected" on the examiner progress page and the backup is promoted automatically.',
                ],
                'Open examiner progress', route('viva.examiners.show', $notice),
            )];
        }
    }

    protected function overdueReports(): iterable
    {
        foreach ($this->currentRows()->whereNotNull('thesis_received_at')->whereNull('report_received_at')->get() as $row) {
            $notice = $row->notice;
            $level = $notice->student ? VivaChecklist::levelFor($notice->student) : null;
            $due = $row->reportDueAt($level);

            if (! $due || ! $due->isPast()) {
                continue;
            }

            yield [$notice, 'report_overdue', 'progress:'.$row->id, $this->academicExecs($notice), [], new VivaReminder(
                'report_overdue', $notice,
                'Examiner report overdue: '.$notice->student?->name,
                [
                    "{$row->sideLabel()} examiner {$row->examiner->name} received the thesis on {$row->thesis_received_at->format('j M Y')}; the report was due on {$due->format('j M Y')} and has not been recorded.",
                    'Please chase the examiner, then record the report and its category on the examiner progress page.',
                ],
                'Open examiner progress', route('viva.examiners.show', $notice),
            )];
        }
    }

    /** Current progress rows on acknowledged notices. */
    protected function currentRows()
    {
        return VivaExaminerProgress::with('examiner', 'notice.student')
            ->current()
            ->whereHas('notice', fn ($q) => $q->where('module_type', 'viva_voce')->where('status', Application::STATUS_APPROVED));
    }

    /** @return array<int, User> the AEs of the candidate's department */
    protected function academicExecs(Application $notice)
    {
        return User::where('role', Role::ACADEMIC_EXEC)
            ->where('department', $notice->student?->department)
            ->get()
            ->all();
    }
}
