<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Notifications\LockerKeyReminder;
use Illuminate\Console\Command;

/**
 * Nags a student whose locker key has sat uncollected past the grace period.
 *
 * Scheduled from routes/console.php. Same shape as Nureen's
 * RemindStalledSupervisionRequests: reminder_sent_at means a student is only
 * nagged again once another full period has passed, not on every run.
 */
class RemindOverdueLockerKeys extends Command
{
    protected $signature = 'workstation:remind-locker-keys {--days=3 : Grace period before a reminder is sent}';

    protected $description = 'Email students who have not collected their locker key within the grace period';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $overdue = LockerKey::query()
            ->where('status', LockerKey::STATUS_REQUESTED)
            ->where('requested_at', '<=', $cutoff)
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('reminder_sent_at')->orWhere('reminder_sent_at', '<=', $cutoff);
            })
            ->with('student')
            ->get();

        $sent = 0;

        foreach ($overdue as $lockerKey) {
            if (! $lockerKey->student) {
                continue;
            }

            $lockerKey->student->notify(new LockerKeyReminder($lockerKey));

            $lockerKey->update([
                'reminder_sent_at' => now(),
                'reminder_count' => $lockerKey->reminder_count + 1,
            ]);

            $sent++;
        }

        $this->info("Sent {$sent} locker key reminder(s).");

        return self::SUCCESS;
    }
}
