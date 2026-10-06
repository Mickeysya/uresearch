<?php

namespace App\Modules\Chloe\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deliberately its own record rather than a column on WorkstationRequest — a
 * student can hold a workstation and never touch this at all, and the two
 * are tracked (and reminded about) on separate clocks.
 */
class LockerKey extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_COLLECTED = 'collected';

    public const STATUS_RETURNED = 'returned';

    /** Days uncollected before Console\Commands\RemindOverdueLockerKeys nags the student. */
    public const GRACE_DAYS = 3;

    protected $fillable = [
        'workstation_request_id', 'student_id', 'status', 'requested_at',
        'collected_at', 'returned_at', 'reminder_sent_at', 'reminder_count',
        'is_override', 'overridden_by_id', 'override_reason',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'collected_at' => 'datetime',
            'returned_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'is_override' => 'boolean',
        ];
    }

    public function workstationRequest(): BelongsTo
    {
        return $this->belongsTo(WorkstationRequest::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_REQUESTED
            && $this->requested_at->lt(now()->subDays(self::GRACE_DAYS));
    }
}
