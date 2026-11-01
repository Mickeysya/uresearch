<?php

namespace App\Modules\Nureen\Support\Backtest;

use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Support\AttendanceProjector;
use App\Modules\Nureen\Support\AttendanceRiskEvaluator;

/**
 * How well the attendance early warning predicts, measured on complete
 * semesters whose outcome is already known.
 *
 * For each cut-off period k, every student's history is cut at k: the
 * periods after it are hidden, and the system's checks are replayed as if
 * they had run after each visible period. The result is compared with what
 * actually happened by the end of the semester.
 *
 * Everything the live check uses is used here, unchanged:
 *   - per-period percentage: AttendanceRecord::countedPercentage(), on an
 *     unsaved model (nothing is written);
 *   - the original rule: AttendanceRiskEvaluator::ruleFlags();
 *   - the projection: AttendanceProjector::project().
 *
 * Definitions (also printed in the report):
 *   - Actual final % = all attended / all scheduled sessions over the whole
 *     semester. "Truly at risk" = actual final below the threshold (80%).
 *   - A method has FLAGGED a student by cut-off k if it flagged them at ANY
 *     check from period 1 to k: the live system emails on the first flag,
 *     so that is when the student is warned.
 *       rule        AttendanceRiskEvaluator::ruleFlags() on that period;
 *       projection  projected end-of-semester % below the threshold;
 *       combined    rule OR projection (what the system uses).
 *   - Error = |projected % at period k - actual final %|, over the students
 *     with a projection at k (none before projection_min_periods, or when
 *     period lengths are inconsistent). The baseline carries the student's
 *     attendance so far (cumulative % at k) forward unchanged.
 *   - Earliness: over the whole semester, for truly at-risk students, the
 *     first period the combined approach flagged them versus the first
 *     period the rule alone did.
 *
 * Pure: no database writes, no notifications, no clock.
 */
final class AttendanceBacktester
{
    public function __construct(
        private readonly int $semesterPeriods,
        private readonly int $minPeriods = 3,
        private readonly float $threshold = AttendanceRiskEvaluator::THRESHOLD,
    ) {}

    /**
     * @param  array<string, array<int, array{attended: int, total: int, end: string}>>  $students  each oldest first, exactly semesterPeriods long
     * @param  array<int, int>  $cutoffs  period numbers, 1..semesterPeriods
     * @return array{students: int, at_risk: int, cutoffs: array<int, array>, earliness: array}
     */
    public function run(array $students, array $cutoffs): array
    {
        $timelines = array_map(fn (array $periods) => $this->timeline($periods), $students);

        return [
            'students' => count($timelines),
            'at_risk' => count(array_filter($timelines, fn ($t) => $t['label'])),
            'cutoffs' => array_map(fn (int $k) => $this->atCutoff($timelines, $k), $cutoffs),
            'earliness' => $this->earliness($timelines),
        ];
    }

    /** Turn a cut-off given as a period number ("7") or a share of the semester ("50%") into a period number. */
    public static function cutoffPeriod(string $cutoff, int $semesterPeriods): int
    {
        $cutoff = trim($cutoff);

        $period = str_ends_with($cutoff, '%')
            ? (int) round((float) rtrim($cutoff, '%') / 100 * $semesterPeriods)
            : (int) $cutoff;

        return max(1, min($semesterPeriods, $period));
    }

    /**
     * Each period's percentage, rule flag and projection, as the live
     * check would have seen them at that point, plus the actual outcome.
     *
     * @param  array<int, array{attended: int, total: int, end: string}>  $periods
     */
    private function timeline(array $periods): array
    {
        $periods = array_values($periods);
        $projector = new AttendanceProjector($this->minPeriods);
        $rates = [];
        $steps = [];
        $attended = 0;
        $total = 0;

        foreach ($periods as $i => $period) {
            $rates[$i] = (new AttendanceRecord([
                'sessions_attended' => $period['attended'],
                'sessions_total' => $period['total'],
                'sessions_excused' => 0,
            ]))->countedPercentage();

            $attended += $period['attended'];
            $total += $period['total'];
            $projection = $projector->project(array_slice($periods, 0, $i + 1), $this->semesterPeriods);

            $steps[$i + 1] = [
                'rule' => AttendanceRiskEvaluator::ruleFlags($rates[$i], $rates[$i - 1] ?? null, $i >= 2 ? $rates[$i - 2] : null),
                'projected' => $projection['projected'] ?? null,
                'projection' => $projection !== null && $projection['projected'] < $this->threshold,
                'so_far' => $total > 0 ? 100 * $attended / $total : null,
            ];
        }

        $actual = $total > 0 ? 100 * $attended / $total : 0.0;

        return ['steps' => $steps, 'actual' => $actual, 'label' => $actual < $this->threshold];
    }

