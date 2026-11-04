<?php

namespace App\Modules\Haziq\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StageGate extends Model
{
    protected $fillable = [
        'application_id', 'rule_id', 'stage_order', 'stage_name', 'requirement_description',
        'deadline_date', 'grace_end_date', 'completion_status', 'completion_date', 'completed_by',
        'timeline_status', 'allowance_status', 'notes', 'last_reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'deadline_date' => 'date',
            'grace_end_date' => 'date',
            'completion_date' => 'date',
            'last_reminder_sent_at' => 'date',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(StageGateRule::class, 'rule_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }

    public function isRecoverable(): bool
    {
        return (bool) ($this->rule?->recoverable ?? $this->stage_order === 1);
    }

    public function isOpen(): bool
    {
        return $this->completion_status !== 'completed';
    }
}
