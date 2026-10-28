<?php

namespace App\Modules\Nureen\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GaCertificationDetail extends Model
{
    protected $fillable = [
        'application_id', 'appointment_type', 'period_start', 'period_end', 'purpose',
        'reference_number', 'revoked_at', 'revoked_reason',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'revoked_at' => 'datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
