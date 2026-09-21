<?php

namespace App\Modules\Norhanis\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A student's RPD candidacy -- the "masterlist" entry the scope document
 * refers to. There is no separate masterlist table; this row IS it.
 *
 * Deliberately not an Application: reminders, appeals and dismissals all read
 * or write this record, but the candidacy itself is never submitted through
 * WorkflowEngine -- it is the thing the RPD appeal/dismissal chains act on.
 */
class Candidacy extends Model
{
    public const STUDY_MODE_FULL_TIME = 'full_time';
    public const STUDY_MODE_PART_TIME = 'part_time';

    public const PROGRAMME_MASTERS = 'masters';
    public const PROGRAMME_PHD = 'phd';

    /** Longest extension a student may request on an appeal, agreed with CGS. */
    public const MAX_APPEAL_EXTENSION_MONTHS = 6;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_APPEAL_PENDING = 'appeal_pending';
    public const STATUS_FAILED_AWAITING_RESUBMISSION = 'failed_awaiting_resubmission';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'student_id', 'study_mode', 'programme', 'start_date', 'deadline',
        'resubmission_deadline', 'attempt_number', 'status', 'rpd_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'deadline' => 'date',
            'resubmission_deadline' => 'date',
            'rpd_completed_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function reminderLogs(): HasMany
    {
        return $this->hasMany(RpdReminderLog::class);
    }

    /**
     * Months-before-deadline marks the scope document asks for, in the order
     * checked. Shared by RemindRpdCandidates (the automatic 07:00 scan) and
     * UpcomingRpdRemindersController (the CGS visibility page + manual "Send
     * Now" fallback), so the two can never disagree about which mark a
     * candidacy is in.
     */
    public const REMINDER_MONTH_MARKS = [3, 2, 1];

    /**
     * The moment a given month-mark's reminder window opens for this
     * candidacy -- e.g. a 31 Mar deadline's 3-month mark opens on 31 Dec, not
     * "90 days before", which drifts depending on which months fall in
     * between. A mark is due once now() reaches this instant.
     */
    public function reminderWindowOpensAt(int $monthMark): Carbon
    {
        return $this->deadline->copy()->subMonths($monthMark);
    }

    /**
     * 8 months full-time, 12 months part-time -- Masters and PhD are the
     * same duration, only study mode changes it. This is the *first*
     * deadline only; a failed attempt's resubmission window is a different
     * table, see resubmissionMonthsFor().
     */
    public static function deadlineMonthsFor(string $studyMode): int
    {
        return $studyMode === self::STUDY_MODE_PART_TIME ? 12 : 8;
    }

    public static function computeDeadline(Carbon $startDate, string $studyMode): Carbon
    {
        return $startDate->copy()->addMonths(self::deadlineMonthsFor($studyMode));
    }

    /**
     * Resubmission window after a failed RPD attempt -- Table 5 of the FYP I
     * interim report. Unlike the first deadline, this genuinely differs by
     * programme at the same study mode: Masters Full-Time resubmits in 3
     * months, PhD Full-Time in 6; Masters Part-Time in 6, PhD Part-Time in 12.
     */
    public static function resubmissionMonthsFor(string $programme, string $studyMode): int
    {
        return match (true) {
            $programme === self::PROGRAMME_PHD && $studyMode === self::STUDY_MODE_PART_TIME => 12,
            $programme === self::PROGRAMME_PHD => 6,
            $studyMode === self::STUDY_MODE_PART_TIME => 6,
            default => 3, // Masters, Full-Time
        };
    }

    public static function computeResubmissionDeadline(Carbon $from, string $programme, string $studyMode): Carbon
    {
        return $from->copy()->addMonths(self::resubmissionMonthsFor($programme, $studyMode));
    }

    /**
     * Candidacies eligible for Non-Exec CGS to initiate a dismissal for: the
     * RPD milestone was never recorded as done, and either the original
     * deadline passed while still on the first attempt, or the resubmission
     * deadline passed after a failed attempt. Not mid-appeal, not already
     * dismissed or completed either way.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNull('rpd_completed_at')
            ->where(function (Builder $query) {
                $query->where(function (Builder $query) {
                    $query->where('status', self::STATUS_ACTIVE)
                        ->whereDate('deadline', '<', now());
                })->orWhere(function (Builder $query) {
                    $query->where('status', self::STATUS_FAILED_AWAITING_RESUBMISSION)
                        ->whereDate('resubmission_deadline', '<', now());
                });
            });
    }

    /**
     * Same rule as scopeOverdue(), for a single already-loaded model. Compares
     * by calendar day, not by isPast() -- a deadline of today is not yet
     * overdue, and this must agree with the SQL scope used for the CGS
     * dismissal-initiation list or a candidacy could appear eligible in one
     * place and not the other.
     */
    public function isOverdue(): bool
    {
        if ($this->rpd_completed_at !== null) {
            return false;
        }

        return match ($this->status) {
            self::STATUS_ACTIVE => $this->deadline->lt(now()->startOfDay()),
            self::STATUS_FAILED_AWAITING_RESUBMISSION => $this->resubmission_deadline?->lt(now()->startOfDay()) ?? false,
            default => false,
        };
    }
}
