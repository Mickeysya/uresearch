<?php

namespace App\Modules\Nureen\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GaCertificationDetail extends Model
{
    protected $fillable = [
        'application_id', 'supervisor_id', 'appointment_type', 'period_start', 'period_end', 'purpose',
        'course_code', 'course_name', 'course_owner_name',
        'project_title', 'project_leader_name', 'grant_code', 'semester',
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

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    /** The type-specific fields configured for this row's own appointment_type. */
    public function typeFields(): array
    {
        return config("nureen.ga_certification.fields.{$this->appointment_type}", []);
    }
}
