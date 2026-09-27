<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Workstation extends Model
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_OCCUPIED = 'occupied';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_DISABLED = 'disabled';

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
     */
    public function activeRequest(): HasOne
    {
        return $this->hasOne(WorkstationRequest::class)
            ->where('status', WorkstationRequest::STATUS_CONFIRMED)
            ->latestOfMany('requested_at');
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_AVAILABLE => 'Available',
            self::STATUS_OCCUPIED => 'Occupied',
            self::STATUS_RESERVED => 'Reserved',
            // The seat map reference's third seat colour (yellow) is
            // "MAINTAINANCE" — the same real-world case this status already
            // covered, just relabelled to match CGS's own term for it.
            self::STATUS_DISABLED => 'Under Maintenance',
        ];
    }
}
