<?php

namespace App\Modules\Hani\Console;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaExaminerProgress;
use App\Modules\Hani\Models\VivaReminderLog;
use App\Modules\Hani\Notifications\VivaReminder;
use App\Modules\Hani\Support\PanelSeats;
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
 *   panel_7d / panel_1d   7 days and 1 day before the viva (a viva set
 *                         closer than 7 days gets only panel_1d), with the
 *                         calendar file -> everyone invited; a reschedule
 *                         sends them again (keyed on the schedule sequence)
 *   softbound_nudge       the expected softbound date is within 14 days and
 *                         nothing is filed -> the student
 *   softbound_overdue     that date has passed and nothing is filed -> the AE
 *
 * Result & corrections:
 *   corrections_30d / corrections_7d   before the corrected thesis is due,
 *                         nothing submitted -> the student
 *   corrections_overdue   due date passed, nothing submitted -> the AE
 *   reexam_window_closed  category 4, the resubmission window has closed
 *                         and no re-viva was logged: the mains are freed
 *                         (no gap) and the AE is told
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
            $then = $reminder[6] ?? null;
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

            // Anything the reminder does besides telling someone, done once,
            // after the log row has claimed it.
            if ($then) {
                $then();
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
        yield from $this->correctionDeadlines();
        yield from $this->closedReexaminationWindows();
    }

    protected function correctionDeadlines(): iterable
    {
        $thresholds = collect((array) config('hani.result.reminder_days', [30, 7]))->map(fn ($d) => (int) $d)->sortDesc()->values();

        $details = VivaDetail::with('application.student')
            ->whereNotNull('corrections_due_at')
            ->whereNull('corrections_submitted_at')
            ->whereNull('corrections_accepted_at')
            ->where('corrections_due_at', '<=', now()->addDays($thresholds->first() ?? 30)->toDateString())
            ->get();

        foreach ($details as $detail) {
            $notice = $detail->application;
            $student = $notice->student;
            $due = $detail->corrections_due_at;
            $daysLeft = (int) now()->startOfDay()->diffInDays($due, false);

            if ($daysLeft < 0) {
                yield [$notice, 'corrections_overdue', '', $this->academicExecs($notice), [], new VivaReminder(
                    'corrections_overdue', $notice,
                    'Corrections overdue: '.$student?->name,
                    [
                        "{$student?->name}'s corrected final thesis (result {$detail->resultLabel()}) was due on {$due->format('j M Y')} and has not been submitted.",
                        'You may want to contact the student and their supervisor.',
                    ],
                    'Open Viva Results', route('viva.results.index'),
                )];

                continue;
            }

            // The largest threshold the deadline is within, so a short
            // deadline does not get two reminders on the same day.
            foreach ($thresholds as $i => $days) {
                $next = $thresholds[$i + 1] ?? -1;

                if ($daysLeft <= $days && $daysLeft > $next) {
                    yield [$notice, "corrections_{$days}d", '', [$student], [], new VivaReminder(
                        "corrections_{$days}d", $notice,
                        'Corrected thesis due by '.$due->format('j M Y'),
                        [
                            "Your corrected final thesis is due by {$due->format('j M Y')} ({$daysLeft} days from today).",
                            'Upload it on your viva page in UResearch when it is ready.',
                        ],
                        'Open your viva page', route('viva.eligibility'),
                    )];
                }
            }
        }
    }

    protected function closedReexaminationWindows(): iterable
    {
        $details = VivaDetail::with('application.student')
            ->where('result_category', VivaDetail::REEXAMINATION)
            ->whereNull('examiners_freed_at')
            ->whereNotNull('resubmission_closes_at')
            ->where('resubmission_closes_at', '<', today()->toDateString())
            ->get();

        foreach ($details as $detail) {
            $notice = $detail->application;

            // A re-viva logged since the result: the examiners stay held for it.
            $logged = Application::where('module_type', 're_viva')
                ->where('student_id', $notice->student_id)
                ->where('created_at', '>=', $detail->result_recorded_at ?? $detail->viva_at)
                ->exists();

            if ($logged) {
                continue;
            }

            $panel = VivaProgress::forNotices(collect([$notice]))->first()->panel();

            yield [$notice, 'reexam_window_closed', '', $this->academicExecs($notice), [], new VivaReminder(
                'reexam_window_closed', $notice,
                'Re-examination window closed: '.$notice->student?->name,
                [
                    "The window for {$notice->student?->name} to resubmit for re-examination closed on {$detail->resubmission_closes_at->format('j M Y')}, and no re-viva was logged.",
                    'The two main examiners have been freed, without a gap, since they did not examine again.',
                ],
                'Open Viva Results', route('viva.results.index'),
            ), function () use ($detail, $panel) {
                if ($panel) {
                    app(PanelSeats::class)->freeWithoutGap($panel, 'Re-examination window closed with no re-viva logged.');
                }
                $detail->update(['examiners_freed_at' => now()]);
            }];
        }
    }

    protected function panelReminders(): iterable
    {
        $details = VivaDetail::with('application.student.supervisor')
            ->whereNotNull('viva_at')
            ->where('viva_at', '>', now())
            ->where('viva_at', '<=', now()->addDays(max((array) config('hani.reminders.panel_days', [7, 1])) + 1))
            ->whereHas('application', fn ($q) => $q->where('module_type', 'viva_voce')->where('status', Application::STATUS_APPROVED))
            ->get();

        $thresholds = collect((array) config('hani.reminders.panel_days', [7, 1]))->map(fn ($d) => (int) $d)->sort()->values();
        $last = $thresholds->first();

        foreach ($details as $detail) {
            $notice = $detail->application;
            $daysAway = (int) now()->startOfDay()->diffInDays($detail->viva_at->copy()->startOfDay());

            foreach ($thresholds as $days) {
                // The last reminder (1 day) goes to any viva that close, even
                // one set for later today. An earlier one (7 days) goes on the
                // day, or the day after if a run was missed -- never to a viva
                // already inside the last reminder's window, and never late
                // to one set closer than that (its invitation is fresh).
                $due = $days === $last
                    ? $daysAway <= $days
                    : $daysAway <= $days && $daysAway >= max($last + 1, $days - 1);

                if (! $due) {
                    continue;
                }

                $panel = VivaProgress::forNotices(collect([$notice]))->first()->panel();
                $recipients = VivaInvite::recipients($notice, $detail, $panel);

                foreach ($recipients['skipped'] as $why) {
                    $this->warn("  panel_{$days}d for viva #{$notice->id}: examiner skipped. {$why}");
                }
                $when = $detail->viva_at->format('l, j F Y, g:i a');

                yield [$notice, "panel_{$days}d", 'seq:'.$detail->schedule_sequence, $recipients['users'], $recipients['emails'], new VivaReminder(
                    "panel_{$days}d", $notice,
                    match (true) { $daysAway === 0 => 'Viva today: ', $daysAway === 1 => 'Viva tomorrow: ', default => "Viva in {$daysAway} days: " }.$notice->student?->name,
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
