<?php

namespace App\Modules\Chloe\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The 5-minute window between a student clicking "Select" and clicking the
 * confirm link in their email. See WorkstationAllocator, the only code that
 * writes to this table (and the matching Workstation status) together.
 */
class WorkstationPendingSelection extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = ['workstation_id', 'student_id', 'token', 'status', 'expires_at', 'confirmed_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
