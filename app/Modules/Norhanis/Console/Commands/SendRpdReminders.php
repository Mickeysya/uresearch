<?php

namespace App\Modules\Norhanis\Console\Commands;

use App\Modules\Core\Models\User;
use App\Modules\Norhanis\Models\Candidacy;
use App\Modules\Norhanis\Models\RpdReminderLog;
use App\Modules\Norhanis\Notifications\RpdDeadlineApproaching;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * The RPD reminder scheduler — norhanis.md Module 4, path 1.
 *
 * Scans the masterlist daily and emails the student and their supervisor at
 * the 3, 2 and 1-month marks before the Research Proposal Defence deadline.
 *
 * FIRING ONCE IS THE WHOLE PROBLEM. The command runs every day, so a naive
 * "deadline is within 3 months" test mails the student every morning for a
 * month. The guard is `rpd_reminder_logs`, which has a unique index on
 * (candidacy_id, milestone): the log row is written BEFORE the notification is
 * sent, and a duplicate-key failure is what skips the send. Checking first and
 * writing after would leave a window where two overlapping runs both pass the
 * check -- inserting first makes the database decide, not the timing.
 *
 * MILESTONES ARE NOT CUMULATIVE. A candidacy created 6 weeks before its
 * deadline should get the 1-month reminder when it falls due, not the 3- and
 * 2-month ones it already missed. Each milestone therefore fires only inside
 * its own window: at or under N months, but above N-1.
 */
class SendRpdReminders extends Command
{
    protected $signature = 'rpd:remind
                            {--dry-run : List what would be sent without sending or logging anything}';

    protected $description = 'Email students and supervisors at 3, 2 and 1 months before their RPD deadline';

    /** Months before the deadline at which a reminder goes out. */
    protected const MILESTONES = [3, 2, 1];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Only clocks that are still running. A defended or dismissed
        // candidacy has no deadline worth chasing.
        $candidacies = Candidacy::with(['student.supervisor'])
            ->whereIn('status', [Candidacy::STATUS_ACTIVE, Candidacy::STATUS_EXTENDED])
            ->whereDate('rpd_deadline', '>=', now()->startOfDay())
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($candidacies as $candidacy) {
            $milestone = $this->milestoneDueFor($candidacy);

            if ($milestone === null) {
                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    '  would send %d-month reminder to %s (deadline %s)',
                    $milestone,
                    $candidacy->student?->name ?? "student #{$candidacy->student_id}",
                    $candidacy->rpd_deadline->format('Y-m-d'),
                ));
                $sent++;

                continue;
            }

            // Claim the milestone first. If another run already has it, the
            // unique index throws and we skip rather than send twice.
            try {
                RpdReminderLog::create([
                    'candidacy_id' => $candidacy->id,
                    'milestone' => $milestone,
                    'deadline_at_send' => $candidacy->rpd_deadline,
                    'sent_at' => now(),
                ]);
            } catch (QueryException $e) {
                $skipped++;

                continue;
            }

            foreach ($this->recipientsFor($candidacy) as $recipient) {
                $recipient->notify(new RpdDeadlineApproaching($candidacy, $milestone));
            }

            $sent++;
        }

        $this->info($dryRun
            ? "Dry run: {$sent} reminder(s) would be sent."
            : "Sent {$sent} RPD reminder(s); {$skipped} already sent.");

        return self::SUCCESS;
    }

    /**
     * Which milestone, if any, falls due for this candidacy today.
     *
     * Returns the smallest milestone whose window the deadline currently sits
     * in, so a candidacy entered late gets the reminder it is actually due
     * rather than a backlog of ones whose moment has passed.
     */
    protected function milestoneDueFor(Candidacy $candidacy): ?int
    {
        $daysLeft = $candidacy->daysRemaining();

        foreach (self::MILESTONES as $milestone) {
            // Month boundaries in days, measured from the deadline backwards,
            // so "3 months" tracks the calendar rather than a flat 90.
            $opensAt = (int) now()->startOfDay()
                ->diffInDays($candidacy->rpd_deadline->copy()->subMonthsNoOverflow($milestone), false);

            $closesAt = $milestone === 1
                ? null
                : (int) now()->startOfDay()
                    ->diffInDays($candidacy->rpd_deadline->copy()->subMonthsNoOverflow($milestone - 1), false);

            // The window is open once today has reached the milestone date
            // ($opensAt <= 0) and, for all but the last, not yet reached the
            // next one ($closesAt > 0).
            if ($opensAt <= 0 && ($closesAt === null || $closesAt > 0) && $daysLeft >= 0) {
                return $milestone;
            }
        }

        return null;
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    protected function recipientsFor(Candidacy $candidacy): \Illuminate\Support\Collection
    {
        return collect([$candidacy->student, $candidacy->student?->supervisor])
            ->filter()
            ->unique('id')
            ->values();
    }
}
