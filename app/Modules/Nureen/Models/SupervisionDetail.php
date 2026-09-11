<?php

namespace App\Modules\Nureen\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupervisionDetail extends Model
{
    protected $fillable = [
        'application_id', 'requested_supervisor_id', 'justification', 'reminded_at',
    ];

    protected function casts(): array
    {
        return ['reminded_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function requestedSupervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_supervisor_id');
    }
}
