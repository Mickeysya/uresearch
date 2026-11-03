<?php

namespace App\Modules\Nureen\Support\Backtest;

use Carbon\CarbonImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A SYNTHETIC full-semester attendance dataset for the backtest, until CGS
 * provides an anonymised real one. Not real students: every matric number
 * is "SYN" plus a number, and every report built from it says so.
 *
 * Reproducible: the same seed, student count and semester length always
 * give the same dataset (its own Mt19937 engine, never the global one).
 *
 * Every student follows one of PATTERNS, in those proportions. On top of
 * the pattern each period gets uniform noise of ±NOISE percentage points
 * (±NOISY_FINE_NOISE for "noisy but fine"), clamped to 0-100%, and is
 * converted to whole sessions out of SESSIONS_PER_PERIOD.
 */
final class SyntheticAttendance
{
    public const SESSIONS_PER_PERIOD = 10;

    public const NOISE = 4.0;

    public const NOISY_FINE_NOISE = 12.0;

    /** Week-ending Friday of the synthetic semester's first period. */
    public const FIRST_PERIOD_END = '2026-01-09';

    /** @var array<string, array{label: string, share: float, shape: string}> */
    public const PATTERNS = [
        'steady_high' => ['label' => 'Steady high', 'share' => 0.30, 'shape' => 'level at 90-99%'],
        'steady_borderline' => ['label' => 'Steady borderline', 'share' => 0.15, 'shape' => 'level at 79-87%'],
        'gradual_decline' => ['label' => 'Gradual decline', 'share' => 0.15, 'shape' => 'starts at 88-98%, falls 1.5-3.5 points a period'],
        'sudden_drop' => ['label' => 'Sudden drop', 'share' => 0.10, 'shape' => '88-97% until a period between 35% and 75% of the way through, then 45-70%'],
        'recovery' => ['label' => 'Recovery after a bad period', 'share' => 0.10, 'shape' => '2-3 periods at 40-65% starting in period 2-4, otherwise 88-97%'],
        'isolated_bad' => ['label' => 'One isolated bad period', 'share' => 0.10, 'shape' => 'one period at 30-60%, otherwise 88-97%'],
        'noisy_fine' => ['label' => 'Noisy but fine', 'share' => 0.10, 'shape' => 'level at 88-93% with ±12-point noise'],
    ];

    private Randomizer $random;

    public function __construct(private readonly int $seed = 2026)
    {
        $this->random = new Randomizer(new Mt19937($seed));
    }

    /**
     * @return array{students: array<string, array<int, array{attended: int, total: int, end: string}>>, patterns: array<string, string>, counts: array<string, int>}
     */
    public function generate(int $students, int $periods): array
    {
        $counts = self::counts($students);
        $out = ['students' => [], 'patterns' => [], 'counts' => $counts];
        $n = 0;

        foreach ($counts as $pattern => $count) {
            for ($i = 0; $i < $count; $i++) {
                $matric = sprintf('SYN%04d', ++$n);
                $out['patterns'][$matric] = $pattern;
                $out['students'][$matric] = $this->student($pattern, $periods);
            }
        }

        return $out;
    }

    /**
     * Students per pattern: each share rounded down, then the remainder
     * handed out one each in PATTERNS order.
     *
     * @return array<string, int>
     */
    public static function counts(int $students): array
    {
        $counts = array_map(fn ($p) => (int) floor($p['share'] * $students), self::PATTERNS);

        foreach (array_keys(self::PATTERNS) as $pattern) {
            if (array_sum($counts) >= $students) {
                break;
            }
            $counts[$pattern]++;
        }

        return $counts;
    }

    /** @return array<int, array{attended: int, total: int, end: string}> */
    private function student(string $pattern, int $periods): array
    {
        $rates = match ($pattern) {
            'steady_high' => array_fill(1, $periods, $this->uniform(90, 99)),
            'steady_borderline' => array_fill(1, $periods, $this->uniform(79, 87)),
            'gradual_decline' => $this->decline($periods),
            'sudden_drop' => $this->suddenDrop($periods),
            'recovery' => $this->recovery($periods),
            'isolated_bad' => $this->isolatedBad($periods),
            'noisy_fine' => array_fill(1, $periods, $this->uniform(88, 93)),
        };

        $noise = $pattern === 'noisy_fine' ? self::NOISY_FINE_NOISE : self::NOISE;
        $first = CarbonImmutable::parse(self::FIRST_PERIOD_END);
        $out = [];

        foreach ($rates as $period => $rate) {
            $rate = max(0.0, min(100.0, $rate + $this->uniform(-$noise, $noise)));
            $out[] = [
                'attended' => (int) round($rate / 100 * self::SESSIONS_PER_PERIOD),
                'total' => self::SESSIONS_PER_PERIOD,
                'end' => $first->addWeeks($period - 1)->toDateString(),
            ];
        }

        return $out;
    }

    private function decline(int $periods): array
    {
        $start = $this->uniform(88, 98);
        $slope = $this->uniform(1.5, 3.5);

        return array_combine(range(1, $periods), array_map(fn ($p) => $start - $slope * ($p - 1), range(1, $periods)));
    }

    private function suddenDrop(int $periods): array
    {
        $before = $this->uniform(88, 97);
        $after = $this->uniform(45, 70);
        $dropAt = $this->random->getInt((int) ceil($periods * 0.35), max((int) ceil($periods * 0.35), (int) floor($periods * 0.75)));

        return array_combine(range(1, $periods), array_map(fn ($p) => $p >= $dropAt ? $after : $before, range(1, $periods)));
    }

    private function recovery(int $periods): array
    {
        $good = $this->uniform(88, 97);
        $bad = $this->uniform(40, 65);
        $from = $this->random->getInt(2, min(4, $periods));
        $length = $this->random->getInt(2, 3);

        return array_combine(range(1, $periods), array_map(fn ($p) => $p >= $from && $p < $from + $length ? $bad : $good, range(1, $periods)));
    }

    private function isolatedBad(int $periods): array
    {
        $good = $this->uniform(88, 97);
        $bad = $this->uniform(30, 60);
        $at = $this->random->getInt(2, max(2, $periods - 1));

        return array_combine(range(1, $periods), array_map(fn ($p) => $p === $at ? $bad : $good, range(1, $periods)));
    }

    private function uniform(float $min, float $max): float
    {
        return $min + ($max - $min) * $this->random->getInt(0, 1_000_000) / 1_000_000;
    }
}
