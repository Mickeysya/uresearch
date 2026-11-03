<?php

namespace App\Modules\Hani\Console;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaExaminerProgress;
use App\Modules\Hani\Models\VivaReminderLog;
use App\Modules\Hani\Notifications\VivaReminder;
use App\Modules\Hani\Support\VivaChecklist;
use App\Modules\Hani\Support\VivaInvite;
use App\Modules\Hani\Support\VivaProgress;
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
 * Scheduling:
 *   panel_7d / panel_1d   7 days and 1 day before the viva, with the
 *                         calendar file -> everyone invited; a reschedule
 *                         sends them again (keyed on the schedule sequence)
 *   softbound_nudge       the expected softbound date is within 14 days and
 *                         nothing is filed -> the student
 *   softbound_overdue     that date has passed and nothing is filed -> the AE
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

    protected $description = 'Send the viva reminders: examiner replies and reports, panel reminders, softbound deadlines';

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
        yield from $this->panelReminders();
        yield from $this->softboundDeadlines();
    }

    protected function panelReminders(): iterable
    {
        $details = VivaDetail::with('application.student.supervisor')
            ->whereNotNull('viva_at')
            ->where('viva_at', '>', now())
            ->where('viva_at', '<=', now()->addDays(max((array) config('hani.reminders.panel_days', [7, 1])) + 1))
            ->whereHas('application', fn ($q) => $q->where('module_type', 'viva_voce')->where('status', Application::STATUS_APPROVED))
            ->get();

        foreach ($details as $detail) {
            $notice = $detail->application;
            $daysAway = (int) now()->startOfDay()->diffInDays($detail->viva_at->copy()->startOfDay());

            foreach ((array) config('hani.reminders.panel_days', [7, 1]) as $days) {
                // On the day, or the day after if a run was missed -- never
                // for a viva set closer than that (its invitation is fresh).
                if ($daysAway > $days || $daysAway < max(1, $days - 1) || ($days > 1 && $daysAway <= 1)) {
                    continue;
                }

                $panel = VivaProgress::forNotices(collect([$notice]))->first()->panel();
                $recipients = VivaInvite::recipients($notice, $detail, $panel);
                $when = $detail->viva_at->format('l, j F Y, g:i a');

                yield [$notice, "panel_{$days}d", 'seq:'.$detail->schedule_sequence, $recipients['users'], $recipients['emails'], new VivaReminder(
                    "panel_{$days}d", $notice,
                    ($days === 1 ? 'Viva tomorrow: ' : "Viva in {$days} days: ").$notice->student?->name,
                    [
                        "A reminder that the viva voce of {$notice->student?->name} is on {$when} at {$detail->venue}.",
                        'Thesis: '.$detail->thesis_title.'. Chairman: '.$detail->chair_name.'.',
                        'The calendar file is attached again in case you need it.',
                    ],
                    null, null,
                    VivaInvite::ics($notice, $detail),
                )];
            }
        }
    }

    protected function softboundDeadlines(): iterable
    {
        $nudgeDays = (int) config('hani.reminders.softbound_nudge_days', 14);

        $details = VivaDetail::with('application.student')
            ->whereNotNull('expected_softbound_date')
            ->where('expected_softbound_date', '<=', now()->addDays($nudgeDays)->toDateString())
            ->whereHas('application', fn ($q) => $q->where('module_type', 'viva_voce')->where('status', Application::STATUS_APPROVED))
            ->get();

        foreach ($details as $detail) {
            $notice = $detail->application;
            $student = $notice->student;

            // Filed (waiting or verified) means nothing to chase.
            $filed = Application::where('module_type', 'viva_softbound')->where('student_id', $notice->student_id)
                ->whereIn('status', [Application::STATUS_PENDING, Application::STATUS_APPROVED])->exists();

            if ($filed || ! $student) {
                continue;
            }

            $date = $detail->expected_softbound_date->format('j M Y');

            if ($detail->expected_softbound_date->isBefore(today())) {
                yield [$notice, 'softbound_overdue', '', $this->academicExecs($notice), [], new VivaReminder(
                    'softbound_overdue', $notice,
                    'Softbound thesis not filed: '.$student->name,
                    [
                        "{$student->name} expected to file the softbound thesis by {$date}, and nothing has been filed.",
                        'You may want to contact the student and their supervisor.',
                    ],
                    'Open Viva Scheduling', route('viva.schedule.index'),
                )];
            } else {
                yield [$notice, 'softbound_nudge', '', [$student], [], new VivaReminder(
                    'softbound_nudge', $notice,
                    'Your softbound thesis is due by '.$date,
                    [
                        "You told CGS you would submit your softbound thesis by {$date}.",
                        'When it is ready, file it through Form #019 in UResearch, with your thesis, the format confirmation form and your Turnitin report from ULearn.',
                    ],
                    'Open your viva page', route('viva.eligibility'),
                )];
            }
        }
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
