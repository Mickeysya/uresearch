<?php

namespace App\Modules\Nureen\Support;

/**
 * Where a student's attendance is heading by the end of the semester.
 *
 * Least-squares linear regression on the student's OWN per-period rates --
 * not a trained model: CGS has no labelled history to train one on, and a
 * straight line through a student's recent periods is something CGS staff
 * can understand and check by hand.
 *
 * Pure: no database, no config, no clock. The caller hands over the periods
 * (already including any sessions excused by an approved appeal) and the
 * length of the semester. See AttendanceRiskEvaluator::assess().
 */
final class AttendanceProjector
{
    public const TOO_FEW_PERIODS = 'too_few_periods';

    public const INCONSISTENT_PERIODS = 'inconsistent_periods';

    public function __construct(private readonly int $minPeriods = 3) {}

    /**
     * Why there is no projection for these periods, or null when there is
     * one. A regression against period NUMBER only means anything if every
     * period is the same length: a monthly row followed by weekly ones would
     * be treated as one "period" each, and the line would be nonsense. So
     * when the periods carry their end dates and those dates show mixed (or
     * irregular) lengths, the projector refuses rather than guesses.
     *
     * @param  array<int, array{attended: int, total: int, end?: string}>  $periods
     */
    public function unavailableReason(array $periods): ?string
    {
        $ends = array_column($periods, 'end');

        if (count($ends) === count($periods) && self::periodLength($ends) === null) {
            return self::INCONSISTENT_PERIODS;
        }

        $usable = count(array_filter($periods, fn ($p) => (int) $p['total'] > 0));

        return $usable < $this->minPeriods ? self::TOO_FEW_PERIODS : null;
    }

    /**
     * 'weekly' (every gap between consecutive end dates is 6-8 days),
     * 'monthly' (every gap 28-31 days), 'unknown' (fewer than two periods,
     * nothing to compare), or null when the lengths are mixed or a gap fits
     * neither -- a missed fortnight counts as inconsistent, deliberately.
     *
     * @param  array<int, string>  $ends  Y-m-d, oldest first
     */
    public static function periodLength(array $ends): ?string
    {
        if (count($ends) < 2) {
            return 'unknown';
        }

        $kinds = [];
        for ($i = 1; $i < count($ends); $i++) {
            $gap = (int) (new \DateTimeImmutable($ends[$i - 1]))->diff(new \DateTimeImmutable($ends[$i]))->days;
            $kinds[] = match (true) {
                $gap >= 6 && $gap <= 8 => 'weekly',
                $gap >= 28 && $gap <= 31 => 'monthly',
                default => 'irregular',
            };
        }

        $kinds = array_unique($kinds);

        return count($kinds) === 1 && $kinds[0] !== 'irregular' ? $kinds[0] : null;
    }

    /**
     * @param  array<int, array{attended: int, total: int, end?: string}>  $periods  oldest first, one per period so far
     * @param  int  $semesterPeriods  how many periods the whole semester has
     * @return array{current: float, projected: float, slope: float, periods_used: int, periods_remaining: int}|null
     *         null when unavailableReason() gives a reason: too few usable
     *         periods, or periods of inconsistent length
     */
    public function project(array $periods, int $semesterPeriods): ?array
    {
        if ($this->unavailableReason($periods) !== null) {
            return null;
        }

        // Period numbers count every period, including one with no
        // sessions (a public-holiday week still passes), but only periods
        // that HAD sessions give a rate to fit.
        $points = [];
        foreach (array_values($periods) as $i => $period) {
            if ((int) $period['total'] > 0) {
                $points[] = [
                    'x' => $i + 1,
                    'rate' => 100 * (int) $period['attended'] / (int) $period['total'],
                    'attended' => (int) $period['attended'],
                    'total' => (int) $period['total'],
                ];
            }
        }

        $n = count($points);

        // Least squares: slope = Σ(x-x̄)(y-ȳ) / Σ(x-x̄)².
        $meanX = array_sum(array_column($points, 'x')) / $n;
        $meanY = array_sum(array_column($points, 'rate')) / $n;
        $sxy = 0.0;
        $sxx = 0.0;
        foreach ($points as $p) {
            $sxy += ($p['x'] - $meanX) * ($p['rate'] - $meanY);
            $sxx += ($p['x'] - $meanX) ** 2;
        }
        $slope = $sxx > 0 ? $sxy / $sxx : 0.0;
        $intercept = $meanY - $slope * $meanX;

        $attended = array_sum(array_column($points, 'attended'));
        $total = array_sum(array_column($points, 'total'));
        $averageSessions = $total / $n;

        // Extend the line over the periods still to come, each clamped to
        // 0-100%, at the average number of sessions seen so far.
        $elapsed = count($periods);
        $remaining = max(0, $semesterPeriods - $elapsed);
        $projectedAttended = (float) $attended;

        for ($x = $elapsed + 1; $x <= $elapsed + $remaining; $x++) {
            $rate = max(0.0, min(100.0, $intercept + $slope * $x));
            $projectedAttended += $averageSessions * $rate / 100;
        }

        return [
            'current' => round(100 * $attended / $total, 1),
            'projected' => round(100 * $projectedAttended / ($total + $averageSessions * $remaining), 1),
            'slope' => round($slope, 2) + 0.0,  // + 0.0 turns -0.0 into 0.0
            'periods_used' => $n,
            'periods_remaining' => $remaining,
        ];
    }
}
