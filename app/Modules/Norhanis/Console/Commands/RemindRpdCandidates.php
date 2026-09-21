<?php

namespace App\Modules\Norhanis\Console\Commands;

use App\Modules\Norhanis\Models\Candidacy;
use App\Modules\Norhanis\Services\RpdReminderSender;
use Illuminate\Console\Command;

/**
 * The RPD reminder scan: 3, 2 and 1 months before each active candidacy's
 * deadline, email the student. Scheduled daily from routes/console.php.
 *
 * A candidacy stops being scanned the moment it leaves 'active' -- an appeal
 * under review (Candidacy::STATUS_APPEAL_PENDING) or a closed candidacy
 * (dismissed/completed) has no business receiving a "your deadline is
 * approaching" email.
 */
class RemindRpdCandidates extends Command
{
    protected $signature = 'rpd:remind';

    protected $description = 'Email students at 3, 2, and 1 months before their RPD candidacy deadline';

    public function handle(RpdReminderSender $sender): int
    {
        $sent = 0;

        Candidacy::where('status', Candidacy::STATUS_ACTIVE)
            ->with('student')
            ->chunkById(100, function ($candidacies) use (&$sent, $sender) {
                foreach ($candidacies as $candidacy) {
                    $sent += $this->remindFor($candidacy, $sender);
                }
            });

        $this->info("Sent {$sent} RPD deadline reminder(s).");

        return self::SUCCESS;
    }

    protected function remindFor(Candidacy $candidacy, RpdReminderSender $sender): int
    {
        $sent = 0;

        foreach (Candidacy::REMINDER_MONTH_MARKS as $mark) {
            if ($sender->sendIfDue($candidacy, $mark) === RpdReminderSender::SENT) {
                $sent++;
            }
        }

        return $sent;
    }
}
