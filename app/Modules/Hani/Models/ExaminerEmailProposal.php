<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An email proposed at nomination, waiting for CGS. See the migration. */
class ExaminerEmailProposal extends Model
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const DISMISSED = 'dismissed';
    public const SUPERSEDED = 'superseded';

    protected $fillable = [
        'examiner_id', 'nomination_id', 'proposed_email', 'proposed_by_id', 'cv_path', 'cv_original_name',
        'status', 'decided_by_id', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(Examiner::class);
    }

    public function nomination(): BelongsTo
    {
        return $this->belongsTo(ExaminerNomination::class);
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }
}
