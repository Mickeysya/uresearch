<?php

namespace App\Modules\Norhanis\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One student's RPD clock.
 *
 * All the deadline arithmetic lives here rather than in the controllers, so
 * the reminder command, the appeal flow and the dismissal flow cannot disagree
 * about when a deadline falls or how far it may move.
 */
class Candidacy extends Model
{
    /** Plural of "candidacy" is not what Laravel guesses ("candidacies" vs "candidacys"). */
    protected $table = 'candidacies';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXTENDED = 'extended';
    public const STATUS_DEFENDED = 'defended';
    public const STATUS_DISMISSED = 'dismissed';

    /**
     * The RPD window, in months, per programme type. Straight from
     * norhanis.md: 8 months full-time, 12 months part-time, Masters or PhD.
     */
    public const WINDOW_MONTHS = [
        'msc_ft' => 8,
        'phd_ft' => 8,
        'msc_pt' => 12,
        'phd_pt' => 12,
    ];

    /**
     * Ceiling on total extension across every appeal a student files.
     *
     * norhanis.md does not name one; chloe.md names twelve months for the
     * parallel study-candidacy appeal and TODO.md flags the two flows as
     * near-identical machinery. Twelve is used here so the two agree by
     * default -- if CGS confirms a different RPD ceiling, this is the constant
     * to change and nothing else moves.
     */
    public const MAX_EXTENSION_MONTHS = 12;

    protected $fillable = [
        'student_id', 'programme_type', 'candidature_start_date',
        'rpd_deadline', 'status', 'extension_months_used', 'defended_on',
    ];

    protected function casts(): array
    {
        return [
            'candidature_start_date' => 'date',
            'rpd_deadline' => 'date',
            'defended_on' => 'date',
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

    /** @return array<string, string> */
    public static function programmeTypes(): array
    {
        return [
            'msc_ft' => 'MSc — Full Time (8 months)',
            'msc_pt' => 'MSc — Part Time (12 months)',
            'phd_ft' => 'PhD — Full Time (8 months)',
            'phd_pt' => 'PhD — Part Time (12 months)',
        ];
    }

    /**
     * The first deadline for a programme, before any extension.
     *
     * Only ever used to seed rpd_deadline at creation. After that the stored
     * date is the truth -- recomputing it would erase every granted extension.
     */
    public static function initialDeadline(string $programmeType, \DateTimeInterface $start): \Carbon\Carbon
    {
        $months = self::WINDOW_MONTHS[$programmeType] ?? 8;

        return \Carbon\Carbon::parse($start)->addMonthsNoOverflow($months);
    }

    /** Whole months from today to the deadline; negative once it has passed. */
    public function monthsRemaining(): int
    {
        return (int) now()->startOfDay()->diffInMonths($this->rpd_deadline, false);
    }

    public function daysRemaining(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->rpd_deadline, false);
    }

    public function isOverdue(): bool
    {
        return $this->daysRemaining() < 0;
    }

    /** Extension months still available under the ceiling. */
    public function extensionMonthsRemaining(): int
    {
        return max(0, self::MAX_EXTENSION_MONTHS - $this->extension_months_used);
    }

    /**
     * Whether this candidacy may appeal at all.
     *
     * A defended or dismissed clock has stopped, and a student who has used the
     * whole ceiling has nothing left to ask for.
     */
    public function canAppeal(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_EXTENDED], true)
            && $this->extensionMonthsRemaining() > 0;
    }

    /** Whether CGS may open a dismissal: the deadline passed and it is still running. */
    public function isDismissible(): bool
    {
        return $this->isOverdue()
            && in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_EXTENDED], true);
    }

    /**
     * How urgent this row is, for the masterlist and the student's own view.
     * Same four tones the dashboards already use.
     */
    public function tone(): string
    {
        return match (true) {
            $this->status === self::STATUS_DISMISSED => 'critical',
            $this->status === self::STATUS_DEFENDED => 'good',
            $this->isOverdue() => 'critical',
            $this->monthsRemaining() <= 1 => 'warn',
            $this->monthsRemaining() <= 3 => 'info',
            default => 'good',
        };
    }
}
