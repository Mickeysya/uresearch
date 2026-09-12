<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Nureen\Models\AttendanceRecord;

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
 */
class AttendanceRiskEvaluator
{
    public const THRESHOLD = 80.0;

    public const EARLY_WARNING_BAND = 5.0;

    /**
     * Where this student's attendance is heading, as a percentage.
     *
     * A straight-line projection of the recent trend, in the same spirit as
     * isAtRisk() above: a small explicit rule, not a statistical model. Takes
     * the mean change per period across the last few uploads and carries it
     * forward, clamped to 0-100.
     *
     * Returns null when there is not enough history to have a trend at all --
     * the caller shows "not enough data" rather than inventing a number.
     */
    public static function project(AttendanceRecord $current, int $periodsAhead = 4): ?float
    {
        $history = AttendanceRecord::where('student_id', $current->student_id)
            ->where('period_end', '<=', $current->period_end)
            ->orderByDesc('period_end')
            ->limit(4)
            ->get()
            ->reverse()
            ->values();

        if ($history->count() < 2) {
            return null;
        }

        $deltas = [];
        for ($i = 1; $i < $history->count(); $i++) {
            $deltas[] = (float) $history[$i]->percentage - (float) $history[$i - 1]->percentage;
        }

        $meanDelta = array_sum($deltas) / count($deltas);
        $projected = (float) $current->percentage + ($meanDelta * $periodsAhead);

        return round(max(0, min(100, $projected)), 1);
    }

    public static function isAtRisk(AttendanceRecord $current): bool
    {
        if ((float) $current->percentage < self::THRESHOLD) {
            return true;
        }

        $priorTwo = AttendanceRecord::where('student_id', $current->student_id)
            ->where('period_end', '<', $current->period_end)
            ->orderByDesc('period_end')
            ->limit(2)
            ->get();

        if ($priorTwo->count() < 2) {
            return false;
        }

        [$previous, $beforeThat] = $priorTwo->values()->all();

        $declining = (float) $current->percentage < (float) $previous->percentage
            && (float) $previous->percentage < (float) $beforeThat->percentage;

        $nearThreshold = (float) $current->percentage <= self::THRESHOLD + self::EARLY_WARNING_BAND;

        return $declining && $nearThreshold;
    }
}
