<?php

namespace App\Modules\Hani\Console;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Models\ReVivaReminderLog;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaReminderLog;
use App\Modules\Hani\Support\PanelSeats;
use App\Modules\Hani\Support\ReVivaOutcome;
use Carbon\CarbonInterface;
use App\Modules\Hani\Notifications\ReVivaUpdate;
use App\Modules\Hani\Support\VivaProgress;
use App\Modules\Hani\Support\WorkingDays;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Notification;

/**
 * `re-viva:monitor`: the re-viva clocks nobody should have to watch by hand.
 *
 *   panel_chase      Under Panel Review for hani.reviva.panel_chase_working_days
 *                    (20): remind the AE to chase the panel
 *   panel_escalate   ... for panel_escalate_working_days (25): tell the
 *                    Senior Executive
 *   window_{30,7}d   the student, before the resubmission window closes:
 *                    a category-4 viva with no round logged (logged in
 *                    viva_reminder_logs, against the viva), or a level-4
 *                    round with no next round
 *   next_round_window_closed  a level-4 round's window closed with no next
 *                    round: the mains are freed with no gap (they did not
 *                    examine again); AE and Senior Executive told once
 *   final_{30,7}d    the student, before the final-thesis deadline (1-3)
 *   final_overdue    the deadline passed: AE and Senior Executive told once,
 *                    and the round is flagged on Re-viva Cycles
 *
 * Each is sent once per round: a row in re_viva_reminder_logs is written
 * BEFORE sending, and its unique key makes a second run skip it (the
 * rpd:remind pattern). --dry-run lists what would go without sending or
 * logging. Scheduled daily from Hani\ModuleProvider.
 */
class ReVivaMonitor extends Command
{
    protected $signature = 're-viva:monitor
                            {--dry-run : List what would be sent without sending or logging anything}';

    protected $description = 'Re-viva reminders and escalations: panel time limits, resubmission windows, final-thesis deadlines';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sent = 0;
        $skipped = 0;

        foreach ($this->due() as $item) {
            [$subject, $kind, $reference, $users, $notification] = $item;
            $then = $item[5] ?? null;
            $users = collect($users)->filter()->unique('id');

            if ($dryRun) {
                $application = $subject instanceof ReVivaDetail ? $subject->application : $subject;
                $this->line(sprintf('  would send %s for %s #%d (%s) to %d recipient(s)', $kind, $application->module_type, $application->id, $application->student?->name, $users->count()));
                $sent++;

                continue;
            }

            try {
                // A round logs against itself; a category-4 viva with no
                // round yet logs against the viva.
                $subject instanceof ReVivaDetail
                    ? ReVivaReminderLog::create(['re_viva_detail_id' => $subject->id, 'kind' => $kind, 'reference' => $reference, 'sent_at' => now()])
                    : VivaReminderLog::create(['viva_application_id' => $subject->id, 'kind' => 'reviva_'.$kind, 'reference' => $reference, 'sent_at' => now()]);
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
            } catch (\Throwable $e) {
                report($e);
            }

            $sent++;
        }

        $this->info($dryRun
            ? "Dry run: {$sent} re-viva reminder(s) would be sent."
            : "Sent {$sent} re-viva reminder(s); {$skipped} already sent.");

