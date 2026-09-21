<?php

namespace App\Modules\Chloe\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One student's claim on one seat. Not an Application row — this module has
 * no approval chain, so nothing here ever touches WorkflowEngine. See
 * Services\WorkstationAllocator, which is the only code that changes these
 * rows and the parent Workstation's status together.
 */
class WorkstationRequest extends Model
{
    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'workstation_id', 'student_id', 'status', 'requested_at', 'released_at',
        'is_override', 'overridden_by_id', 'override_reason',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'released_at' => 'datetime',
            'is_override' => 'boolean',
        ];
    }

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by_id');
    }

    public function lockerKey(): HasOne
    {
        return $this->hasOne(LockerKey::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }
}
