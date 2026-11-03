<?php

namespace App\Modules\Norhanis\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reminder sent about a stalled application. See RemindStalledApplications. */
class StageReminder extends Model
{
    protected $table = 'norhanis_stage_reminders';

    protected $fillable = ['application_id', 'stage_key', 'sent_at'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
