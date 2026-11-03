<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\DemoStudentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** The one pagination bar: Operations and every Approval Portal tab. */
class CandidacyPaginationTest extends TestCase
{
    use BuildsCandidacyWorld;
    use MakesUsers;
    use RefreshDatabase;

    protected int $n = 0;

    protected ?DemoStudentProfile $demo = null;

    /** Cheap rows: one demo student, closed states (so the one-open rule is not involved), a unique number each. */
    protected function many(int $count, string $status = 'approved', ?string $stage = null, array $extra = []): void
    {
        $this->demo ??= DemoStudentProfile::create(['matric_no' => '21019999', 'name' => 'Demo Many', 'email' => 'many@utp.edu.my', 'gender' => 'male', 'programme' => 'MSc X', 'programme_start_date' => '2025-01-01', 'candidacy_expiry_date' => '2027-06-01', 'status' => 'active']);

        foreach (range(1, $count) as $i) {
            $this->n++;
            // an open appeal needs its own student (one open appeal per student)
            $owner = in_array($status, CandidacyAppeal::OPEN_STATUSES, true)
                ? DemoStudentProfile::create(['matric_no' => (string) (21020000 + $this->n), 'name' => "Demo Open {$this->n}", 'email' => "open{$this->n}@utp.edu.my", 'gender' => 'male', 'programme' => 'MSc X', 'programme_start_date' => '2025-01-01', 'candidacy_expiry_date' => '2027-06-01', 'status' => 'active'])
                : $this->demo;
            CandidacyAppeal::create($extra + [
                'demo_profile_id' => $owner->id, 'appeal_no' => sprintf('SCA-21019999-%03d', $this->n), 'status' => $status, 'current_stage' => $stage, 'round' => 1,
                'department_id' => $this->profile($this->lecturer)->department_id, 'requested_months' => 3, 'submitted_at' => now()->subDays(3), 'stage_entered_at' => now()->subDay(),
                'supervisor_profile_id' => $this->profile($this->lecturer)->id, 'chair_profile_id' => $this->profile($this->chairUser)->id, 'dean_profile_id' => $this->profile($this->deanUser)->id,
                'approved_months' => $status === 'approved' ? 3 : null, 'final_outcome' => $status === 'approved' ? 'approved' : null,
            ]);
        }
    }

    protected function ops(array $query = [])
    {
        return $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.operations', $query));
    }

    /** The desktop bar's items as ['label' => href|null|'disabled'|'current'|'gap'] in order. */
    protected function bar(string $html): array
    {
        preg_match('~<nav class="cgs-pagination is-full".*?</nav>~s', $html, $nav);
        preg_match_all('~<(a|span) ([^>]*)>(.*?)</\1>~s', $nav[0] ?? '', $m, PREG_SET_ORDER);

        return array_map(function ($x) {
            $label = trim(html_entity_decode(strip_tags($x[3])));
            if ($x[1] === 'a' && preg_match('~href="([^"]*)"~', $x[2], $h)) {
                return [$label, 'link', html_entity_decode($h[1])];
            }

            return [$label, str_contains($x[2], 'is-current') ? 'current' : (str_contains($x[2], 'is-disabled') ? 'disabled' : 'gap'), null];
        }, $m);
    }

