<?php

namespace App\Modules\Chloe\Assistant;

use Illuminate\Support\Carbon;

/**
 * The "previous intent task"'s period extractor, missing from the
 * codebase before this task (confirmed by investigation -- only a bare
 * ParameterExtractor::months(int) existed, no YYYY-MM/quarter/year
 * parsing or validation). Two jobs:
 *
 *  1. parseDeterministic(): a best-effort, pattern-based read of the
 *     RAW (already-masked) question text -- used only as a prompt HINT
 *     for the router and as the Part 5 fallback's own period source
 *     when the model is unavailable. Never trusted on its own for an
 *     actual answer.
 *  2. validate(): the one gate every period actually used to answer a
 *     question must pass through, whether it came from the model's own
 *     JSON or from parseDeterministic() -- "a model-supplied period is
 *     checked, never trusted" (Part 2.3). Rejects anything malformed,
 *     any year outside a sane window, or a quarter/month out of range.
 *
 * A validated period is always one of exactly four normalised shapes:
 *   ['kind' => 'month', 'year' => int, 'month' => 1-12]
 *   ['kind' => 'quarter', 'year' => int, 'quarter' => 1-4]
 *   ['kind' => 'year', 'year' => int]
 *   ['kind' => 'range', 'from' => 'YYYY-MM', 'to' => 'YYYY-MM']  (any other span)
 *
 * The router itself sends a period as a STRING (Part 2.2: "months as
 * YYYY-MM, quarters and years as explicit ranges"): "2026-11", or
 * "2027-01..2027-03" -- fromString() turns that into one of the shapes
 * above (a range that is exactly a quarter or a calendar year becomes
 * that kind), and toString() is its inverse, used for chip params.
 */
final class PeriodExtractor
{
    protected const MONTH_NAMES = [
        'january' => 1, 'jan' => 1, 'february' => 2, 'feb' => 2, 'march' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4, 'may' => 5, 'june' => 6, 'jun' => 6, 'july' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8, 'september' => 9, 'sep' => 9, 'sept' => 9,
        'october' => 10, 'oct' => 10, 'november' => 11, 'nov' => 11, 'december' => 12, 'dec' => 12,
    ];

    /** Seats are a Workstation-scale thing, not a multi-decade forecast -- anything further out than this is almost certainly a misread, not a real question. */
    protected const MAX_MONTHS_AHEAD = 36;

    /**
     * Best-effort deterministic read of raw (masked) text -- "november
     * 2026", "nov 2026", "Q1 2027", "quarter 1 2027", "2027" (bare
     * year), "next month", "this month", "next 3 months" (returns the
     * NEAREST month of a multi-month span; the forecast lookup itself
     * expands a relative "next N months" span separately, see
     * Lookups::releaseForecast()). Returns null, never a guess, when
     * nothing in the text looks like a period at all.
     */
    public static function parseDeterministic(string $text, ?Carbon $now = null): ?array
    {
        $now = $now ?? Carbon::now('Asia/Kuala_Lumpur');
        $needle = mb_strtolower($text);

        // "Q1 2027", "quarter 1 2027", "Q1" (year implied = current).
        if (preg_match('/\bq(?:uarter)?\s*([1-4])\s*(\d{4})?\b/i', $needle, $m)) {
            $quarter = (int) $m[1];
            $year = isset($m[2]) ? (int) $m[2] : (int) self::inferYearForQuarter($now, $quarter);

            return self::validate(['kind' => 'quarter', 'year' => $year, 'quarter' => $quarter]);
        }

        // A month name, with or without a year: "november 2026", "nov", "december".
        $monthPattern = implode('|', array_map('preg_quote', array_keys(self::MONTH_NAMES)));
        if (preg_match('/\b('.$monthPattern.')\b\s*(\d{4})?/i', $needle, $m)) {
            $month = self::MONTH_NAMES[mb_strtolower($m[1])];
            $year = isset($m[2]) && $m[2] !== ''
                ? (int) $m[2]
                : self::inferYearForMonth($now, $month);

            return self::validate(['kind' => 'month', 'year' => $year, 'month' => $month]);
        }

        // "this month" / "next month".
        if (preg_match('/\bthis\s+month\b/i', $needle)) {
            return self::validate(['kind' => 'month', 'year' => (int) $now->year, 'month' => (int) $now->month]);
        }
        if (preg_match('/\bnext\s+month\b/i', $needle)) {
            $next = $now->copy()->addMonth();

            return self::validate(['kind' => 'month', 'year' => (int) $next->year, 'month' => (int) $next->month]);
        }

        if (preg_match('/\bnext\s+quarter\b/i', $needle)) {
            $next = $now->copy()->startOfMonth()->addMonths(3);

            return self::validate(['kind' => 'quarter', 'year' => (int) $next->year, 'quarter' => (int) ceil($next->month / 3)]);
        }
        if (preg_match('/\b(this|next)\s+year\b/i', $needle, $m)) {
            return self::validate(['kind' => 'year', 'year' => (int) $now->year + (mb_strtolower($m[1]) === 'next' ? 1 : 0)]);
        }

        // A bare 4-digit year, standalone (not part of a month/quarter already matched above).
        if (preg_match('/\b(20\d{2})\b/', $needle, $m)) {
            return self::validate(['kind' => 'year', 'year' => (int) $m[1]]);
        }

        return null;
    }

