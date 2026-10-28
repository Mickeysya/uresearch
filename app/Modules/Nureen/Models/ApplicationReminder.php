<?php

namespace App\Modules\Nureen\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stall-reminder bookkeeping shared by GA Extension and Certification, via
 * Support\StalledApplicationReminder. Supervision keeps its own columns on
 * supervision_details -- its "who do I actually remind" rule is already
 * special-cased there, so this table would only duplicate it, not simplify
 * it.
 */
class ApplicationReminder extends Model
{
    protected $fillable = [
        'application_id', 'reminder_count', 'reminded_stage_key', 'last_reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'last_reminded_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
