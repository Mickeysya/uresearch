<?php

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\Workstation;
use Illuminate\Database\Migrations\Migration;

/**
 * PART 1 #8: the new Awaiting Key Return status did not exist when these
 * seats were put back to Available with their former occupant's Collected
 * key turned Return Pending (the 3 demo "unreturned key" seats, and any
 * real one reached the same way since). One-time data migration: every
 * seat currently Available whose latest key is Return Pending becomes
 * Awaiting Key Return instead. Nothing about the key rows themselves
 * changes, and no History row is written -- this corrects a seat's status
 * to what the rule would already have produced had it existed, it is not
 * a new event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Workstation::withoutGlobalScopes()->where('status', Workstation::STATUS_AVAILABLE)
            ->get()
            ->each(function (Workstation $seat) {
                $latestKey = $seat->lockerKeysHistory()->latest('requested_at')->first();

                if ($latestKey?->status === LockerKey::STATUS_RETURN_PENDING) {
                    $seat->update(['status' => Workstation::STATUS_AWAITING_KEY_RETURN]);
                }
            });
    }

    public function down(): void
    {
        Workstation::withoutGlobalScopes()->where('status', Workstation::STATUS_AWAITING_KEY_RETURN)
            ->update(['status' => Workstation::STATUS_AVAILABLE]);
    }
};
