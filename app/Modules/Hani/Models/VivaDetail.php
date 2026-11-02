<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VivaDetail extends Model
{
    protected $fillable = [
        'application_id', 'programme_level', 'thesis_title', 'expected_softbound_date',
        'viva_at', 'venue', 'chair_name', 'chair_email',
        'co_supervisor_name', 'co_supervisor_email', 'scheduled_by_id', 'schedule_sequence',
    ];

    protected function casts(): array
    {
        return [
            'expected_softbound_date' => 'date',
            'viva_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function scheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by_id');
    }

    public function isScheduled(): bool
    {
        return $this->viva_at !== null;
    }
}