    /**
     * "2026-11" or "2027-01..2027-03" (the router's own normalised
     * form) -> a validated period, or null. Never guesses: anything not
     * exactly that shape, or that fails validate(), is null.
     */
    public static function fromString(mixed $value, ?Carbon $now = null): ?array
    {
        if (is_array($value)) {
            return self::validate($value, $now);
        }

        if (! is_string($value) || ! preg_match('/^\s*(\d{4})-(\d{2})\s*(?:\.\.\s*(\d{4})-(\d{2})\s*)?$/', $value, $m)) {
            return null;
        }

        $fromYear = (int) $m[1];
        $fromMonth = (int) $m[2];

        if (! isset($m[3]) || $m[3] === '') {
            return self::validate(['kind' => 'month', 'year' => $fromYear, 'month' => $fromMonth], $now);
        }

        $toYear = (int) $m[3];
        $toMonth = (int) $m[4];

        if ($fromYear === $toYear && $fromMonth === $toMonth) {
            return self::validate(['kind' => 'month', 'year' => $fromYear, 'month' => $fromMonth], $now);
        }
        if ($fromYear === $toYear && $fromMonth === 1 && $toMonth === 12) {
            return self::validate(['kind' => 'year', 'year' => $fromYear], $now);
        }
        if ($fromYear === $toYear && $fromMonth % 3 === 1 && $toMonth === $fromMonth + 2) {
            return self::validate(['kind' => 'quarter', 'year' => $fromYear, 'quarter' => intdiv($fromMonth - 1, 3) + 1], $now);
        }

        return self::validate(['kind' => 'range', 'from' => sprintf('%04d-%02d', $fromYear, $fromMonth), 'to' => sprintf('%04d-%02d', $toYear, $toMonth)], $now);
    }

    /** Inverse of fromString(): a validated period -> "2026-11" / "2027-01..2027-03". */
    public static function toString(array $period): string
    {
        [$from, $to] = self::firstAndLastMonth($period);

        return $from->format('Y-m') === $to->format('Y-m') ? $from->format('Y-m') : $from->format('Y-m').'..'.$to->format('Y-m');
    }

    /** @return array{0: Carbon, 1: Carbon} the first and last calendar month (1st of each) a validated period covers. */
    protected static function firstAndLastMonth(array $period): array
    {
        $at = fn (int $y, int $mo) => Carbon::create($y, $mo, 1, 0, 0, 0, 'Asia/Kuala_Lumpur');

        return match ($period['kind']) {
            'month' => [$at($period['year'], $period['month']), $at($period['year'], $period['month'])],
            'quarter' => [$at($period['year'], ($period['quarter'] - 1) * 3 + 1), $at($period['year'], ($period['quarter'] - 1) * 3 + 3)],
            'year' => [$at($period['year'], 1), $at($period['year'], 12)],
            'range' => [
                $at((int) substr($period['from'], 0, 4), (int) substr($period['from'], 5, 2)),
                $at((int) substr($period['to'], 0, 4), (int) substr($period['to'], 5, 2)),
            ],
        };
    }

    /**
     * The one gate every period goes through before it is ever used to
     * answer a question -- rejects anything malformed or implausible,
     * never silently clamps a bad value into range.
     */
    public static function validate(mixed $period, ?Carbon $now = null): ?array
    {
        $now = $now ?? Carbon::now('Asia/Kuala_Lumpur');

        if (is_array($period) && ($period['kind'] ?? null) === 'range') {
            return self::validateRange($period, $now);
        }

        if (! is_array($period) || ! isset($period['kind'], $period['year'])) {
            return null;
        }

        $year = $period['year'];

        if (! is_int($year) && ! (is_string($year) && ctype_digit($year))) {
            return null;
        }
        $year = (int) $year;

        if ($year < $now->year - 1 || $year > $now->year + 3) {
            return null;
        }

        return match ($period['kind']) {
            'month' => self::validateMonth($period, $year, $now),
            'quarter' => self::validateQuarter($period, $year, $now),
            'year' => ['kind' => 'year', 'year' => $year],
            default => null,
        };
    }