    private function atCutoff(array $timelines, int $k): array
    {
        $confusion = ['rule' => [0, 0, 0, 0], 'projection' => [0, 0, 0, 0], 'combined' => [0, 0, 0, 0]];
        $errors = [];
        $baselineErrors = [];

        foreach ($timelines as $t) {
            $visible = array_slice($t['steps'], 0, $k, true);
            $byRule = in_array(true, array_column($visible, 'rule'), true);
            $byProjection = in_array(true, array_column($visible, 'projection'), true);

            foreach (['rule' => $byRule, 'projection' => $byProjection, 'combined' => $byRule || $byProjection] as $method => $flagged) {
                // [TP, FP, FN, TN]
                $confusion[$method][$flagged ? ($t['label'] ? 0 : 1) : ($t['label'] ? 2 : 3)]++;
            }

            if (($projected = $t['steps'][$k]['projected'] ?? null) !== null) {
                // The projector rounds to one decimal; the actual figure is
                // compared at the same precision.
                $errors[] = $projected - round($t['actual'], 1);
                $baselineErrors[] = round($t['steps'][$k]['so_far'], 1) - round($t['actual'], 1);
            }
        }

        return [
            'cutoff' => $k,
            'share' => $k / $this->semesterPeriods,
            'projected_n' => count($errors),
            'mae' => self::mae($errors),
            'rmse' => $errors === [] ? null : sqrt(array_sum(array_map(fn ($e) => $e * $e, $errors)) / count($errors)),
            'baseline_mae' => self::mae($baselineErrors),
            'methods' => array_map(fn (array $c) => self::scores(...$c), $confusion),
        ];
    }

    private function earliness(array $timelines): array
    {
        $leads = [];
        $onlyCombined = 0;
        $missed = 0;

        foreach (array_filter($timelines, fn ($t) => $t['label']) as $t) {
            $firstRule = self::first($t['steps'], fn ($s) => $s['rule']);
            $firstCombined = self::first($t['steps'], fn ($s) => $s['rule'] || $s['projection']);

            if ($firstCombined === null) {
                $missed++;
            } elseif ($firstRule === null) {
                $onlyCombined++;
            } else {
                $leads[] = $firstRule - $firstCombined;
            }
        }

        return [
            'both_flagged' => count($leads),
            'mean_lead' => $leads === [] ? null : array_sum($leads) / count($leads),
            'earlier' => count(array_filter($leads, fn ($l) => $l > 0)),
            'only_combined' => $onlyCombined,
            'missed' => $missed,
        ];
    }

    private static function first(array $steps, callable $flagged): ?int
    {
        foreach ($steps as $period => $step) {
            if ($flagged($step)) {
                return $period;
            }
        }

        return null;
    }

    private static function mae(array $errors): ?float
    {
        return $errors === [] ? null : array_sum(array_map('abs', $errors)) / count($errors);
    }

    /** Precision, recall and F1 from a confusion matrix; null where undefined (nothing flagged / nobody at risk). */
    private static function scores(int $tp, int $fp, int $fn, int $tn): array
    {
        $precision = $tp + $fp > 0 ? $tp / ($tp + $fp) : null;
        $recall = $tp + $fn > 0 ? $tp / ($tp + $fn) : null;
        $f1 = $precision !== null && $recall !== null && $precision + $recall > 0
            ? 2 * $precision * $recall / ($precision + $recall)
            : null;

        return compact('tp', 'fp', 'fn', 'tn', 'precision', 'recall', 'f1');
    }
}
