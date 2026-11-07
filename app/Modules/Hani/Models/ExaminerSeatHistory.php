<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One change to one panel seat. See the migration. */
class ExaminerSeatHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'examiner_seat_history';

    protected $fillable = ['nomination_id', 'seat', 'examiner_id', 'event', 'reason', 'actor_id'];

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(Examiner::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function seatLabel(): string
    {
        return ExaminerNomination::SLOTS[$this->seat] ?? $this->seat;
    }

    public function eventLabel(): string
    {
        return match ($this->event) {
            'nominated' => 'Nominated',
            'released' => 'Released and tied up',
            'standby' => 'On standby (not tied up)',
            'declined' => 'Declined',
            'promoted' => 'Promoted from backup',
            'vacant' => 'Seat left empty',
            'replaced' => 'Replacement appointed',
            'examined' => 'Examined; gap started',
            'freed' => 'Freed without a gap',
            'sub_requested' => 'Substitution requested',
            'substituted' => 'Substituted by the department',
            'merged' => 'Record merged',
            default => ucfirst($this->event),
        };
    }
}
