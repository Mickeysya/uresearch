<?php

namespace App\Modules\Chloe\Support;

/**
 * Round 5 PART B.3/C. A pure PHP reference implementation of the exact
 * same filter/aggregation rules public/js/chloe-insights-aggregate.js
 * applies client-side -- used ONLY by tests, to cross-check that the JS
 * file's logic (which PHPUnit cannot execute) matches this one, never
 * called from the page itself. Keeping the two in sync by hand is the
 * point: a test comparing them catches the day one silently drifts from
 * the other.
 *
 * Every public method here is pure: given rows (and sometimes a filter
 * state), it returns a value -- no database, no I/O, no mutation.
 */
final class InsightsAggregator
{
    /**
     * Holder-level dimensions never match a row whose own value for that
     * dimension is null or "none" -- a seat with no current seat holder
     * (or one with no resolvable programme/expiry/stay/release/key) is
     * simply never "in" any selected category of that dimension, filtered
     * or not.
     */
    public const HOLDER_DIMENSIONS = ['programme', 'key', 'expiry', 'stay', 'release', 'source', 'due'];

    /**
     * True when $row satisfies every dimension's filter in $filters
     * EXCEPT $excludeDim (if given) -- an empty set for a dimension means
     * "no filter on it", which always matches. $filters maps a dimension
     * name to an array of selected string values.
     *
     * @param  array<string, string>  $row
     * @param  array<string, array<int, string>>  $filters
     */
    public static function rowMatches(array $row, array $filters, ?string $excludeDim = null): bool
    {
        foreach ($filters as $dim => $selected) {
            if ($dim === $excludeDim || $selected === []) {
                continue;
            }

            $rowValue = self::rowValueForDim($row, $dim);

            if (in_array($dim, self::HOLDER_DIMENSIONS, true) && ($rowValue === null || $rowValue === 'none')) {
                return false;
            }

            if (! in_array($rowValue, $selected, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Dimension name -> the row field it reads; 'room' reads 'room',
     * 'release' reads 'release_month', 'source' reads 'release_source',
     * 'due' reads 'return_due'. 'notice' is not one of PART 3.1's 11
     * named dimensions -- added so the "seats in a termination notice"
     * KPI (Part 4.4 #3) can be clicked to highlight across every chart
     * the same way any other category can; it is a plain boolean on
     * every row (never null/none), so it is never a HOLDER_DIMENSIONS
     * member.
     */
    public static function rowValueForDim(array $row, string $dim): ?string
    {
        return match ($dim) {
            'release' => $row['release_month'] ?? null,
            'source' => $row['release_source'] ?? null,
            'due' => $row['return_due'] ?? null,
            'notice' => ($row['notice'] ?? false) ? 'true' : 'false',
            default => $row[$dim] ?? null,
        };
    }

    /**
     * context[c] = count of $rows with value c for dimension $dim, with
     * NO filters applied at all (the unfiltered population) -- the fixed
     * axis-scale source, never changes while filtering.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public static function context(array $rows, string $dim): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $value = self::rowValueForDim($row, $dim);

            if (in_array($dim, self::HOLDER_DIMENSIONS, true) && ($value === null || $value === 'none')) {
                continue;
            }

            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * value[c] = count of $rows with value c for dimension $dim that
     * ALSO satisfy every OTHER dimension's filter (rowMatches($row, $filters, $dim)).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, array<int, string>>  $filters
     * @return array<string, int>
     */
    public static function value(array $rows, string $dim, array $filters): array
    {
        $counts = [];

        foreach ($rows as $row) {
            if (! self::rowMatches($row, $filters, $dim)) {
                continue;
            }

            $value = self::rowValueForDim($row, $dim);

            if (in_array($dim, self::HOLDER_DIMENSIONS, true) && ($value === null || $value === 'none')) {
                continue;
            }

            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return array<int, array<string, mixed>> every row satisfying every dimension's filter (no exclusion) */
    public static function filteredRows(array $rows, array $filters): array
    {
        return array_values(array_filter($rows, fn ($row) => self::rowMatches($row, $filters)));
    }

    /**
     * Round 6 PART 3.2: highlighted[c] = count of $rows (already the
     * current FILTER population) whose value for $dim is c AND which
     * satisfy the FULL highlight selection (every highlight dimension,
     * $dim itself included -- unlike value(), nothing is excluded). An
     * empty $highlight matches every row, so highlighted[c] === the
     * plain per-category count of $rows, which is exactly "looks fully
     * solid" / "drawn in full colour with the total label".
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, array<int, string>>  $highlight
     * @return array<string, int>
     */
    public static function highlightCounts(array $rows, string $dim, array $highlight): array
    {
        $counts = [];

        foreach ($rows as $row) {
            if (! self::rowMatches($row, $highlight)) {
                continue;
            }

            $value = self::rowValueForDim($row, $dim);

            if (in_array($dim, self::HOLDER_DIMENSIONS, true) && ($value === null || $value === 'none')) {
                continue;
            }

            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        return $counts;
    }

    /** True when any of the five HOLDER_DIMENSIONS has a non-empty selection in $dims. */
    public static function hasHolderDimension(array $dims): bool
    {
        foreach (self::HOLDER_DIMENSIONS as $dim) {
            if (! empty($dims[$dim])) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Showing X of Y seat holders" (a holder-level dimension is part of
     * the FILTER or the HIGHLIGHT) or "Showing X of Y seats" -- X is the
     * count of $populationRows (already filter-matched) that ALSO match
     * $highlight (every row, when $highlight is empty); Y is
     * count($populationRows) itself (the filtered population is already
     * scoped to seat holders by the filter dropdowns when relevant, so
     * there is no separate "every seat" count to fall back to here).
     *
     * @param  array<int, array<string, mixed>>  $populationRows
     * @param  array<string, array<int, string>>  $filters
     * @param  array<string, array<int, string>>  $highlight
     */
    public static function summaryCounts(array $populationRows, array $filters, array $highlight): array
    {
        $hasHolderDim = self::hasHolderDimension($filters) || self::hasHolderDimension($highlight);

        $matching = count(self::filteredRows($populationRows, $highlight));
        $total = count($populationRows);

        return ['matching' => $matching, 'total' => $total, 'unit' => $hasHolderDim ? 'seat holders' : 'seats'];
    }

    /**
     * Round 6 PART 3.7/5.1: every month from $nowMonth (format "Y-m") to
     * the last month with an actual release, capped at $maxMonths;
     * releases further out than that are summed into one trailing
     * "later" bucket instead of their own month. $filters is the
     * dropdown-filtered POPULATION's filter set (every holder-level
     * dimension included); $roomScopeFilters is block/room/gender ONLY,
     * matching PART 5.1's own rule that "available now" is scoped by
     * room filters alone, never by programme/key.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{months: array<int, array{month: string, from_candidacy: int, from_termination: int, releases: int, projected_available: int}>, later: array{from_candidacy: int, from_termination: int, releases: int, projected_available: int}|null}
     */
    public static function forecastMonths(array $rows, array $filters, array $roomScopeFilters, string $nowMonth, int $maxMonths = 36): array
    {
        $filteredForReleases = self::filteredRows($rows, $filters);

        $available = count(array_filter($rows, fn ($row) => $row['status'] === 'available' && self::rowMatches($row, $roomScopeFilters)));

        $releaseMonths = array_values(array_unique(array_filter(
            array_map(fn ($r) => $r['release_month'], $filteredForReleases),
            fn ($m) => $m !== 'none' && $m !== null
        )));
        sort($releaseMonths);

        $start = \DateTime::createFromFormat('Y-m-d', $nowMonth.'-01');
        $monthIndex = function (string $month) use ($start) {
            $d = \DateTime::createFromFormat('Y-m-d', $month.'-01');

            return ((int) $d->format('Y') - (int) $start->format('Y')) * 12 + ((int) $d->format('m') - (int) $start->format('m'));
        };

        $lastIndex = 0;
        foreach ($releaseMonths as $m) {
            $lastIndex = max($lastIndex, $monthIndex($m));
        }
        $spanMonths = min($lastIndex, $maxMonths);

        $monthKeys = [];
        for ($i = 0; $i <= $spanMonths; $i++) {
            $d = clone $start;
            $d->modify("+{$i} months");
            $monthKeys[] = $d->format('Y-m');
        }

        $cumulative = 0;
        $months = [];

        foreach ($monthKeys as $month) {
            $fromCandidacy = count(array_filter($filteredForReleases, fn ($r) => $r['release_month'] === $month && $r['release_source'] === 'candidacy'));
            $fromTermination = count(array_filter($filteredForReleases, fn ($r) => $r['release_month'] === $month && $r['release_source'] === 'termination'));
            $releases = $fromCandidacy + $fromTermination;
            $cumulative += $releases;

            $months[] = [
                'month' => $month,
                'from_candidacy' => $fromCandidacy,
                'from_termination' => $fromTermination,
                'releases' => $releases,
                'projected_available' => $available + $cumulative,
            ];
        }

        $later = null;
        $laterRows = array_filter($filteredForReleases, fn ($r) => $r['release_month'] !== 'none' && ! in_array($r['release_month'], $monthKeys, true));

        if (count($laterRows) > 0) {
            $fromCandidacy = count(array_filter($laterRows, fn ($r) => $r['release_source'] === 'candidacy'));
            $fromTermination = count($laterRows) - $fromCandidacy;
            $cumulative += count($laterRows);

            $later = [
                'from_candidacy' => $fromCandidacy,
                'from_termination' => $fromTermination,
                'releases' => count($laterRows),
                'projected_available' => $available + $cumulative,
            ];
        }

        return ['months' => $months, 'later' => $later];
    }

    /** Month span (inclusive) <= 24 -> 'month', else 'quarter' -- PART 3.7's own default-grain rule. */
    public static function defaultGrain(int $monthCount): string
    {
        return $monthCount <= 24 ? 'month' : 'quarter';
    }

    /**
     * Groups forecastMonths()'s own 'months' list into quarters or years
     * (or returns it unchanged for 'month'); each group's
     * projected_available is the LAST month in that group's own value
     * (a cumulative figure, so "at period end" is exactly the right one
     * to carry up), everything else summed across the group. Each group
     * also carries the literal 'months' (their Y-m keys) it was built
     * from, so a drill-down can re-slice the monthly series exactly.
     *
     * @param  array<int, array{month: string, from_candidacy: int, from_termination: int, releases: int, projected_available: int}>  $months
     * @return array<int, array{key: string, label: string, from_candidacy: int, from_termination: int, releases: int, projected_available: int, months: array<int, string>}>
     */
    public static function groupPeriods(array $months, string $grain): array
    {
        if ($grain === 'month') {
            return array_map(fn ($m) => [
                'key' => $m['month'],
                'label' => $m['month'],
                'from_candidacy' => $m['from_candidacy'],
                'from_termination' => $m['from_termination'],
                'releases' => $m['releases'],
                'projected_available' => $m['projected_available'],
                'months' => [$m['month']],
            ], $months);
        }

        $groups = [];
        $order = [];

        foreach ($months as $m) {
            [$year, $monthNum] = array_map('intval', explode('-', $m['month']));
            $key = $grain === 'year' ? (string) $year : $year.'-Q'.(int) ceil($monthNum / 3);

            if (! isset($groups[$key])) {
                $groups[$key] = ['key' => $key, 'label' => $key, 'from_candidacy' => 0, 'from_termination' => 0, 'releases' => 0, 'projected_available' => 0, 'months' => []];
                $order[] = $key;
            }

            $groups[$key]['from_candidacy'] += $m['from_candidacy'];
            $groups[$key]['from_termination'] += $m['from_termination'];
            $groups[$key]['releases'] += $m['releases'];
            $groups[$key]['projected_available'] = $m['projected_available'];
            $groups[$key]['months'][] = $m['month'];
        }

        return array_map(fn ($key) => $groups[$key], $order);
    }
}
