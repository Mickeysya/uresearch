<?php

namespace App\Modules\Chloe\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidacyDismissal extends Model
{
    public const REASON_EXPIRED_NO_APPEAL = 'expired_no_appeal';

    public const REASON_APPEAL_REJECTED = 'appeal_rejected';

    public const REASON_EXTENSION_EXHAUSTED = 'extension_exhausted';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_CONFIRMED = 'confirmed';

    protected $fillable = [
        'study_candidacy_id', 'student_id', 'reason', 'generated_at',
        'status', 'confirmed_by_id', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return ['generated_at' => 'datetime', 'confirmed_at' => 'datetime'];
    }

    public function candidacy(): BelongsTo
    {
        return $this->belongsTo(StudyCandidacy::class, 'study_candidacy_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }

    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            self::REASON_EXPIRED_NO_APPEAL => 'Candidacy expired, no appeal filed',
            self::REASON_APPEAL_REJECTED => 'Appeal rejected — no further appeals allowed',
            self::REASON_EXTENSION_EXHAUSTED => '12-month appeal allowance exhausted',
            default => ucfirst(str_replace('_', ' ', $reason)),
        };
    }
}