    public function test_page_one_has_a_disabled_prev_and_a_working_next_and_the_last_page_the_reverse(): void
    {
        $this->many(60);

        $first = $this->bar($this->ops()->assertOk()->getContent());
        $this->assertSame(['« Prev', 'disabled', null], $first[0]);
        $this->assertSame(['1', 'current', null], $first[1]);
        $this->assertSame('link', $first[2][1]);
        $this->assertSame('Next »', end($first)[0]);
        $this->assertSame('link', end($first)[1]);

        $last = $this->bar($this->ops(['page' => 3])->getContent());
        $this->assertSame('link', $last[0][1]);
        $this->assertSame(['Next »', 'disabled', null], end($last));
        $this->assertSame('3', $last[3][0]);
        $this->assertSame('current', $last[3][1]);

        $html = $this->ops()->getContent();
        $this->assertStringContainsString('aria-disabled="true"', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertDoesNotMatchRegularExpression('~<a[^>]*>\s*(«|&laquo;) Prev~', $html); // page 1: Prev is not a link
    }

    public function test_the_showing_line_and_the_single_page_and_empty_cases(): void
    {
        $this->many(39);
        $this->assertStringContainsString('Showing 26 to 39 of 39 appeals', $this->ops(['page' => 2])->getContent());
        $this->assertStringContainsString('Showing 1 to 25 of 39 appeals', $this->ops()->getContent());

        $one = $this->ops(['q' => 'SCA-21019999-001'])->getContent();   // exactly one row
        $this->assertStringContainsString('Showing 1 to 1 of 1 appeal', $one);
        $this->assertStringNotContainsString('class="cgs-pagination', $one);

        $few = $this->ops(['q' => 'SCA-21019999-00'])->getContent();    // 9 rows: one page
        $this->assertStringContainsString('Showing 1 to 9 of 9 appeals', $few);
        $this->assertStringNotContainsString('class="cgs-pagination', $few);

        $none = $this->ops(['q' => 'no-such-thing'])->getContent();
        $this->assertStringContainsString('No appeals found', $none);
        $this->assertStringNotContainsString('class="cgs-pagination', $none);
        $this->assertStringNotContainsString('Showing', $none);
    }

    public function test_page_links_keep_filters_tab_and_card_in_the_url(): void
    {
        $this->many(60);
        $query = ['group' => 'approved', 'q' => 'SCA-21019999', 'department' => $this->profile($this->lecturer)->department_id, 'months' => 3];

        foreach ($this->bar($this->ops($query)->getContent()) as [$label, $kind, $href]) {
            if ($kind === 'link') {
                parse_str(parse_url($href, PHP_URL_QUERY), $q);
                foreach ($query as $key => $value) {
                    $this->assertSame((string) $value, $q[$key] ?? null, "{$label} keeps {$key}");
                }
            }
        }
    }

    public function test_a_page_beyond_the_last_clamps_and_bad_numbers_fall_back_to_one(): void
    {
        $this->many(60);

        $this->assertStringContainsString('Showing 51 to 60 of 60 appeals', $this->ops(['page' => 99])->assertOk()->getContent());
        foreach (['0', 'abc', '-4', '', '1.5x'] as $bad) {
            $this->assertStringContainsString('Showing 1 to 25 of 60 appeals', $this->ops(['page' => $bad])->assertOk()->getContent(), "page={$bad}");
        }
    }

    public function test_a_long_list_shows_the_seven_slot_window_and_a_compact_five_slot_one(): void
    {
        $this->many(300); // 12 pages

        $labels = fn (string $html) => array_map(fn ($b) => $b[0], array_slice($this->bar($html), 1, -1));
        $this->assertSame(['1', '2', '3', '4', '5', '…', '12'], $labels($this->ops(['page' => 1])->getContent()));
        $this->assertSame(['1', '…', '5', '6', '7', '…', '12'], $labels($this->ops(['page' => 6])->getContent()));
        $this->assertSame(['1', '…', '8', '9', '10', '11', '12'], $labels($this->ops(['page' => 12])->getContent()));

        $html = $this->ops(['page' => 6])->getContent();
        $this->assertStringContainsString('cgs-pagination is-compact', $html);
        preg_match('~<nav class="cgs-pagination is-compact".*?</nav>~s', $html, $c);
        $this->assertSame(5, preg_match_all('~<(a|span) [^>]*>(?!&laquo;|Next)[^<]*</\1>~', $c[0]));
    }

    public function test_the_live_region_keeps_the_page_and_clamps_when_rows_disappear(): void
    {
        $this->many(60);
        $region = fn (int $page) => $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.operations.region', ['page' => $page]));

        $this->assertStringContainsString('Showing 26 to 50 of 60 appeals', $region(2)->assertOk()->getContent());
        CandidacyAppeal::where('appeal_no', '>', 'SCA-21019999-020')->delete(); // only 20 rows remain: page 2 no longer exists
        $html = $region(2)->assertOk()->getContent();
        $this->assertStringContainsString('Showing 1 to 20 of 20 appeals', $html);
        $this->assertStringNotContainsString('class="cgs-pagination', $html);
    }

    public function test_back_from_the_detail_page_returns_to_the_same_page_number(): void
    {
        $this->many(60);
        $html = html_entity_decode($this->ops(['group' => 'approved', 'page' => 2])->getContent());
        preg_match('~href="([^"]*/candidacy/appeals/\d+\?return=[^"]*)"~', $html, $m);

        $detail = html_entity_decode($this->actingAs($this->cgsUser)->get($m[1])->assertOk()->getContent());
        preg_match('~<a href="([^"]*)" class="page-header-back~', $detail, $b);

        $this->assertStringContainsString('/cgs/candidacy?group=approved&page=2', $b[1]);
    }

    public function test_the_portal_tabs_paginate_with_the_same_bar_and_keep_their_state(): void
    {
        $this->many(30, 'with_supervisor', 'supervisor');

        $p1 = $this->actingAs($this->lecturer)->get(route('candidacy.portal.queue'))->assertOk()->getContent();
        $this->assertStringContainsString('Showing 1 to 25 of 30 appeals', $p1);
        $this->assertSame(25, substr_count($p1, '>Review<'));

        $p2 = $this->actingAs($this->lecturer)->get(route('candidacy.portal.queue', ['page' => 2, 'q' => 'SCA-21019999']))->getContent();
        $this->assertStringContainsString('Showing 26 to 30 of 30 appeals', $p2);
        $bar = $this->bar($p2);
        $this->assertSame(['Next »', 'disabled', null], end($bar));
        $this->assertStringContainsString('q=SCA-21019999', $bar[0][2]); // Prev keeps the search
        $this->assertStringNotContainsString('page=2', $bar[0][2] ?? '');

        // changing the tab resets the page; a page beyond the last clamps
        $tabs = html_entity_decode($p2);
        preg_match('~href="([^"]*tab=ongoing[^"]*)"~', $tabs, $t);
        $this->assertStringNotContainsString('page=', $t[1]);
        $this->assertStringContainsString('Showing 26 to 30 of 30 appeals', $this->actingAs($this->lecturer)->get(route('candidacy.portal.queue', ['page' => 50]))->getContent());
        $this->assertStringContainsString('No appeals found', $this->actingAs($this->lecturer)->get(route('candidacy.portal.queue', ['tab' => 'history']))->getContent());
    }

    public function test_the_portal_live_region_keeps_the_page_and_the_detail_return_path_has_it(): void
    {
        $this->many(30, 'with_supervisor', 'supervisor');

        $region = html_entity_decode($this->actingAs($this->lecturer)->get(route('candidacy.portal.region', ['page' => 2]))->assertOk()->getContent());
        $this->assertStringContainsString('Showing 26 to 30 of 30 appeals', $region);
        $this->assertStringContainsString('return=/candidacy/portal?page=2', str_replace('%2F', '/', str_replace('%3F', '?', str_replace('%3D', '=', $region))));
    }
}
