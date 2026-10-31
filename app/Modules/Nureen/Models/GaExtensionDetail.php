<?php

namespace App\Modules\Nureen\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GaExtensionDetail extends Model
{
    protected $fillable = [
        'application_id', 'supervisor_id', 'current_end_date', 'requested_new_end_date', 'reason_for_extension',
        'additional_info', 'final_approver_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'current_end_date' => 'date',
            'requested_new_end_date' => 'date',
            // Provisional -- see config('nureen.ga_extension.additional_fields').
            'additional_info' => 'array',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** The supervisor this application's 'supervisor' stage is addressed to. */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }
}
