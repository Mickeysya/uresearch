<?php

namespace App\Modules\Norhanis\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One reminder the scheduler has already sent. See the migration for why it exists. */
class RpdReminderLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['candidacy_id', 'milestone', 'deadline_at_send', 'sent_at'];

    protected function casts(): array
    {
        return [
            'deadline_at_send' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    public function candidacy(): BelongsTo
    {
        return $this->belongsTo(Candidacy::class);
    }
}