    /** Any other span of whole months, oldest first, both ends within the same window a single month may use, at most MAX_MONTHS_AHEAD long. */
    protected static function validateRange(array $period, Carbon $now): ?array
    {
        $from = self::validate(self::monthShape($period['from'] ?? null), $now);
        $to = self::validate(self::monthShape($period['to'] ?? null), $now);

        if ($from === null || $to === null) {
            return null;
        }

        $fromKey = $from['year'] * 12 + $from['month'];
        $toKey = $to['year'] * 12 + $to['month'];

        if ($toKey < $fromKey || $toKey - $fromKey > self::MAX_MONTHS_AHEAD) {
            return null;
        }

        return ['kind' => 'range', 'from' => sprintf('%04d-%02d', $from['year'], $from['month']), 'to' => sprintf('%04d-%02d', $to['year'], $to['month'])];
    }

    protected static function monthShape(mixed $yyyyMm): ?array
    {
        return is_string($yyyyMm) && preg_match('/^(\d{4})-(\d{2})$/', $yyyyMm, $m)
            ? ['kind' => 'month', 'year' => (int) $m[1], 'month' => (int) $m[2]]
            : null;
    }

    protected static function validateMonth(array $period, int $year, Carbon $now): ?array
    {
        $month = $period['month'] ?? null;

        if (! is_numeric($month)) {
            return null;
        }
        $month = (int) $month;

        if ($month < 1 || $month > 12) {
            return null;
        }

        $target = Carbon::create($year, $month, 1, 0, 0, 0, 'Asia/Kuala_Lumpur');
        $monthsAhead = $now->copy()->startOfMonth()->diffInMonths($target, false);

        if ($monthsAhead < -1 || $monthsAhead > self::MAX_MONTHS_AHEAD) {
            return null;
        }

        return ['kind' => 'month', 'year' => $year, 'month' => $month];
    }

    protected static function validateQuarter(array $period, int $year, Carbon $now): ?array
    {
        $quarter = $period['quarter'] ?? null;

        if (! is_numeric($quarter)) {
            return null;
        }
        $quarter = (int) $quarter;

        if ($quarter < 1 || $quarter > 4) {
            return null;
        }

        $firstMonthOfQuarter = ($quarter - 1) * 3 + 1;
        $target = Carbon::create($year, $firstMonthOfQuarter, 1, 0, 0, 0, 'Asia/Kuala_Lumpur');
        $monthsAhead = $now->copy()->startOfMonth()->diffInMonths($target, false);

        if ($monthsAhead < -3 || $monthsAhead > self::MAX_MONTHS_AHEAD) {
            return null;
        }

        return ['kind' => 'quarter', 'year' => $year, 'quarter' => $quarter];
    }

    /**
     * A validated period's own [firstMonthsAhead, lastMonthsAhead]
     * window, 0-based ("this month" = 0) -- exactly the index range
     * WorkstationStatistics::seatReleaseForecast()'s own per-month array
     * uses, so the lookup can sum the right slice without reimplementing
     * any date maths of its own.
     */
    public static function monthsAheadRange(array $period, ?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now('Asia/Kuala_Lumpur');
        $thisMonth = $now->copy()->startOfMonth();
        [$from, $to] = self::firstAndLastMonth($period);

        return [(int) $thisMonth->diffInMonths($from, false), (int) $thisMonth->diffInMonths($to, false)];
    }

    /** "November 2026" / "Q1 2027" / "2027" / "November 2026 to February 2027" -- for the "Interpreted as" line and the Did-you-mean text. */
    public static function label(array $period): string
    {
        return match ($period['kind']) {
            'month' => Carbon::create($period['year'], $period['month'], 1)->format('F Y'),
            'quarter' => "Q{$period['quarter']} {$period['year']}",
            'year' => (string) $period['year'],
            'range' => (function () use ($period) {
                [$from, $to] = self::firstAndLastMonth($period);

                return $from->format('F Y').' to '.$to->format('F Y');
            })(),
            default => '',
        };
    }

    protected static function inferYearForMonth(Carbon $now, int $month): int
    {
        // A bare month name with no year means "the next occurrence of
        // that month" -- this year if it hasn't passed yet, else next
        // year. Never the past.
        return $month >= $now->month ? (int) $now->year : (int) $now->year + 1;
    }

    protected static function inferYearForQuarter(Carbon $now, int $quarter): int
    {
        $currentQuarter = (int) ceil($now->month / 3);

        return $quarter >= $currentQuarter ? (int) $now->year : (int) $now->year + 1;
    }
}
