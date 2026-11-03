<?php

namespace App\Modules\Chloe\Services;

use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationHistory;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationPendingSelection;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Models\WorkstationTerminationNotice;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The seat catalogue's two rules that must hold whoever calls them (a controller, a test, a command):
 *
 *  - Numbering: one persistent counter (workstation_seat_counter, row id 1) hands out seat numbers. It is only ever
 *    incremented, inside the same transaction that creates the seats, so a number is never reused or backfilled, deleting
 *    Seat 200 or the newest seat changes nothing, and numbers run on past 999. seat_code stays an unpadded string; every
 *    sort in this module casts it to a number.
 *  - Retiring: a seat that is occupied, awaiting key return, pending or held by an active/pending assignment cannot be
 *    deleted. A free or maintenance seat is soft deleted (history keeps pointing at it).
 */
class WorkstationSeatCatalogue
{
    /** Rows per INSERT. n is not capped; chunking only keeps each statement small. */
    public const CHUNK = 500;

    public const IN_USE_MESSAGE = 'This seat is in use. Ask CGS to contact the student and reassign the seat first.';

    public const NOT_FREE_MESSAGE = 'This seat is reserved. Make it Available or Under Maintenance before deleting it.';

    /** @return array{first: int, last: int, count: int} the numbers just created, e.g. 316 to 315+n */
    public function addSeats(WorkstationLocation $location, int $count): array
    {
        if ($count < 1) {
            throw new RuntimeException('The number of seats must be at least 1.');
        }

        return DB::transaction(function () use ($location, $count) {
            $counter = DB::table('workstation_seat_counter')->where('id', 1)->lockForUpdate()->first();
            $first = ((int) $counter->last_number) + 1;
            $last = $first + $count - 1;

            // Incremented, never decremented, and written before the seats so a failed insert rolls both back together.
            DB::table('workstation_seat_counter')->where('id', 1)->update(['last_number' => $last, 'updated_at' => now()]);

            $now = now();

            for ($from = $first; $from <= $last; $from += self::CHUNK) {
                $to = min($from + self::CHUNK - 1, $last);

                DB::table('workstations')->insert(array_map(fn (int $n) => [
                    'workstation_location_id' => $location->id,
                    'seat_code' => (string) $n,
                    'status' => Workstation::STATUS_AVAILABLE,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], range($from, $to)));
            }

            return ['first' => $first, 'last' => $last, 'count' => $count];
        });
    }

    public function nextNumber(): int
    {
        return ((int) DB::table('workstation_seat_counter')->where('id', 1)->value('last_number')) + 1;
    }

    /** null = the seat may be deleted; otherwise the message to show CGS. */
    public function blockReason(Workstation $seat): ?string
    {
        if (in_array($seat->status, [Workstation::STATUS_OCCUPIED, Workstation::STATUS_AWAITING_KEY_RETURN, Workstation::STATUS_PENDING], true)) {
            return self::IN_USE_MESSAGE;
        }

        $held = WorkstationRequest::where('workstation_id', $seat->id)->where('status', WorkstationRequest::STATUS_CONFIRMED)->exists()
            || WorkstationPendingSelection::where('workstation_id', $seat->id)->where('status', WorkstationPendingSelection::STATUS_PENDING)->exists()
            || WorkstationTerminationNotice::whereHas('workstationRequest', fn ($q) => $q->where('workstation_id', $seat->id))->where('status', WorkstationTerminationNotice::STATUS_ACTIVE)->exists();

        if ($held) {
            return self::IN_USE_MESSAGE;
        }

        if ($seat->status === Workstation::STATUS_RESERVED) {
            return self::NOT_FREE_MESSAGE;
        }

        return null;
    }

    /** Soft deletes the seat if the rules allow it. @return ?string the block message, or null when it was retired */
    public function retire(Workstation $seat, ?User $actor): ?string
    {
        return DB::transaction(function () use ($seat, $actor) {
            $locked = Workstation::whereKey($seat->id)->lockForUpdate()->first();

            if (! $locked) {
                return null;
            }

            if ($message = $this->blockReason($locked)) {
                return $message;
            }

            WorkstationHistory::record(
                seat: $locked,
                actorType: WorkstationHistory::ACTOR_CGS,
                actorName: $actor?->name ?? 'CGS',
                action: WorkstationHistory::ACTION_SEAT_RETIRED,
                beforeState: $locked->status,
                afterState: 'retired',
            );

            $locked->delete();

            return null;
        });
    }
}
