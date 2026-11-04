<?php

namespace Tests\Unit;

use App\Modules\Chloe\Support\CandidacyPagination as Pg;
use PHPUnit\Framework\TestCase;

/** The page-number window of the one candidacy pagination bar. */
class CandidacyPaginationWindowTest extends TestCase
{
    public function test_the_examples_from_the_brief(): void
    {
        $e = Pg::ELLIPSIS;

        foreach ([1, 2, 3, 4] as $c) {
            $this->assertSame([1, 2, 3, 4, 5, $e, 12], Pg::window(12, $c), "page {$c}");
        }
        $this->assertSame([1, $e, 4, 5, 6, $e, 12], Pg::window(12, 5));
        $this->assertSame([1, $e, 5, 6, 7, $e, 12], Pg::window(12, 6));
        foreach ([9, 10, 11, 12] as $c) {
            $this->assertSame([1, $e, 8, 9, 10, 11, 12], Pg::window(12, $c), "page {$c}");
        }
    }

    public function test_few_pages_show_every_number(): void
    {
        $this->assertSame([], Pg::window(0, 1));
        $this->assertSame([1], Pg::window(1, 1));
        $this->assertSame([1, 2], Pg::window(2, 2));
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], Pg::window(7, 4));
    }

    /** @return iterable<string, array{int,int,int}> */
    public static function cases(): iterable
    {
        foreach ([1, 2, 7, 8, 12, 30] as $total) {
            foreach (array_unique(array_filter([1, 2, intdiv($total + 1, 2), max(1, $total - 1), $total], fn ($c) => $c <= $total)) as $current) {
                foreach ([7, 5] as $slots) {
                    yield "{$total} pages, page {$current}, {$slots} slots" => [$total, $current, $slots];
                }
            }
        }
    }

    /** @dataProvider cases */
    public function test_the_window_rules_hold_everywhere(int $total, int $current, int $slots): void
    {
        $w = Pg::window($total, $current, $slots);

        $this->assertLessThanOrEqual($slots, count($w));
        if ($total > $slots) {
            $this->assertCount($slots, $w, 'exactly the slot count once there are more pages than slots');
        }
        $this->assertSame(1, $w[0], 'first page always present');
        $this->assertSame($total, end($w), 'last page always present');
        $this->assertContains($current, $w, 'the current page is shown');

        $numbers = array_values(array_filter($w, 'is_int'));
        $this->assertSame($numbers, array_values(array_unique($numbers)), 'no duplicate numbers');
        $this->assertSame($numbers, collect($numbers)->sort()->values()->all(), 'ascending');

        foreach ($w as $i => $slot) {
            if ($slot === Pg::ELLIPSIS) {
                $this->assertNotSame(Pg::ELLIPSIS, $w[$i + 1] ?? null, 'no two ellipses side by side');
                $this->assertGreaterThan(0, $i);
                $this->assertLessThan(count($w) - 1, $i);
                $this->assertGreaterThan($w[$i - 1] + 1, $w[$i + 1], 'an ellipsis hides at least one page');
            }
        }

        if ($total > $slots && $slots === 7 && $current > 1 && $current < $total) {
            $this->assertContains($current - 1, $w, 'a neighbour is shown (7 slots)');
            $this->assertContains($current + 1, $w, 'a neighbour is shown (7 slots)');
        }
    }

    public function test_page_numbers_from_the_url_are_normalised(): void
    {
        $this->assertSame(1, Pg::page('0', 5));
        $this->assertSame(1, Pg::page('-3', 5));
        $this->assertSame(1, Pg::page('abc', 5));
        $this->assertSame(1, Pg::page(null, 5));
        $this->assertSame(1, Pg::page('', 5));
        $this->assertSame(5, Pg::page('99', 5));
        $this->assertSame(3, Pg::page('3', 5));
        $this->assertSame(1, Pg::page('7', 0));
    }

    public function test_range_and_pages(): void
    {
        $this->assertSame(2, Pg::pages(39));
        $this->assertSame(1, Pg::pages(0));
        $this->assertSame(['from' => 26, 'to' => 39], Pg::range(39, 2));
        $this->assertSame(['from' => 0, 'to' => 0], Pg::range(0, 1));
    }
}
