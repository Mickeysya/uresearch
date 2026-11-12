<?php

namespace App\Modules\Norhanis\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One row of a student's Daily Log TA Hours.
 *
 * The student adds rows (draft), submits them (pending), and the course
 * lecturer each row names approves or rejects it. Only approved hours count:
 * toward the semester's 84 on the Travel form and the dashboard's monthly
 * figure. `state` is this table's own column, not an application status.
 */
class TaHourLog extends Model
{
    public const DRAFT = 'draft';
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $table = 'ta_hour_logs';

    protected $fillable = [
        'student_id', 'application_id', 'activity_type', 'description', 'activity_date',
        'time_in', 'time_out', 'hours', 'course_lecturer_id', 'state',
        'submitted_at', 'decided_at', 'decision_reason',
        'recorded_by', 'approver_role', 'notes',
    ];

    protected $attributes = [
        'state' => self::DRAFT,
    ];

    protected function casts(): array
    {
        return [
            'activity_date' => 'date',
            'hours' => 'decimal:2',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function courseLecturer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'course_lecturer_id');
    }

    /** The lecturer's staff code, for "Name (CODE)". */
    public function lecturerCode(): HasOne
    {
        return $this->hasOne(TaCourseLecturer::class, 'user_id', 'course_lecturer_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('state', self::APPROVED);
    }

    /** Rows still in play: everything but a rejection. What the monthly cap counts. */
    public function scopeCounting(Builder $query): Builder
    {
        return $query->where('state', '!=', self::REJECTED);
    }

    public function scopeInMonth(Builder $query, int $year, int $month): Builder
    {
        return $query->whereYear('activity_date', $year)->whereMonth('activity_date', $month);
    }

    /** The UCampus label, or the free text older staff-entered rows hold. */
    public function activityLabel(): string
    {
        return config('norhanis.ta.activities.'.$this->activity_type) ?? $this->activity_type;
    }

    public function lecturerDisplay(): string
    {
        if (! $this->courseLecturer) {
            return '—';
        }

        return $this->lecturerCode
            ? "{$this->courseLecturer->name} ({$this->lecturerCode->staff_code})"
            : $this->courseLecturer->name;
    }

    public function stateLabel(): string
    {
        return match ($this->state) {
            self::DRAFT => 'Not submitted',
            self::PENDING => 'Pending lecturer approval',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            default => ucfirst((string) $this->state),
        };
    }

    /** Hours between two HH:MM times on the same day, to 2 dp. */
    public static function hoursBetweenTimes(string $in, string $out): float
    {
        [$ih, $im] = array_map('intval', explode(':', $in));
        [$oh, $om] = array_map('intval', explode(':', $out));

        return round((($oh * 60 + $om) - ($ih * 60 + $im)) / 60, 2);
    }
}
