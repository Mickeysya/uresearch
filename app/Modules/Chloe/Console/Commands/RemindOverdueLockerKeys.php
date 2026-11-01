<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Support\ScheduledDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * The daily sweep behind a locker key's two deadlines: a reminder at
 * GRACE_DAYS (3), automatic cancellation at COLLECTION_PERIOD_DAYS (7). Both
 * thresholds and the actual state change live on LockerKey::processIfOverdue()
 * — this command is just "run it over every row still Pending Collection",
 * the same method the student's own page load also calls inline (rule 11),
 * so the two never drift out of step with each other.
 *
 * Every row here already belongs to a real student: locker_keys.student_id
 * is a required foreign key into users, and the only way a row exists at all
 * is LockerKeyController::store(), which requires a logged-in student with a
 * confirmed seat. The seed's dummy occupants (bulk-set Workstation.status,
 * no WorkstationRequest, no login) can never have one — nothing here needs
 * to filter them out separately.
 */
class RemindOverdueLockerKeys extends Command
{
    protected $signature = 'workstation:remind-locker-keys {--only= : cancel (07:30 refresh: auto-cancel only) or remind (08:00: day-3 reminder only)}';

    protected $description = 'Send locker key reminders and auto-cancel requests uncollected past the 7-day window';

    public function handle(): int
    {
        $only = $this->option('only') ?: null;

        // Old name, no --only: refresh first, then reminders, both inside the refresh context -- whatever it sends before 08:00 is queued for 08:00,
        // so the old schedule entry in routes/console.php (shared) cannot send anything early.
        if ($only === null) {
            ScheduledDelivery::refreshing(function () {
                $this->call('workstation:remind-locker-keys', ['--only' => 'cancel']);
                $this->call('workstation:remind-locker-keys', ['--only' => 'remind']);
            });

            return self::SUCCESS;
        }

        if ($only === 'remind') {
            return $this->withReminderLock(fn () => $this->sweep($only));
        }

        return ScheduledDelivery::refreshing(fn () => $this->sweep($only));
    }

    protected function withReminderLock(callable $run): int
    {
        // the old and the new schedule entry can both fire at 08:00; one waits for the other, and reminder_sent_at keeps it to one e-mail
        $lock = Cache::lock('workstation-key-reminders', 600);

        if (! $lock->block(30)) {
            $this->warn('Another reminder run is in progress.');

            return self::SUCCESS;
        }

        try {
            return $run();
        } finally {
            $lock->release();
        }
    }

    protected function sweep(string $only): int
    {
        $reminded = 0;
        $cancelled = 0;

        LockerKey::where('status', LockerKey::STATUS_REQUESTED)
            ->with(['student', 'workstationRequest.workstation.location'])
            ->get()
            ->each(function (LockerKey $lockerKey) use (&$reminded, &$cancelled, $only) {
                match ($lockerKey->processIfOverdue($only)) {
                    'reminded' => $reminded++,
                    'cancelled' => $cancelled++,
                    default => null,
                };
            });

        $this->info("Sent {$reminded} reminder(s); auto-cancelled {$cancelled} overdue request(s).");

        return self::SUCCESS;
    }
}
