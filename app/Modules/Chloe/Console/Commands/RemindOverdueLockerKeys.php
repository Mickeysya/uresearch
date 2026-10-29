<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Models\LockerKey;
use Illuminate\Console\Command;

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
    protected $signature = 'workstation:remind-locker-keys';

    protected $description = 'Send locker key reminders and auto-cancel requests uncollected past the 7-day window';

    public function handle(): int
    {
        $reminded = 0;
        $cancelled = 0;

        LockerKey::where('status', LockerKey::STATUS_REQUESTED)
            ->with(['student', 'workstationRequest.workstation.location'])
            ->get()
            ->each(function (LockerKey $lockerKey) use (&$reminded, &$cancelled) {
                match ($lockerKey->processIfOverdue()) {
                    'reminded' => $reminded++,
                    'cancelled' => $cancelled++,
                    default => null,
                };
            });

        $this->info("Sent {$reminded} reminder(s); auto-cancelled {$cancelled} overdue request(s).");

        return self::SUCCESS;
    }
}
