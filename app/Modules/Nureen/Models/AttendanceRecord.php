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
        'student_id', 'period_end', 'sessions_attended', 'sessions_total', 'percentage', 'at_risk',
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
        static::saving(function (self $record) {
            $record->percentage = $record->sessions_total > 0
                ? round(($record->sessions_attended / $record->sessions_total) * 100, 2)
                : 0;
        });
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
    public static function recordPeriod(int $studentId, string $periodEnd, int $attended, int $total): self
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
        ])->save();

        return $record;
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
