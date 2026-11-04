<?php

namespace App\Modules\Nureen\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'student_id', 'period_end', 'sessions_attended', 'sessions_total', 'sessions_excused', 'leave_days', 'percentage', 'at_risk',
    ];

    protected function casts(): array
    {
        return [
            'period_end' => 'date',
            'percentage' => 'decimal:2',
            'at_risk' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Percentage is always derived from the two counts, never taken
        // as-is from the CSV -- rule 7, "derive, don't trust".
        //
        // Sessions excused by an approved appeal are folded in according to
        // config('nureen.attendance.excused_sessions') -- see
        // countedAttended() / countedTotal().
        static::saving(function (self $record) {
            $record->percentage = $record->countedPercentage();
        });
    }

    /** Excused sessions are counted per config: as attended (default) or out of the total. */
    public static function excusalRule(): string
    {
        return config('nureen.attendance.excused_sessions') === 'exclude_from_total'
            ? 'exclude_from_total'
            : 'count_as_attended';
    }

    /**
     * Sessions on approved annual leave this period: leave_days times
     * config('nureen.attendance.leave.sessions_per_day').
     */
    public function leaveSessions(): int
    {
        return (int) $this->leave_days * self::sessionsPerLeaveDay();
    }

    public static function sessionsPerLeaveDay(): int
    {
        return max(1, (int) config('nureen.attendance.leave.sessions_per_day', 1));
    }

    /**
     * Sessions that do not count as absence: excused by an approved appeal,
     * or on approved annual leave. Both are treated the same way
     * (Postgraduate Handbook 2025: approved leave is not absence).
     */
    public function sessionsExcusedOrOnLeave(): int
    {
        return (int) $this->sessions_excused + $this->leaveSessions();
    }

    /**
     * Sessions counted as attended. 'count_as_attended': attended + excused
     * + leave, never more than the total. 'exclude_from_total': attended
     * only. The one place an approved appeal's excusal and approved leave
     * are folded into attended -- used by `percentage`, the semester figure
     * and the projection alike.
     */
    public function countedAttended(): int
    {
        return self::excusalRule() === 'exclude_from_total'
            ? (int) $this->sessions_attended
            : min((int) $this->sessions_total, (int) $this->sessions_attended + $this->sessionsExcusedOrOnLeave());
    }

    /** Sessions counted in the period. 'exclude_from_total': total - excused - leave. */
    public function countedTotal(): int
    {
        return self::excusalRule() === 'exclude_from_total'
            ? max(0, (int) $this->sessions_total - $this->sessionsExcusedOrOnLeave())
            : (int) $this->sessions_total;
    }

    /**
     * The period's percentage under the configured rule. A period whose
     * every missed session was excused under 'exclude_from_total' has
     * nothing countable left that was missed, so it reads 100%, not 0%;
     * a period with no sessions at all reads 0%, as it always has.
     */
    public function countedPercentage(): float
    {
        if ((int) $this->sessions_total <= 0) {
            return 0.0;
        }

        $total = $this->countedTotal();

        return $total > 0 ? round(($this->countedAttended() / $total) * 100, 2) : 100.0;
    }

    /** Missed sessions not yet excused or on leave -- what an appeal could still ask for, whichever rule applies. */
    public function sessionsMissed(): int
    {
        return max(0, (int) $this->sessions_total - (int) $this->sessions_attended - $this->sessionsExcusedOrOnLeave());
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * Record one student's figures for one period, replacing that period if
     * it is already on file.
     *
     * The lookup uses whereDate() rather than a plain equality on the raw
     * string, and that is load-bearing: the `date` cast writes `period_end`
     * with a zeroed time, so a bare `where('period_end', '2026-08-31')`
     * compares "2026-08-31" against "2026-08-31 00:00:00". MySQL coerces the
     * two and matches, which is why re-uploading appeared to work; any driver
     * that does not coerce misses the match, tries to insert, and hits the
     * unique(student_id, period_end) index -- a 500 that rolls back the whole
     * upload rather than updating one row. whereDate() compiles per driver
     * and matches on either.
     *
     * Lives on the model because "a period is replaced, not duplicated" is a
     * promise about this table, and the upload screen is not the only thing
     * that will eventually write to it.
     */
    public static function recordPeriod(int $studentId, string $periodEnd, int $attended, int $total, ?int $leaveDays = null): self
    {
        $record = static::query()
            ->where('student_id', $studentId)
            ->whereDate('period_end', $periodEnd)
            ->first()
            ?? new static(['student_id' => $studentId, 'period_end' => $periodEnd]);

        // percentage is recomputed from these two by the saving hook above --
        // never taken from the sheet.
        $record->fill([
            'sessions_attended' => $attended,
            'sessions_total' => $total,
        ]);

        // Approved leave from the sheet's optional leave_days column. Null
        // -- no column, or left blank -- keeps whatever CGS recorded, so a
        // re-upload without the column never wipes it.
        if ($leaveDays !== null) {
            $record->leave_days = $leaveDays;
        }

        $record->save();

        return $record;
    }

    /**
     * CGS records approved leave days for a period already on file (the
     * Approved Leave page). The same column the sheet's leave_days writes,
     * the same recount. Returns null when the period is not on file.
     */
    public static function recordLeave(int $studentId, string $periodEnd, int $days): ?self
    {
        $record = static::query()
            ->where('student_id', $studentId)
            ->whereDate('period_end', $periodEnd)
            ->first();

        if (! $record) {
            return null;
        }

        $record->update(['leave_days' => $days]);
        $record->update(['at_risk' => \App\Modules\Nureen\Support\AttendanceRiskEvaluator::isAtRisk($record)]);

        return $record;
    }

    /**
     * The most leave days this period can take: one per missed session not
     * already excused by an appeal.
     */
    public function leaveCapacity(?int $attended = null, ?int $total = null): int
    {
        $missed = ($total ?? (int) $this->sessions_total) - ($attended ?? (int) $this->sessions_attended) - (int) $this->sessions_excused;

        return max(0, intdiv(max(0, $missed), self::sessionsPerLeaveDay()));
    }

    /**
     * One row per student: their most recent period, whenever it was.
     *
     * "How is each student doing right now" is the question behind the
     * CGS at-risk list, the CGS attendance bands and the admin average, and
     * all three had built this same self-join separately. A student's periods
     * do not all end on the same date -- someone whose last upload was in
     * March must still be counted -- so it cannot be a plain
     * `where('period_end', $latest)`.
     *
     * Returns a query, not a collection, so a caller can still filter it:
     * `->latestPerStudent()->where('at_risk', true)`.
     */
    public function scopeLatestPerStudent(Builder $query): Builder
    {
        $newest = static::query()
            ->select('student_id', DB::raw('MAX(period_end) as max_period_end'))
            ->groupBy('student_id');

        return $query
            ->joinSub($newest, 'latest', function ($join) {
                $join->on('attendance_records.student_id', '=', 'latest.student_id')
                    ->on('attendance_records.period_end', '=', 'latest.max_period_end');
            })
            ->select('attendance_records.*');
    }
}
