<?php

namespace App\Modules\Norhanis\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (candidacy, month_mark) reminder actually sent. The unique
 * index on that pair (see the migration) is what stops RemindRpdCandidates
 * ever emailing the same 3/2/1-month warning twice, even across runs.
 */
class RpdReminderLog extends Model
{
    protected $fillable = ['candidacy_id', 'month_mark', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function candidacy(): BelongsTo
    {
        return $this->belongsTo(Candidacy::class);
    }
}
