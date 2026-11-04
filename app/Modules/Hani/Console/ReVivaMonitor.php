<?php

namespace App\Modules\Hani\Console;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Models\ReVivaReminderLog;
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

    protected $description = 'Re-viva reminders and escalations: panel time limits in working days';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sent = 0;
        $skipped = 0;

        foreach ($this->due() as $item) {
            [$detail, $kind, $reference, $users, $notification] = $item;
            $then = $item[5] ?? null;

            if ($dryRun) {
                $this->line(sprintf('  would send %s for re-viva #%d (%s) to %d recipient(s)', $kind, $detail->application_id, $detail->application->student?->name, count($users)));
                $sent++;

                continue;
            }

            try {
                ReVivaReminderLog::create([
                    're_viva_detail_id' => $detail->id,
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
                Notification::send(collect($users)->filter()->unique('id'), $notification);
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

    /** @return iterable<int, array{0: ReVivaDetail, 1: string, 2: string, 3: iterable<User>, 4: ReVivaUpdate, 5?: callable}> */
    protected function due(): iterable
    {
        yield from $this->panelTimeLimits();
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
