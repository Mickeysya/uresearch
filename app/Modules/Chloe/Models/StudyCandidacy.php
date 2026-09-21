<?php

namespace App\Modules\Chloe\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudyCandidacy extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SOFTBOUND = 'softbound';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_COMPLETED = 'completed';

    /** Business rule: no appeal may push cumulative extension past this. */
    public const MAX_APPEAL_MONTHS = 12;

    protected $fillable = [
        'student_id', 'programme_start_date', 'candidacy_expiry_date', 'status',
        'cumulative_extension_months', 'last_rejection_at', 'dismissed_at', 'dismissed_by_id',
    ];

    protected function casts(): array
    {
        return [
            'programme_start_date' => 'date',
            'candidacy_expiry_date' => 'date',
            'last_rejection_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by_id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(StudyCandidacyReminder::class);
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(CandidacyAppealDetail::class);
    }

    public function remainingAppealMonths(): int
    {
        return max(0, self::MAX_APPEAL_MONTHS - $this->cumulative_extension_months);
    }

    /**
     * Both conditions from the brief: the cap isn't reached, and no appeal
     * has ever been rejected -- at any stage, not only by the Dean. Either
     * one permanently blocks a new appeal. A Return is deliberately not
     * this: it only reopens that same application for editing, it never
     * touches last_rejection_at. See CandidacyAppealController::decide().
     */
    public function canAppeal(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->remainingAppealMonths() > 0
            && $this->last_rejection_at === null;
    }

    /**
     * The three monthly reminder dates, counting back from the expiry date
     * with calendar-month arithmetic (not a fixed day count) so a
     * short month clamps correctly -- e.g. a 31 Dec due date gives
     * 30 Sep (3 months before), 31 Oct (2 months before), 30 Nov (1 month
     * before), matching subMonthsNoOverflow's clamp-to-last-day behaviour
     * rather than overflowing into the next month.
     *
     * @return array<int, \Illuminate\Support\Carbon>
     */
    public function reminderDates(): array
    {
        return [
            1 => $this->candidacy_expiry_date->copy()->subMonthsNoOverflow(3)->startOfDay(),
            2 => $this->candidacy_expiry_date->copy()->subMonthsNoOverflow(2)->startOfDay(),
            3 => $this->candidacy_expiry_date->copy()->subMonthsNoOverflow(1)->startOfDay(),
        ];
    }

    public function daysUntilExpiry(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->candidacy_expiry_date->startOfDay(), false);
    }

    public function isNearExpiry(int $withinMonths = 3): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && now()->diffInDays($this->candidacy_expiry_date, false) <= $withinMonths * 30;
    }

    public function hasOpenAppeal(): bool
    {
        return $this->appeals()
            ->whereHas('application', fn ($q) => $q->whereIn('status', [
                \App\Modules\Core\Models\Application::STATUS_PENDING,
                \App\Modules\Core\Models\Application::STATUS_RETURNED,
            ]))
            ->exists();
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_SOFTBOUND => 'Softbound',
            self::STATUS_DISMISSED => 'Dismissed',
            self::STATUS_INACTIVE => 'Inactive',
            self::STATUS_COMPLETED => 'Completed',
        ];
    }
}
