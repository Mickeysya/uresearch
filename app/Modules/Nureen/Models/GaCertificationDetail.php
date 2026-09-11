<?php

namespace App\Modules\Nureen\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GaCertificationDetail extends Model
{
    protected $fillable = [
        'application_id', 'appointment_type', 'period_start', 'period_end', 'purpose',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
