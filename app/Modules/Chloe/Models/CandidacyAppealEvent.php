<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * APPEND-ONLY. The history of an appeal is never edited or removed: update(), delete() and saving an existing row all
 * throw. (Rows only ever disappear through the database's cascade when a whole appeal is deleted, which no code path
 * does.) Write one with CandidacyAppealEvent::record(...).
 */
class CandidacyAppealEvent extends Model
{
    public $timestamps = false;

    protected $table = 'candidacy_appeal_events';

    protected $fillable = [
        'candidacy_appeal_id', 'staff_profile_id', 'occurred_at', 'actor_kind', 'actor_user_id', 'actor_name', 'actor_role',
        'action', 'stage', 'round', 'reason_codes', 'remark', 'from_status', 'to_status',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'reason_codes' => 'array'];
    }

    public function appeal(): BelongsTo
    {
        return $this->belongsTo(CandidacyAppeal::class, 'candidacy_appeal_id');
    }

    /** @param array<string, mixed> $attributes */
    public static function record(array $attributes): self
    {
        $event = new self;
        $event->forceFill($attributes + ['occurred_at' => now()]);
        $event->save();

        return $event;
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('Appeal events are append-only: an existing event cannot be saved again.');
        }

        return parent::save($options);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('Appeal events are append-only.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('Appeal events are append-only.');
    }
}
