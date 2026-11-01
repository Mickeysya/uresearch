<?php

namespace App\Modules\Chloe\Support;

/**
 * The page-number window of the ONE candidacy pagination bar (view: candidacy.wca._pagination).
 *
 * At most $slots entries (7 on desktop, 5 compact), always exactly $slots once there are more pages than slots; the first and last page
 * are always present; "…" (the string) marks a gap and is never next to another "…".
 *   12 pages, 7 slots:  page 1-4 -> 1 2 3 4 5 … 12   page 6 -> 1 … 5 6 7 … 12   page 9-12 -> 1 … 8 9 10 11 12
 */
final class CandidacyPagination
{
    public const ELLIPSIS = '…';

    public const PER_PAGE = 25;

    /** @return list<int|string> */
    public static function window(int $total, int $current, int $slots = 7): array
    {
        $current = max(1, min($current, max(1, $total)));

        if ($total <= $slots) {
            return $total < 1 ? [] : range(1, $total);
        }

        $edge = $slots - 2; // numbers shown next to the single "…" at the near edge

        if ($current <= $slots - 3) {
            return [...range(1, $edge), self::ELLIPSIS, $total];
        }

        if ($current >= $total - $slots + 4) {
            return [1, self::ELLIPSIS, ...range($total - $edge + 1, $total)];
        }

        // the middle: 1 … (current and its neighbours as far as the slots allow) … last
        $inner = $slots - 4;
        $start = $current - intdiv($inner - 1, 2);

        return [1, self::ELLIPSIS, ...range($start, $start + $inner - 1), self::ELLIPSIS, $total];
    }

    /** A page number from the URL: non-numeric or below 1 -> 1; beyond the last page -> the last page. */
    public static function page(mixed $raw, int $pages): int
    {
        $page = is_numeric($raw) ? (int) $raw : 1;

        return max(1, min($page, max(1, $pages)));
    }

    public static function pages(int $total, int $perPage = self::PER_PAGE): int
    {
        return max(1, (int) ceil($total / $perPage));
    }

    /** @return array{from:int,to:int} the 1-based first and last row of a page */
    public static function range(int $total, int $page, int $perPage = self::PER_PAGE): array
    {
        return $total === 0 ? ['from' => 0, 'to' => 0] : ['from' => ($page - 1) * $perPage + 1, 'to' => min($total, $page * $perPage)];
    }
}