        return self::SUCCESS;
    }

    /** @return iterable<int, array{0: ReVivaDetail|Application, 1: string, 2: string, 3: iterable<User>, 4: ReVivaUpdate, 5?: callable}> */
    protected function due(): iterable
    {
        yield from $this->panelTimeLimits();
        yield from $this->vivaWindows();
        yield from $this->nextRoundWindows();
        yield from $this->finalTheses();
    }

    /** @return \Illuminate\Support\Collection<int, int> */
    protected function thresholds()
    {
        return collect((array) config('hani.result.reminder_days', [30, 7]))->map(fn ($d) => (int) $d)->sortDesc()->values();
    }

    /** The largest threshold $daysLeft is within, so a short deadline is not reminded twice in a day. */
    protected function threshold(int $daysLeft): ?int
    {
        $thresholds = $this->thresholds();

        foreach ($thresholds as $i => $days) {
            if ($daysLeft <= $days && $daysLeft > ($thresholds[$i + 1] ?? -1)) {
                return $days;
            }
        }

        return null;
    }

    /** Round 1: a category-4 viva whose window is closing with no round logged. */
    protected function vivaWindows(): iterable
    {
        $details = VivaDetail::with('application.student')
            ->where('result_category', VivaDetail::REEXAMINATION)
            ->whereNull('examiners_freed_at')
            ->whereNotNull('resubmission_closes_at')
            // Datetimes, not date strings: a date column can hold "Y-m-d 00:00:00".
            ->whereBetween('resubmission_closes_at', [today(), today()->addDays($this->thresholds()->first() ?? 30)->endOfDay()])
            ->get();

        foreach ($details as $viva) {
            $notice = $viva->application;
            $logged = ReVivaDetail::where('viva_application_id', $notice->id)->exists();
            $days = $this->threshold((int) today()->diffInDays($viva->resubmission_closes_at, false));

            if ($logged || $days === null) {
                continue;
            }

            yield [$notice, "window_{$days}d", '', [$notice->student], $this->windowReminder($notice, $viva->resubmission_closes_at, 1)];
        }
    }

    /** Round N+1: a level-4 round whose next window is closing, or has closed. */
    protected function nextRoundWindows(): iterable
    {
        $details = ReVivaDetail::with('application.student')
            ->where('outcome_level', ReVivaDetail::OUTCOME_LOOP_BACK)
            ->whereDoesntHave('nextCycle')
            ->get();

        foreach ($details as $detail) {
            $closes = ReVivaOutcome::nextRoundCloses($detail);
            $daysLeft = (int) today()->diffInDays($closes, false);
            $student = $detail->application->student;

            if ($daysLeft < 0) {
                $panel = $detail->panel();

                yield [$detail, 'next_round_window_closed', '', VivaProgress::academicExecsFor($student)->merge(User::where('role', Role::SENIOR_EXEC_CGS)->get()), new ReVivaUpdate(
                    'next_round_window_closed', $detail->application,
                    'Re-viva window closed: '.$student->name,
                    [
                        "The window for {$student->name} to resubmit for re-viva round ".($detail->cycle_number + 1)." closed on {$closes->format('j M Y')}, and no round was logged.",
                        'The two main examiners have been freed, without a gap, since they did not examine again.',
                    ],
                    'Open Re-viva Cycles', route('reviva.cycles.index'),
                ), function () use ($panel) {
                    if ($panel) {
                        app(PanelSeats::class)->freeWithoutGap($panel, 'Re-viva level 4: the next round\'s window closed with no round logged.');
                    }
                }];

                continue;
            }

            if ($days = $this->threshold($daysLeft)) {
                yield [$detail, "window_{$days}d", '', [$student], $this->windowReminder($detail->application, $closes, $detail->cycle_number + 1)];
            }
        }
    }

    protected function windowReminder(Application $application, CarbonInterface $closes, int $round): ReVivaUpdate
    {
        $daysLeft = (int) today()->diffInDays($closes, false);

        return new ReVivaUpdate(
            'window_closing', $application,
            'Corrected thesis due to CGS by '.$closes->format('j M Y'),
            [
                "Your corrected thesis for re-viva round {$round} is due to CGS by {$closes->format('j M Y')} ({$daysLeft} days from today).",
                'Send it to the Centre for Graduate Studies with the Summary of Corrections endorsed by your supervisor.',
            ],
        );
    }

    /** Levels 1-3: the final thesis, before and after its deadline. */
    protected function finalTheses(): iterable
    {
        $details = ReVivaDetail::with('application.student')
            ->whereIn('outcome_level', ReVivaDetail::OUTCOME_CORRECTIONS)
            ->whereNull('corrections_accepted_at')
            ->whereNotNull('correction_deadline')
            ->where('correction_deadline', '<=', today()->addDays($this->thresholds()->first() ?? 30)->endOfDay())
            ->get();

        foreach ($details as $detail) {
            $student = $detail->application->student;
            $due = $detail->correction_deadline;
            $daysLeft = (int) today()->diffInDays($due, false);

            if ($daysLeft < 0) {
                yield [$detail, 'final_overdue', '', VivaProgress::academicExecsFor($student)->merge(User::where('role', Role::SENIOR_EXEC_CGS)->get()), new ReVivaUpdate(
                    'final_overdue', $detail->application,
                    'Re-viva final thesis overdue: '.$student->name,
                    [
                        "{$student->name}'s final thesis after re-viva round {$detail->cycle_number} ({$detail->outcomeLabel()}) was due on {$due->format('j M Y')}"
                            .($detail->final_thesis_submitted_at ? ' and is waiting for acceptance.' : ' and has not been submitted.'),
                        'The round is flagged on Re-viva Cycles.',
                    ],
                    'Open Re-viva Cycles', route('reviva.cycles.index'),
                )];

                continue;
            }

            if ($detail->final_thesis_submitted_at === null && ($days = $this->threshold($daysLeft))) {
                yield [$detail, "final_{$days}d", '', [$student], new ReVivaUpdate(
                    'final_due', $detail->application,
                    'Final thesis due by '.$due->format('j M Y'),
                    [
                        "Your final thesis after re-viva round {$detail->cycle_number} is due by {$due->format('j M Y')} ({$daysLeft} days from today).",
                        'Upload it on My Re-viva in UResearch.',
                    ],
                    'Open My Re-viva', route('reviva.mine'),
                )];
            }
        }
    }

    protected function panelTimeLimits(): iterable
    {
        $chase = (int) config('hani.reviva.panel_chase_working_days', 20);
        $escalate = (int) config('hani.reviva.panel_escalate_working_days', 25);

        $details = ReVivaDetail::with('application.student')
            ->whereNotNull('sent_to_panel_at')
            ->whereHas('application', fn ($q) => $q->where('module_type', 're_viva')
                ->where('status', Application::STATUS_PENDING)
                ->where('current_stage', 'under_panel_review'))
            ->get();

        foreach ($details as $detail) {
            $student = $detail->application->student;
            $days = WorkingDays::between($detail->sent_to_panel_at, today());
            $sent = $detail->sent_to_panel_at->format('j M Y');

            if ($days >= $chase) {
                yield [$detail, 'panel_chase', '', VivaProgress::academicExecsFor($student), new ReVivaUpdate(
                    'panel_chase', $detail->application,
                    "Re-viva panel review at {$days} working days: {$student->name}",
                    [
                        "The correction letters for {$student->name}'s re-viva (round {$detail->cycle_number}) went to the panel on {$sent}, {$days} working days ago. The panel was given {$chase} working days.",
                        'Please chase both examiners for their Thesis Re-evaluation Reports (UTP/CGS/024). The Senior Executive is told if nothing has come back after '.$escalate.' working days.',
                    ],
                    'Open the re-viva queue', route('reviva.queue', ['stage' => 'under_panel_review']),
                )];
            }

            if ($days >= $escalate) {
                yield [$detail, 'panel_escalate', '', User::where('role', Role::SENIOR_EXEC_CGS)->get(), new ReVivaUpdate(
                    'panel_escalate', $detail->application,
                    "Re-viva panel overdue ({$days} working days): {$student->name}",
                    [
                        "The panel for {$student->name}'s re-viva (round {$detail->cycle_number}, {$student->department}) has had the corrected thesis for {$days} working days, since {$sent}, against a limit of {$chase}.",
                        'The Academic Executive was reminded at '.$chase.' working days. Please follow up with the department.',
                    ],
                    'Open Re-viva Cycles', route('reviva.cycles.index'),
                )];
            }
        }
    }
}
