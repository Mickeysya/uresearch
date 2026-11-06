<?php

namespace App\Modules\Haziq\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageGateTerminationTrigger extends Model
{
    protected $fillable = [
        'application_id', 'student_id', 'stage_gate_id', 'reason', 'triggered_at',
        'acknowledged_by', 'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'triggered_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function stageGate(): BelongsTo
    {
        return $this->belongsTo(StageGate::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
