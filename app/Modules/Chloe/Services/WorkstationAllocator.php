<?php

namespace App\Modules\Chloe\Services;

use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Notifications\WorkstationAllocated;
use App\Modules\Chloe\Notifications\WorkstationRequestRejected;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only code that changes a Workstation's status and creates a
 * WorkstationRequest together — the same job WorkflowEngine does for
 * applications::status, except this module has no approval chain to route
 * through, so it is its own small engine rather than a stage on that one.
 *
 * The race this exists to close: two students submit for the same seat at
 * nearly the same instant. `lockForUpdate()` inside a transaction serialises
 * the two requests — whichever commits first wins the seat, and the second
 * re-reads a row that already says "occupied" and is rejected. Without the
 * lock, both requests could read "available" before either writes, and both
 * would succeed.
 */
class WorkstationAllocator
{
    /** @return array{request: WorkstationRequest, allocated: bool} */
    public function request(User $student, Workstation $seat): array
    {
        $result = DB::transaction(function () use ($student, $seat) {
            $locked = Workstation::whereKey($seat->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== Workstation::STATUS_AVAILABLE) {
                $rejected = WorkstationRequest::create([
                    'workstation_id' => $seat->id,
                    'student_id' => $student->id,
                    'status' => WorkstationRequest::STATUS_REJECTED,
                    'requested_at' => now(),
                ]);

                return ['request' => $rejected, 'allocated' => false];
            }

            $locked->update(['status' => Workstation::STATUS_OCCUPIED]);

            $confirmed = WorkstationRequest::create([
                'workstation_id' => $seat->id,
                'student_id' => $student->id,
                'status' => WorkstationRequest::STATUS_CONFIRMED,
                'requested_at' => now(),
            ]);

            return ['request' => $confirmed, 'allocated' => true];
        });

        if ($result['allocated']) {
            $student->notify(new WorkstationAllocated($result['request']));
        } else {
            $student->notify(new WorkstationRequestRejected($seat));
        }

        return $result;
    }

    public function release(WorkstationRequest $request, ?User $overriddenBy = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($request, $overriddenBy, $reason) {
            $request->update([
                'status' => WorkstationRequest::STATUS_RELEASED,
                'released_at' => now(),
                'is_override' => (bool) $overriddenBy,
                'overridden_by_id' => $overriddenBy?->id,
                'override_reason' => $reason,
            ]);

            Workstation::whereKey($request->workstation_id)
                ->lockForUpdate()
                ->update(['status' => Workstation::STATUS_AVAILABLE]);
        });

        if ($overriddenBy) {
            activity('workstation')
                ->causedBy($overriddenBy)
                ->performedOn($request)
                ->withProperties(['reason' => $reason])
                ->log('Force-released workstation #'.$request->workstation_id.' (CGS override)');
        }
    }

    /**
     * CGS's release override — unlike release() above, this takes the seat
     * rather than a WorkstationRequest, because a seat can be occupied
     * without one: the catalogue was seeded from CGS's existing seat map,
     * and every seat it showed already taken came in pre-occupied with no
     * app history to attach. Any confirmed request that does exist is still
     * marked released so the student's own record stays correct.
     */
    public function forceReleaseSeat(Workstation $seat, User $admin, string $reason): void
    {
        DB::transaction(function () use ($seat, $admin, $reason) {
            $locked = Workstation::whereKey($seat->id)->lockForUpdate()->first();

            WorkstationRequest::where('workstation_id', $seat->id)
                ->where('status', WorkstationRequest::STATUS_CONFIRMED)
                ->update([
                    'status' => WorkstationRequest::STATUS_RELEASED,
                    'released_at' => now(),
                    'is_override' => true,
                    'overridden_by_id' => $admin->id,
                    'override_reason' => $reason,
                ]);

            $locked->update(['status' => Workstation::STATUS_AVAILABLE]);
        });

        activity('workstation')
            ->causedBy($admin)
            ->performedOn($seat)
            ->withProperties(['reason' => $reason])
            ->log('Force-released workstation #'.$seat->id.' (CGS override)');
    }

    /**
     * CGS's manual-assignment fallback for exceptional cases. Only ever
     * offered on a seat that is not currently occupied — reassigning an
     * occupied seat means force-releasing it first, so the student losing it
     * is never silently dropped without a record.
     */
    public function forceAssign(User $student, Workstation $seat, User $admin, ?string $reason = null): WorkstationRequest
    {
        return DB::transaction(function () use ($student, $seat, $admin, $reason) {
            $locked = Workstation::whereKey($seat->id)->lockForUpdate()->first();

            if (! $locked || $locked->status === Workstation::STATUS_OCCUPIED) {
                throw new RuntimeException('That seat is occupied — force-release it before reassigning.');
            }

            $locked->update(['status' => Workstation::STATUS_OCCUPIED]);

            $request = WorkstationRequest::create([
                'workstation_id' => $seat->id,
                'student_id' => $student->id,
                'status' => WorkstationRequest::STATUS_CONFIRMED,
                'requested_at' => now(),
                'is_override' => true,
                'overridden_by_id' => $admin->id,
                'override_reason' => $reason,
            ]);

            activity('workstation')
                ->causedBy($admin)
                ->performedOn($request)
                ->withProperties(['reason' => $reason])
                ->log('Force-assigned workstation #'.$seat->id.' to '.$student->name.' (CGS override)');

            return $request;
        });
    }
}
