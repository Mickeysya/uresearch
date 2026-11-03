<?php

namespace App\Modules\Nureen\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The latest risk check for one student, projection included. CGS-facing
 * only -- see Support\AttendanceEarlyWarning and AttendanceController::atRisk().
 */
class AttendanceRiskState extends Model
{
    public const REASON_CURRENT = 'current';

    public const REASON_PROJECTED = 'projected';

    public const REASON_BOTH = 'both';

    protected $fillable = [
        'student_id', 'at_risk', 'reason', 'current_percentage', 'projected_percentage',
        'trend_slope', 'periods_used', 'projection_status', 'latest_period_end', 'evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'at_risk' => 'boolean',
            'current_percentage' => 'float',
            'projected_percentage' => 'float',
            'trend_slope' => 'float',
            'latest_period_end' => 'date',
            'evaluated_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(AttendanceAlert::class, 'student_id', 'student_id');
    }
}
