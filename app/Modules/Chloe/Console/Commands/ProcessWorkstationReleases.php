<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\WorkstationHistory;
use App\Modules\Chloe\Models\WorkstationTerminationNotice;
use App\Modules\Chloe\Notifications\LockerKeyNotice;
use App\Modules\Chloe\Services\WorkstationAllocator;
use App\Modules\Chloe\Support\WorkstationTerminationTriggers;
use Illuminate\Console\Command;

/**
 * PART 3's daily job -- replaces the old 14/7/3-day Release Notice pipeline
 * entirely (see WorkstationAllocator and WorkstationHistory's own docs for
 * what that pipeline's leftover columns/constant are kept for). Two jobs in
 * one run, same as before:
 *
 *   1. Start a termination notice for every automatic trigger
 *      Support\WorkstationTerminationTriggers actually finds (one per
 *      request -- that class itself never returns a request that already
 *      has an active notice).
 *   2. Auto-release every seat whose notice's vacate-by day has ended
 *      (actor System, no email/bell, History "Seat released (vacate
 *      period ended)").
 *   3. The existing locker-key return reminders (PART 3 is silent on
 *      these; they are untouched).
 *
 * Demo students are skipped by both (1) and (2): (1) never finds one in
 * the first place (WorkstationTerminationTriggers queries student_id, a
 * demo occupant's WorkstationRequest has demo_profile_id instead); (2)'s
 * query filters them out explicitly, since a demo seat's notice is
 * refreshed by workstation:demo-seed, not released by this job.
 *
 * --dry-run prints what WOULD happen and writes nothing at all -- see
 * handle()'s own guard, checked before every write in this class.
 */
class ProcessWorkstationReleases extends Command
{
    protected $signature = 'workstation:process-releases {--dry-run : Print what would happen; change nothing}';

    protected $description = 'Start termination notices for automatic triggers, auto-release seats whose vacate-by day has ended, and send locker key return reminders';

    public function handle(WorkstationAllocator $allocator): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $started = $this->startTerminations($allocator, $dryRun);
        $released = $this->releaseEndedNotices($allocator, $dryRun);
        $reminded = $dryRun ? 0 : $this->sendReturnReminders();

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Terminations started {$started}, seats released {$released}, return reminders {$reminded}.");

        return self::SUCCESS;
    }

    protected function startTerminations(WorkstationAllocator $allocator, bool $dryRun): int
    {
        $triggers = WorkstationTerminationTriggers::findForOccupiedSeats();

        foreach ($triggers as $trigger) {
            $request = $trigger['request'];
            $reason = $trigger['reason'];

            if ($dryRun) {
                $this->line("[DRY RUN] Would start termination: {$request->occupant()?->name} (request #{$request->id}), reason \"{$reason}\".");

                continue;
            }

            $allocator->startTermination(
                $request,
                WorkstationHistory::ACTOR_SYSTEM,
                null,
                $reason,
            );
        }

        return count($triggers);
    }

    protected function releaseEndedNotices(WorkstationAllocator $allocator, bool $dryRun): int
    {
        $ended = WorkstationTerminationNotice::where('status', WorkstationTerminationNotice::STATUS_ACTIVE)
            ->where('vacate_by_date', '<=', now()->startOfDay())
            ->with('workstationRequest')
            ->get()
            // Demo requests carry demo_profile_id, not student_id -- a demo
            // seat's notice is refreshed by workstation:demo-seed, never
            // released by this job.
            ->filter(fn (WorkstationTerminationNotice $notice) => $notice->workstationRequest?->student_id !== null);

        foreach ($ended as $notice) {
            $request = $notice->workstationRequest;

            if ($dryRun) {
                $this->line("[DRY RUN] Would release: {$request->occupant()?->name} (request #{$request->id}), vacate-by {$notice->vacate_by_date->format('j M Y')}, reason \"{$notice->reason}\".");

                continue;
            }

            $allocator->releaseSeat(
                $request,
                WorkstationHistory::ACTOR_SYSTEM,
                null,
                $notice->reason,
                null,
                isAutomaticRelease: true,
                historyAction: WorkstationHistory::ACTION_SEAT_RELEASED_VACATE_ENDED,
            );
        }

        return $ended->count();
    }

    protected function sendReturnReminders(): int
    {
        $sent = 0;

        LockerKey::where('status', LockerKey::STATUS_RETURN_PENDING)
            ->with('student')
            ->get()
            ->each(function (LockerKey $lockerKey) use (&$sent) {
                if (! $lockerKey->student || ! $lockerKey->isReturnReminderDue()) {
                    return;
                }

                $lockerKey->update(['return_reminder_sent_at' => now()]);
                $lockerKey->student->notify(new LockerKeyNotice($lockerKey, LockerKeyNotice::RETURN_REMINDER));
                $sent++;
            });

        return $sent;
    }
}
