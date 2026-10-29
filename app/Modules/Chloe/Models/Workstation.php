<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Workstation extends Model
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_PENDING = 'pending';

    public const STATUS_OCCUPIED = 'occupied';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_DISABLED = 'disabled';

    // The student has left, but a Collected key from that seat has not
    // come back yet -- the seat must not be offered to anyone else until
    // it does (markReturned()) or CGS writes it off (releaseHold()). See
    // WorkstationAllocator::handleKeyOnRelease()'s caller in releaseSeat()/
    // reassignSeat() for where this is decided.
    public const STATUS_AWAITING_KEY_RETURN = 'awaiting_key_return';

    protected $fillable = ['workstation_location_id', 'seat_code', 'status', 'cluster', 'position', 'notes'];

    public function location(): BelongsTo
    {
        return $this->belongsTo(WorkstationLocation::class, 'workstation_location_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(WorkstationRequest::class);
    }

    /**
     * The confirmed request currently holding this seat, if any. Seeded
     * occupancy (matching CGS's pre-existing seat map) has no such row — a
     * seat can be `occupied` with a known occupant or without one, and every
     * screen that shows "who" has to handle both.
     *
     * Final polish PART 4.6 (found live): a plain `->where('status', ...)
     * ->latestOfMany('requested_at')` chain does NOT apply the status
     * filter inside the one-of-many subquery's own aggregation -- it only
     * applies it as an outer filter on whichever row that subquery already
     * picked as "latest by requested_at" ACROSS EVERY STATUS. A seat with
     * an old confirmed request plus a newer released/cancelled one then
     * resolves to null (the newer, non-confirmed row loses the outer
     * filter, and nothing replaces it), even though a confirmed occupant
     * genuinely exists -- reproduced live on seat 1 (workstation 17),
     * which showed "No student record" on an Occupied seat. The
     * documented fix for a constrained one-of-many is the callback form:
     * the status filter inside the closure becomes part of the
     * aggregation subquery itself, so "latest" is computed only among
     * confirmed rows in the first place.
     */
    public function activeRequest(): HasOne
    {
        return $this->hasOne(WorkstationRequest::class)
            ->ofMany(['requested_at' => 'max'], function ($query) {
                $query->where('status', WorkstationRequest::STATUS_CONFIRMED);
            });
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    /**
     * Every LockerKey this seat has ever had, across every WorkstationRequest
     * that ever held it -- not just the current occupant's. The CGS seat
     * card/details page needs this to show "Key Return Pending/Overdue" for
     * a now-Available seat whose former occupant hasn't returned their key
     * yet (see docs for STEP 4's "last key" rule); a bounded collection per
     * seat, so picking the latest in PHP (sortByDesc) is simpler and just as
     * correct as a per-group SQL subquery for a catalogue this size.
     */
    public function lockerKeysHistory(): HasManyThrough
    {
        return $this->hasManyThrough(
            LockerKey::class,
            WorkstationRequest::class,
            'workstation_id',
            'workstation_request_id',
        );
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_AVAILABLE => 'Available',
            // A student has clicked Select and has 5 minutes to confirm by
            // email before this reverts to Available on its own. See
            // WorkstationAllocator::beginPendingSelection().
            self::STATUS_PENDING => 'Pending Confirmation',
            self::STATUS_OCCUPIED => 'Occupied',
            self::STATUS_RESERVED => 'Reserved',
            self::STATUS_AWAITING_KEY_RETURN => 'Awaiting Key Return',
            // The seat map reference's third seat colour (yellow) is
            // "MAINTAINANCE" — the same real-world case this status already
            // covered, just relabelled to match CGS's own term for it.
            self::STATUS_DISABLED => 'Under Maintenance',
        ];
    }
}
