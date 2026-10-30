<?php

namespace App\Modules\Nureen\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupervisionDetail extends Model
{
    public const ROLE_MAIN = 'main_supervisor';

    public const ROLE_CO = 'co_supervisor';

    protected $fillable = [
        'application_id', 'requested_supervisor_id', 'justification',
        'reminded_at', 'reminder_count', 'reminded_stage_key',
        'appointment_role', 'appointment_type', 'cgs_stage_snapshot',
        'previous_supervisor_id', 'letter_reference', 'letter_issued_at',
        'letter_revoked_at', 'letter_revoked_reason', 'letter_revoke_note',
    ];

    protected function casts(): array
    {
        return [
            'reminded_at' => 'datetime',
            'letter_issued_at' => 'datetime',
            'letter_revoked_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function requestedSupervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_supervisor_id');
    }

    public function previousSupervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'previous_supervisor_id');
    }

    public function isRevoked(): bool
    {
        return $this->letter_revoked_at !== null;
    }

    /**
     * True when $supervisorId already holds a currently-approved,
     * unrevoked Co-Supervisor appointment for $studentId -- the case
     * SupervisionController::store() refuses a second request for, per the
     * rule that a co-supervisor appointment should not be requested twice
     * for the same pair.
     */
    public static function hasActiveCoSupervisor(int $studentId, int $supervisorId): bool
    {
        return static::query()
            ->where('requested_supervisor_id', $supervisorId)
            ->where('appointment_role', self::ROLE_CO)
            ->whereNull('letter_revoked_at')
            ->whereHas('application', fn ($q) => $q
                ->where('student_id', $studentId)
                ->where('status', Application::STATUS_APPROVED))
            ->exists();
    }
}
