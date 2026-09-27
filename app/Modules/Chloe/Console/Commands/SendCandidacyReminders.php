<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Notifications\CandidacyReminder;
use Illuminate\Console\Command;

/**
 * Monthly-from-three-months-out reminders — three in total, matching the
 * as-is manual cadence. Scheduled from routes/console.php.
 *
 * Reminder dates come from StudyCandidacy::reminderDates() -- exact
 * calendar-month arithmetic (Carbon's subMonthsNoOverflow), not a fixed
 * 90/60/30-day count, so a short month clamps the same way the paper
 * process would (see that method's docblock for the worked example).
 *
 * Stop conditions (all four from the brief, in one query + one guard):
 * status != active covers softbound / dismissed / inactive / completed in
 * one filter, and hasOpenAppeal() covers "an appeal is submitted".
 */
class SendCandidacyReminders extends Command
{
    protected $signature = 'candidacy:remind';

    protected $description = 'Send the next due monthly candidacy-expiry reminder to each eligible student';

    public function handle(): int
    {
        $sent = 0;

        StudyCandidacy::where('status', StudyCandidacy::STATUS_ACTIVE)
            ->chunkById(100, function ($candidacies) use (&$sent) {
                foreach ($candidacies as $candidacy) {
                    if ($this->remind($candidacy)) {
                        $sent++;
                    }
                }
            });

        $this->info("Sent {$sent} candidacy reminder(s).");

        return self::SUCCESS;
    }

    protected function remind(StudyCandidacy $candidacy): bool
    {
        if ($candidacy->hasOpenAppeal()) {
            return false;
        }

        $today = now()->startOfDay();

        if ($today->gt($candidacy->candidacy_expiry_date->copy()->startOfDay())) {
            return false; // already past due — Dismiss's job, not Reminder's
        }

        $alreadySent = $candidacy->reminders()->pluck('reminder_number')->all();

        // today >= the reminder date, not today == it, so a day the job
        // didn't run on still catches up rather than skipping that reminder.
        foreach ($candidacy->reminderDates() as $number => $date) {
            if (! in_array($number, $alreadySent, true) && $today->gte($date)) {
                $candidacy->student?->notify(new CandidacyReminder($candidacy, $number));
                $candidacy->reminders()->create(['reminder_number' => $number, 'sent_at' => now()]);

                return true;
            }
        }

        return false;
    }
}
