<?php

namespace App\Modules\Nureen\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GaExtensionDetail extends Model
{
    protected $fillable = [
        'application_id', 'current_end_date', 'requested_new_end_date', 'reason_for_extension',
    ];

    protected function casts(): array
    {
        return [
            'current_end_date' => 'date',
            'requested_new_end_date' => 'date',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
