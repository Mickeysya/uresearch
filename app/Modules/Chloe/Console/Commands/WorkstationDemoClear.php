<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Models\DemoStudentProfile;
use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationHistory;
use App\Modules\Chloe\Models\WorkstationRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes everything workstation:demo-seed (or workstation:demo-login,
 * for Chloe Ching -- untouched here, see below) created for demo
 * profiles: the profiles themselves, their requests, their locker keys,
 * and their History rows, and returns every seat they occupied back to
 * Available.
 *
 * The ONLY code allowed to delete a History row at all -- every other
 * piece of this module treats History as append-only on purpose (see
 * WorkstationHistory's own class doc). Not exposed on any screen; a
 * console command only, run by a human who means to wipe the demo data.
 *
 * Finds everything to remove via demo_student_profiles -- the table
 * itself is the marker (see that model's class doc) -- never by name,
 * email pattern or student ID range, so it can never accidentally reach
 * a real account. Chloe Ching (26005007) has no demo_profile_id anywhere
 * and is never in this table, so nothing here can touch it.
 */
class WorkstationDemoClear extends Command
{
    protected $signature = 'workstation:demo-clear';

    protected $description = 'Remove every demo student profile and their requests, locker keys and History rows, returning their seats to Available';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('workstation:demo-clear refuses to run in production.');

            return self::FAILURE;
        }

        $profileIds = DemoStudentProfile::pluck('id');

        if ($profileIds->isEmpty()) {
            $this->info('No demo data to clear.');

            return self::SUCCESS;
        }

        $matricNos = DemoStudentProfile::pluck('matric_no');

        $seatCount = 0;
        $historyCount = 0;

        DB::transaction(function () use ($profileIds, $matricNos, &$seatCount, &$historyCount) {
            $seatIds = WorkstationRequest::whereIn('demo_profile_id', $profileIds)
                ->pluck('workstation_id')
                ->unique();

            Workstation::whereIn('id', $seatIds)->update(['status' => Workstation::STATUS_AVAILABLE]);
            $seatCount = $seatIds->count();

            $historyCount = WorkstationHistory::whereIn('student_matric_no', $matricNos)->count();
            WorkstationHistory::whereIn('student_matric_no', $matricNos)->delete();

            // Children before the profile itself; both also cascade on
            // delete (see the demo_profile_id migrations), this is just
            // explicit about the order rather than relying on that alone.
            LockerKey::whereIn('demo_profile_id', $profileIds)->delete();
            WorkstationRequest::whereIn('demo_profile_id', $profileIds)->delete();
            DemoStudentProfile::whereIn('id', $profileIds)->delete();
        });

        $this->info("Cleared {$profileIds->count()} demo student(s), restored {$seatCount} seat(s) to Available, deleted {$historyCount} History row(s).");

        return self::SUCCESS;
    }
}
