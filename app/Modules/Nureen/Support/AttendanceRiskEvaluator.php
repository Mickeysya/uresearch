<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Models\AttendanceRiskState;

/**
 * The "Gradual At-Risk" early-warning rule.
 *
 * A student is flagged in two independent ways:
 *   1. Already below the mandatory 80% attendance threshold -- a plain
 *      compliance check.
 *   2. Still compliant but trending toward it: attendance has dropped in
 *      each of the last two uploaded periods AND sits within 5 points of the
 *      threshold. This is the predictive half -- it catches a student before
 *      they cross 80%, not after.
 *
 * A small, explicit rule rather than anything statistical, per the scope's
 * "rule-based predictive analytics" framing agreed with CGS.
 *
 * assess() adds a third, genuinely predictive check alongside the rule: a
 * least-squares projection of the student's end-of-semester attendance
 * (AttendanceProjector). The rule itself (isAtRisk()) is unchanged, and
 * attendance_records.at_risk still means only the rule; the combined
 * outcome lives in attendance_risk_states, which only CGS sees.
 *
 * Students never see the projection: the student dashboard's card is
 * Support\AttendanceTarget (plain session arithmetic), not a forecast.
 */
class AttendanceRiskEvaluator
{
    public const THRESHOLD = 80.0;

    public const EARLY_WARNING_BAND = 5.0;

    /**
     * The full risk check for one student: the original per-period rule
     * (isAtRisk(), unchanged) AND the end-of-semester projection
     * (AttendanceProjector), with the reason recorded:
     *   'current'   the original rule alone
     *   'projected' the projection alone -- projected end-of-semester %
     *               below THRESHOLD, though the rule is not (yet) tripped
     *   'both'
     *
     * Figures are over the current semester's periods only (config
     * nureen.attendance.semester_start), and count sessions excused by an
     * approved appeal per config('nureen.attendance.excused_sessions')
     * (AttendanceRecord::countedAttended() / countedTotal()).
     * `current` is the cumulative semester figure -- what the 80% rule is
     * actually measured on -- not the latest period's own percentage.
     *
     * `projection_status` is null when there is a projection, otherwise why
     * not: AttendanceProjector::TOO_FEW_PERIODS or INCONSISTENT_PERIODS
     * (mixed period lengths: the rule still applies, the projection does not).
     *
     * @return array{at_risk: bool, reason: ?string, current: ?float, projected: ?float, slope: ?float, periods_used: int, projection_status: ?string, latest: ?AttendanceRecord}
     */
    public static function assess(int $studentId): array
    {
        $records = AttendanceRecord::where('student_id', $studentId)
            ->when(config('nureen.attendance.semester_start'), fn ($q, $start) => $q->whereDate('period_end', '>=', $start))
            ->orderBy('period_end')
            ->get();

        $latest = $records->last();

        if (! $latest) {
            return ['at_risk' => false, 'reason' => null, 'current' => null, 'projected' => null, 'slope' => null, 'periods_used' => 0, 'projection_status' => AttendanceProjector::TOO_FEW_PERIODS, 'latest' => null];
        }

        $byRule = self::isAtRisk($latest);

        $periods = $records->map(fn (AttendanceRecord $r) => [
            'attended' => $r->countedAttended(),
            'total' => $r->countedTotal(),
            'end' => $r->period_end->toDateString(),
        ])->all();

        $projector = new AttendanceProjector((int) config('nureen.attendance.projection_min_periods', 3));
        $projection = $projector->project($periods, (int) config('nureen.attendance.semester_periods', 14));

        $byProjection = $projection !== null
            && $projection['projected'] < self::THRESHOLD
            && self::projectionMayFlag($records->count(), (int) config('nureen.attendance.semester_periods', 14));

        $total = $records->sum(fn ($r) => $r->countedTotal());
        $current = $total > 0 ? round(100 * $records->sum(fn ($r) => $r->countedAttended()) / $total, 1) : null;

        return [
            'at_risk' => $byRule || $byProjection,
            'reason' => match (true) {
                $byRule && $byProjection => AttendanceRiskState::REASON_BOTH,
                $byRule => AttendanceRiskState::REASON_CURRENT,
                $byProjection => AttendanceRiskState::REASON_PROJECTED,
                default => null,
            },
            'current' => $current,
            'projected' => $projection['projected'] ?? null,
            'slope' => $projection['slope'] ?? null,
            'periods_used' => $projection['periods_used'] ?? $records->filter(fn ($r) => $r->countedTotal() > 0)->count(),
            'projection_status' => $projection === null ? $projector->unavailableReason($periods) : null,
            'latest' => $latest,
        ];
    }

    public static function isAtRisk(AttendanceRecord $current): bool
    {
        $priorTwo = AttendanceRecord::where('student_id', $current->student_id)
            ->where('period_end', '<', $current->period_end)
            ->orderByDesc('period_end')
            ->limit(2)
            ->get()
            ->values();

        return self::ruleFlags(
            (float) $current->percentage,
            $priorTwo->count() === 2 ? (float) $priorTwo[0]->percentage : null,
            $priorTwo->count() === 2 ? (float) $priorTwo[1]->percentage : null,
        );
    }

    /**
     * Whether the projection may flag a student who has $periodsSoFar
     * periods of a $semesterPeriods-period semester: at least
     * projection_min_periods_to_flag periods, and no later than
     * projection_active_until of the semester. Outside that window the
     * projection is still computed (CGS sees it) but only the rule flags.
     * The backtest passes both settings explicitly to compare configurations;
     * the live check reads them from config.
     */
    public static function projectionMayFlag(int $periodsSoFar, int $semesterPeriods, ?int $minPeriods = null, ?float $activeUntil = null): bool
    {
        $minPeriods ??= (int) config('nureen.attendance.projection_min_periods_to_flag', 4);
        $activeUntil ??= (float) config('nureen.attendance.projection_active_until', 0.6);

        // The epsilon keeps 0.5 x 14 = 7.000000001 from excluding period 7.
        return $periodsSoFar >= $minPeriods && $periodsSoFar <= $activeUntil * $semesterPeriods + 1e-9;
    }

    /**
     * The rule itself, on three per-period percentages (latest first; the
     * earlier two null when there are fewer than two prior periods). Pure,
     * so the backtest (Support\Backtest\AttendanceBacktester) judges exactly
     * the rule the live check runs.
     */
    public static function ruleFlags(float $current, ?float $previous, ?float $beforeThat): bool
    {
        if ($current < self::THRESHOLD) {
            return true;
        }

        if ($previous === null || $beforeThat === null) {
            return false;
        }

        $declining = $current < $previous && $previous < $beforeThat;
        $nearThreshold = $current <= self::THRESHOLD + self::EARLY_WARNING_BAND;

        return $declining && $nearThreshold;
    }
}
