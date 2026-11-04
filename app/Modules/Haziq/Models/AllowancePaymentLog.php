<?php

namespace App\Modules\Haziq\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AllowancePaymentLog extends Model
{
    protected $fillable = [
        'application_id', 'stage_gate_id', 'student_id', 'payment_month', 'amount',
        'pending_addition', 'status', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_month' => 'date',
            'amount' => 'decimal:2',
            'pending_addition' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function stageGate(): BelongsTo
    {
        return $this->belongsTo(StageGate::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
