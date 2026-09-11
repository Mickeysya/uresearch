<?php

namespace App\Modules\Hani\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * The examiner pool and its four-state machine.
 *
 * The legacy schema had no pool at all -- examiner_nominations was a flat
 * table that captured last_examination_date and then never read it, so the
 * mandatory cooling-off period was never actually enforced. State is derived
 * here rather than stored, so it cannot go stale: an examiner on gap becomes
 * available the moment the 90 days elapse, with no job needing to run.
 */
class Examiner extends Model
{
    /** Mandatory cooling-off after an evaluation, in days. */
    public const GAP_DAYS = 90;

    /**
     * How far out assigned_until is set on approval, absent any other signal
     * of when the case will actually conclude. Marking the evaluation
     * complete (ExaminerNominationController::markComplete()) clears this
     * immediately and starts the gap from that real date instead, so this
     * window only matters if completion is never recorded.
     */
    public const DEFAULT_ASSIGNMENT_DAYS = 180;

    public const STATE_ASSIGNED = 'assigned';
    public const STATE_ON_GAP = 'on_gap';
    public const STATE_AVAILABLE = 'available';
    public const STATE_UNAVAILABLE = 'unavailable';

    protected $fillable = [
        'name', 'email', 'department', 'faculty', 'type',
        'is_active', 'last_examination_date', 'assigned_until',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_examination_date' => 'date',
            'assigned_until' => 'date',
        ];
    }

    /** When this examiner comes off gap, or null if they are not on one. */
    public function gapEndsOn(): ?CarbonInterface
    {
        return $this->last_examination_date?->copy()->addDays(self::GAP_DAYS);
    }

    public function state(): string
    {
        if (! $this->is_active) {
            return self::STATE_UNAVAILABLE;
        }

        if ($this->assigned_until && $this->assigned_until->isFuture()) {
            return self::STATE_ASSIGNED;
        }

        if ($this->gapEndsOn()?->isFuture()) {
            return self::STATE_ON_GAP;
        }

        return self::STATE_AVAILABLE;
    }

    public function isEligible(): bool
    {
        return $this->state() === self::STATE_AVAILABLE;
    }

    /** Why this examiner cannot be nominated right now, or null if they can. */
    public function ineligibilityReason(): ?string
    {
        return match ($this->state()) {
            self::STATE_UNAVAILABLE => 'Marked unavailable (retired, resigned or otherwise inactive).',
            self::STATE_ASSIGNED => 'Already assigned to an active case until '
                .$this->assigned_until->format('j M Y').'.',
            self::STATE_ON_GAP => 'Serving the '.self::GAP_DAYS.'-day cooling-off period; '
                .'eligible again on '.$this->gapEndsOn()->format('j M Y').'.',
            default => null,
        };
    }

    public function scopeEligible(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('assigned_until')->orWhere('assigned_until', '<=', now()))
            ->where(fn ($q) => $q->whereNull('last_examination_date')
                ->orWhere('last_examination_date', '<=', now()->subDays(self::GAP_DAYS)));
    }
}
