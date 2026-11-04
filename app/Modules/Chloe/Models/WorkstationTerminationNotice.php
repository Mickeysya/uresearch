<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PART 3: one notice (Term of Use clause 9/16), against one
 * WorkstationRequest. Only WorkstationAllocator::startTermination() /
 * withdrawTermination() / the daily job's auto-release write these rows --
 * see those for the actual lifecycle.
 */
class WorkstationTerminationNotice extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_RELEASED = 'released';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'workstation_request_id', 'reason', 'notice_date', 'vacate_by_date',
        'status', 'withdrawn_reason', 'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'notice_date' => 'date',
            'vacate_by_date' => 'date',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function workstationRequest(): BelongsTo
    {
        return $this->belongsTo(WorkstationRequest::class);
    }
}
