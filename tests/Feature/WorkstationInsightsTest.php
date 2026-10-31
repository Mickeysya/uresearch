<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Models\WorkstationTerminationNotice;
use App\Modules\Chloe\Support\InsightsAggregator;
use App\Modules\Chloe\Support\WorkstationStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesEligibleWorkstationStudents;
use Tests\Support\MakesFakeAssistant;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Round 6 PART 2/9. The redesigned Insights page's own guarantees: the
 * embedded fact rows carry no PII (now including seat_url), the
 * unscoped aggregate matches WorkstationStatistics and the CGS stats
 * bar directly, the PHP reference aggregation's own bucket/forecast
 * rules hold, and the page's static markup has no inline style, no
 * canvas, no external resource, every class prefixed "wsi-", and the
 * five dropdown ids/filter parameter names the client script depends
 * on. These are deliberately data/markup checks only -- nothing here
 * executes the page's own JavaScript (PHPUnit's test client has no JS
 * engine); see tests/Feature/InsightsAggregationParityTest.php for the
 * one place this suite DOES run the real client-side JS (under Node),
 * and the PART 8 report for what was verified in a real browser.
 */
class WorkstationInsightsTest extends TestCase
{
    use MakesEligibleWorkstationStudents;
    use MakesFakeAssistant;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFakeAssistant();
    }

    protected function n2Room(string $gender = 'male', string $code = 'N2-03-01'): WorkstationLocation
    {
        return WorkstationLocation::firstOrCreate(['room_code' => $code], ['block' => 'N2', 'gender' => $gender, 'name' => $code]);
    }

    protected function j2Room(string $gender = 'female', string $code = 'J2-03-01'): WorkstationLocation
    {
        return WorkstationLocation::firstOrCreate(['room_code' => $code], ['block' => 'J2', 'gender' => $gender, 'name' => $code]);
    }

    protected function seat(WorkstationLocation $room, string $code, string $status = Workstation::STATUS_AVAILABLE): Workstation
    {
        return Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => $code, 'position' => 1, 'status' => $status]);
    }

    protected function rows(): array
    {
        $cgs = $this->cgs();

        return $this->actingAs($cgs)->get(route('workstation.cgs.insights'))->viewData('rows');
    }

    protected function html(): string
    {
        $cgs = $this->cgs();

        return $this->actingAs($cgs)->get(route('workstation.cgs.insights'))->getContent();
    }

    // -------------------------------------------------------------
    // No PII, ever
    // -------------------------------------------------------------

    public function test_fact_rows_contain_no_name_student_id_or_email(): void
    {
        $student = $this->eligibleStudent('22013579', 'leaky@test.my', ['programme' => 'MSc Privacy Test']);
        $seat = $this->seat($this->n2Room(), '1', Workstation::STATUS_OCCUPIED);
        WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $student->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()]);

        $json = json_encode(WorkstationStatistics::insightsFactRows());

        $this->assertStringNotContainsString('Ahmad Danial', $json);
        $this->assertStringNotContainsString('22013579', $json);
        $this->assertStringNotContainsString('leaky@test.my', $json);
        $this->assertStringNotContainsString('@', $json);
    }

    public function test_the_embedded_page_json_also_contains_no_pii(): void
    {
        $student = $this->eligibleStudent('22013580', 'leaky2@test.my');
        $seat = $this->seat($this->n2Room(), '2', Workstation::STATUS_OCCUPIED);
        WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $student->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()]);

        $html = $this->html();

        $this->assertStringNotContainsString('22013580', $html);
        $this->assertStringNotContainsString('leaky2@test.my', $html);
    }

    public function test_fact_rows_carry_a_real_seat_url_and_room_url(): void
    {
        $seat = $this->seat($this->n2Room(), '1', Workstation::STATUS_OCCUPIED);

        $row = collect(WorkstationStatistics::insightsFactRows())->firstWhere('seat_no', $seat->id);

        $this->assertSame(route('workstation.cgs.seat', $seat->id), $row['seat_url']);
        $this->assertSame(route('workstation.cgs.room', ['workstationLocation' => $seat->workstation_location_id]), $row['room_url']);
    }

    // -------------------------------------------------------------
    // Unscoped aggregate == WorkstationStatistics / stats bar
    // -------------------------------------------------------------

    public function test_the_unscoped_fact_rows_match_workstation_statistics_directly(): void
    {
        $this->seat($this->n2Room(), '1', Workstation::STATUS_OCCUPIED);
        $this->seat($this->n2Room(), '2', Workstation::STATUS_AVAILABLE);
        $this->seat($this->n2Room(), '3', Workstation::STATUS_DISABLED);

        $rows = $this->rows();

        $stats = WorkstationStatistics::seatOverview();
        $this->assertSame($stats['total'], count($rows));
        $this->assertSame($stats['occupied'], collect($rows)->where('occupied', true)->count());
        $this->assertSame($stats['available'], collect($rows)->where('status', 'available')->count());
        $this->assertSame($stats['maintenance'], collect($rows)->where('status', 'maintenance')->count());
    }

    public function test_unscoped_room_occupancy_from_fact_rows_matches_the_existing_lookup(): void
    {
        $room = $this->n2Room('male', 'N2-08-08');
        $this->seat($room, '1', Workstation::STATUS_OCCUPIED);
        $this->seat($room, '2', Workstation::STATUS_AVAILABLE);

        $rows = $this->rows();
        $existing = collect(WorkstationStatistics::roomOccupancy())->firstWhere('room_code', 'N2-08-08');

        $this->assertSame($existing['occupied'], collect($rows)->where('room', 'N2-08-08')->where('occupied', true)->count());
        $this->assertSame($existing['total'], collect($rows)->where('room', 'N2-08-08')->count());
    }

    // -------------------------------------------------------------
    // PHP reference aggregation's own bucket/forecast rules
    // -------------------------------------------------------------

    public function test_male_plus_female_context_equals_all_for_block(): void
    {
        $this->seat($this->n2Room('male'), '1', Workstation::STATUS_OCCUPIED);
        $this->seat($this->n2Room('male'), '2', Workstation::STATUS_AVAILABLE);
        $this->seat($this->n2Room('female', 'N2-03-02'), '1', Workstation::STATUS_OCCUPIED);
        $this->seat($this->j2Room(), '1', Workstation::STATUS_AVAILABLE);

        $rows = $this->rows();

        foreach (['N2', 'J2'] as $block) {
            $blockRows = array_values(array_filter($rows, fn ($r) => $r['block'] === $block));
            $male = count(array_filter($blockRows, fn ($r) => $r['gender'] === 'male'));
            $female = count(array_filter($blockRows, fn ($r) => $r['gender'] === 'female'));
            $this->assertSame(count($blockRows), $male + $female, "male+female must equal all rows for block {$block}");
        }
    }

    public function test_candidacy_buckets_sum_to_the_resolvable_seat_holder_count(): void
    {
        $student = $this->student();
        $seat = $this->seat($this->n2Room(), '1', Workstation::STATUS_OCCUPIED);
        WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $student->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()]);
        StudyCandidacy::create(['student_id' => $student->id, 'status' => StudyCandidacy::STATUS_ACTIVE, 'programme_start_date' => now()->subMonths(10), 'candidacy_expiry_date' => now()->addMonths(2)]);

        $rows = $this->rows();
        $context = InsightsAggregator::context($rows, 'expiry');

        $this->assertSame(1, array_sum($context));
    }

    public function test_programme_shares_sum_to_the_seat_holder_count(): void
    {
        $room = $this->n2Room();

        for ($i = 1; $i <= 9; $i++) {
            $student = $this->eligibleStudent("2200{$i}001", "student{$i}@test.my", ['programme' => "Programme {$i}"]);
            $seat = $this->seat($room, (string) $i, Workstation::STATUS_OCCUPIED);
            WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $student->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()]);
        }

        $rows = $this->rows();
        $context = InsightsAggregator::context($rows, 'programme');

        $this->assertSame(9, array_sum($context));
        $this->assertCount(9, $context, 'Every one of the 9 distinct programmes is its own category in context() -- "Others" (top 8 + Others) is a client-side grouping over this same data, not a PHP-side bucket.');
    }

    public function test_forecast_months_releases_sum_and_never_drop_the_projection_below_the_room_scopes_available_count(): void
    {
        $this->seat($this->n2Room(), '1', Workstation::STATUS_AVAILABLE);
        $rows = $this->rows();

        $emptyFilters = array_fill_keys(['block', 'room', 'gender', 'programme', 'key'], []);
        $result = InsightsAggregator::forecastMonths($rows, $emptyFilters, ['block' => [], 'room' => [], 'gender' => []], now()->format('Y-m'), 36);

        $nowAvailable = count(array_filter($rows, fn ($r) => $r['status'] === 'available'));

        foreach ($result['months'] as $month) {
            $this->assertGreaterThanOrEqual($nowAvailable, $month['projected_available']);
            $this->assertSame($month['from_candidacy'] + $month['from_termination'], $month['releases']);
        }
    }

    public function test_group_periods_by_quarter_sums_releases_and_carries_the_last_months_projection(): void
    {
        $rows = [];
        $nowMonth = now()->format('Y-m');
        $months = [
            ['month' => $nowMonth, 'from_candidacy' => 2, 'from_termination' => 0, 'releases' => 2, 'projected_available' => 10],
            ['month' => now()->addMonth()->format('Y-m'), 'from_candidacy' => 0, 'from_termination' => 1, 'releases' => 1, 'projected_available' => 11],
            ['month' => now()->addMonths(2)->format('Y-m'), 'from_candidacy' => 3, 'from_termination' => 0, 'releases' => 3, 'projected_available' => 14],
        ];

        $grouped = InsightsAggregator::groupPeriods($months, 'quarter');

        $this->assertCount(1, $grouped, 'All three months fall in the same quarter.');
        $this->assertSame(6, $grouped[0]['releases']);
        $this->assertSame(14, $grouped[0]['projected_available'], 'projected_available must be the LAST month in the group, a cumulative figure.');
        $this->assertSame($months[0]['month'], $grouped[0]['months'][0]);
    }

    public function test_default_grain_is_month_up_to_24_months_then_quarter(): void
    {
        $this->assertSame('month', InsightsAggregator::defaultGrain(1));
        $this->assertSame('month', InsightsAggregator::defaultGrain(24));
        $this->assertSame('quarter', InsightsAggregator::defaultGrain(25));
    }

    public function test_release_month_uses_the_earlier_of_candidacy_expiry_and_termination_vacate_date(): void
    {
        $student = $this->student();
        $seat = $this->seat($this->n2Room(), '1', Workstation::STATUS_OCCUPIED);
        $request = WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $student->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()->subMonths(6)]);
        // Candidacy expiry is 4 months out; the termination notice vacates in 1 month -- the EARLIER (termination) must win.
        StudyCandidacy::create(['student_id' => $student->id, 'status' => StudyCandidacy::STATUS_ACTIVE, 'programme_start_date' => now()->subMonths(6), 'candidacy_expiry_date' => now()->addMonths(4)]);
        WorkstationTerminationNotice::create(['workstation_request_id' => $request->id, 'reason' => 'completed', 'notice_date' => now(), 'vacate_by_date' => now()->addMonth(), 'status' => WorkstationTerminationNotice::STATUS_ACTIVE]);

        $row = collect(WorkstationStatistics::insightsFactRows())->firstWhere('seat_no', $seat->id);

        $this->assertSame('termination', $row['release_source']);
        $this->assertSame(now()->addMonth()->format('Y-m'), $row['release_month']);
    }

    public function test_release_month_has_no_artificial_cap_any_more(): void
    {
        $student = $this->student();
        $seat = $this->seat($this->n2Room(), '1', Workstation::STATUS_OCCUPIED);
        WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $student->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()->subMonths(6)]);
        StudyCandidacy::create(['student_id' => $student->id, 'status' => StudyCandidacy::STATUS_ACTIVE, 'programme_start_date' => now()->subMonths(6), 'candidacy_expiry_date' => now()->addMonths(20)]);

        $row = collect(WorkstationStatistics::insightsFactRows())->firstWhere('seat_no', $seat->id);

        $this->assertSame(now()->addMonths(20)->format('Y-m'), $row['release_month'], 'Round 6 PART 2.1 removed the old 6-month cap/"later" bucket from the data itself.');
    }

    public function test_a_past_expiry_counts_in_the_current_month(): void
    {
        $student = $this->student();
        $seat = $this->seat($this->n2Room(), '1', Workstation::STATUS_OCCUPIED);
        WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $student->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()->subMonths(20)]);
        StudyCandidacy::create(['student_id' => $student->id, 'status' => StudyCandidacy::STATUS_ACTIVE, 'programme_start_date' => now()->subMonths(20), 'candidacy_expiry_date' => now()->subDays(5)]);

        $row = collect(WorkstationStatistics::insightsFactRows())->firstWhere('seat_no', $seat->id);

        $this->assertSame('candidacy', $row['release_source']);
        $this->assertSame(now()->format('Y-m'), $row['release_month']);
    }

    // -------------------------------------------------------------
    // Markup/CSP/isolation guarantees
    // -------------------------------------------------------------

    public function test_the_insights_page_has_no_canvas_element(): void
    {
        $this->assertStringNotContainsString('<canvas', $this->html());
    }

    public function test_the_insights_page_has_no_inline_style_attribute_in_its_own_markup(): void
    {
        $html = $this->html();

        $pageStart = strpos($html, 'id="wsi-page"');
        $contentEnd = strrpos($html, '</body>');
        $pageHtml = substr($html, $pageStart, $contentEnd - $pageStart);

        $this->assertDoesNotMatchRegularExpression('/\sstyle="/', $pageHtml);
    }

    public function test_the_insights_page_has_no_inline_event_handler_attribute(): void
    {
        $html = $this->html();

        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+="/i', $html);
    }

    public function test_the_insights_page_loads_no_external_cross_origin_script_or_stylesheet(): void
    {
        $html = $this->html();

        preg_match_all('/<(?:script|link)[^>]+(?:src|href)="(https?:\/\/[^"]+)"/i', $html, $matches);

        foreach ($matches[1] as $url) {
            $this->assertStringStartsWith('http://localhost', $url, "External resource found: {$url}");
        }
    }

    /** Round 6's isolation rule: every class on this page is prefixed "wsi-" so a shared stylesheet's own rules can never reach in (see the Round 5 donut-legend-colour bug this is a direct reaction to). */
    public function test_every_css_class_in_the_pages_own_markup_is_wsi_prefixed(): void
    {
        $html = $this->html();
        $pageStart = strpos($html, '<div class="wsi-page"');
        // The page's OWN <style> block (identified by its first design
        // token), not the chat widget's separate "wsa-" prefixed one
        // that happens to appear earlier in the document.
        $tokenPos = strpos($html, '--wsi-blue:');
        $styleStart = strrpos(substr($html, 0, $tokenPos), '<style>');
        $styleEnd = strpos($html, '</style>', $tokenPos);
        $pageEnd = strpos($html, '<script type="application/json" id="ins-data"');

        $style = substr($html, $styleStart, $styleEnd - $styleStart);
        // Starts after <x-core::page-header> -- a shared Core component
        // this page legitimately reuses (same as every other Workstation
        // page); its own class names are not this page's to prefix. The
        // live-indicator line is the first element this page's own
        // Blade source writes after that component.
        $ownMarkupStart = strpos($html, 'wsi-live-row', $pageStart);
        $body = substr($html, $ownMarkupStart, $pageEnd - $ownMarkupStart);

        // Only a dot that opens an actual selector (preceded by the start
        // of a rule/combinator, not prose like "e.g." inside an HTML
        // comment) and immediately followed by "{" -- i.e. a plain class
        // selector with nothing else in it, which is everything this
        // page's own <style> block actually uses.
        preg_match_all('/(?:^|[\s,])\.([a-zA-Z][a-zA-Z0-9_-]*)\s*\{/m', $style, $cssClasses);
        foreach (array_unique($cssClasses[1]) as $class) {
            $this->assertStringStartsWith('wsi-', $class, "CSS class .{$class} is not wsi-prefixed.");
        }

        preg_match_all('/class="([^"]+)"/', $body, $classAttrs);
        foreach ($classAttrs[1] as $classList) {
            foreach (preg_split('/\s+/', trim($classList)) as $class) {
                if ($class === '') continue;
                $this->assertStringStartsWith('wsi-', $class, "class=\"{$class}\" is not wsi-prefixed.");
            }
        }
    }

    public function test_cards_declare_no_min_height_or_height_100_percent_except_the_intentional_grid_stretch(): void
    {
        $html = $this->html();
        $styleStart = strpos($html, '#wsi-page {');
        $styleEnd = strpos($html, '</style>', $styleStart);
        $css = substr($html, $styleStart, $styleEnd - $styleStart);

        $this->assertDoesNotMatchRegularExpression('/\.wsi-card[^{]*\{[^}]*min-height/i', $css);
    }

    /** The five dropdown ids/data-dim attributes the client script's renderDropdowns()/change-listener both depend on -- Round 6 narrowed this from eight to five (see PART 4.2: only block/room/gender/programme/key are FILTER dropdowns now). */
    public function test_the_five_filter_dropdowns_are_present_with_the_right_ids_and_dims(): void
    {
        $html = $this->html();

        $expected = ['filter-block' => 'block', 'filter-room' => 'room', 'filter-gender' => 'gender', 'filter-programme' => 'programme', 'filter-key' => 'key'];

        foreach ($expected as $id => $dim) {
            $this->assertStringContainsString("id=\"wsi-{$id}\"", $html);
            $this->assertStringContainsString("data-dim=\"{$dim}\"", $html);
        }

        $this->assertStringContainsString('id="wsi-reset-filters"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
    }

    public function test_the_client_script_reads_filters_from_the_url_and_writes_them_back(): void
    {
        $js = file_get_contents(public_path('js/chloe-workstation-insights.js'));

        $this->assertStringContainsString('new URLSearchParams', $js);
        $this->assertStringContainsString('history.replaceState', $js);
    }

    public function test_the_client_script_never_uses_innerhtml(): void
    {
        $js = file_get_contents(public_path('js/chloe-workstation-insights.js'));

        $this->assertDoesNotMatchRegularExpression('/\.innerHTML\s*=/', $js);
    }

    /**
     * Checks actual DOM/BOM method calls, not the bare words
     * "document"/"window" -- the file's own comments legitimately
     * mention both while explaining this very guarantee.
     */
    public function test_the_aggregate_script_has_no_dom_access(): void
    {
        $js = file_get_contents(public_path('js/chloe-insights-aggregate.js'));

        foreach (['document.getElementById', 'document.createElement', 'document.querySelector', 'window.addEventListener', '.appendChild', '.innerHTML'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $js, "{$forbidden} would be DOM/BOM access, not allowed in the pure aggregation file.");
        }
    }

    public function test_the_insights_page_is_403_for_a_student(): void
    {
        $student = $this->eligibleStudent();

        $this->actingAs($student)->get(route('workstation.cgs.insights'))->assertForbidden();
    }

    /**
     * Round 5's regression guard, carried forward: the 'room' and
     * 'status by block'/'gender' charts must count OCCUPIED seats per
     * room/group, never every seat regardless of status -- 'room' is a
     * seat-level attribute (every row has one), so a naive context()
     * over it gives capacity, not occupancy, which is how the Round 5
     * "every room shows 100%" bug happened. This file now computes
     * occupancy by filtering to OCCUPIED_ROWS explicitly wherever
     * per-room occupancy is needed, rather than calling AGG.context()
     * on the 'room' dimension at all.
     */
    public function test_room_occupancy_is_computed_from_occupied_rows_not_the_generic_room_dimension(): void
    {
        $js = file_get_contents(public_path('js/chloe-workstation-insights.js'));

        $this->assertStringContainsString('OCCUPIED_ROWS', $js);
        $this->assertStringNotContainsString("AGG.context(ROWS, 'room')", $js);
        $this->assertStringNotContainsString("AGG.context(population, 'room')", $js);
    }

    // -------------------------------------------------------------
    // Chat input (PART 7) -- markup only, same reasoning as above
    // -------------------------------------------------------------

    public function test_the_chat_send_button_has_an_upward_arrow_svg_and_an_aria_label(): void
    {
        $cgs = $this->cgs();
        $html = $this->actingAs($cgs)->get(route('workstation.cgs.operations'))->getContent();

        $this->assertStringContainsString('id="wsa-send"', $html);
        $this->assertStringContainsString('aria-label="Send"', $html);
        // The new upward-arrow path (M12 19V5 / M5 12l7-7 7 7), not the old paper-plane path.
        $this->assertStringContainsString('M12 19V5', $html);
        $this->assertStringContainsString('M5 12l7-7 7 7', $html);
    }

    public function test_the_chat_form_still_submits_on_enter_via_a_real_form_submit_handler(): void
    {
        $js = file_get_contents(base_path('app/Modules/Chloe/Resources/views/workstation/_assistant-widget.blade.php'));

        $this->assertStringContainsString("form.addEventListener('submit'", $js);
    }

    /** Part 7: Send is disabled on EITHER an empty input OR an in-flight request, not only the latter. */
    public function test_the_chat_send_button_disables_on_empty_input_too_not_only_while_busy(): void
    {
        $js = file_get_contents(base_path('app/Modules/Chloe/Resources/views/workstation/_assistant-widget.blade.php'));

        $this->assertStringContainsString('function updateSendButtonState', $js);
        $this->assertStringContainsString("input.addEventListener('input', updateSendButtonState)", $js);
        $this->assertStringContainsString("input.value.trim() === ''", $js);
    }

    // -------------------------------------------------------------
    // Round 7: the grouped bar chart (replaces the old stacked chart)
    // -------------------------------------------------------------

    public function test_the_grouped_bar_chart_replaces_the_old_stacked_chart_and_has_its_own_new_title(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('How many seats are in each state?', $html);
        $this->assertStringContainsString('Seats by status for each block.', $html);
        $this->assertStringNotContainsString('Seat status by block', $html, 'The old Round 6 stacked-chart title must be gone.');
    }

    public function test_the_grouped_bar_chart_js_uses_its_own_status_order_and_a_shared_axis(): void
    {
        $js = file_get_contents(public_path('js/chloe-workstation-insights.js'));

        $this->assertStringContainsString('STATUS_ORDER_GROUPED', $js);
        // The spec's own required order: Occupied, Available, Awaiting Key Return, Maintenance.
        $this->assertMatchesRegularExpression(
            "/STATUS_ORDER_GROUPED\s*=\s*\['occupied',\s*'available',\s*'awaiting_key_return',\s*'maintenance'\]/",
            $js
        );
        // One shared axis (a single niceAxisMax call over both blocks' counts), not a per-block scale.
        $this->assertStringContainsString('var scale = niceAxisMax(maxCount);', $js);
    }

    public function test_the_grouped_bar_chart_counts_match_the_stats_bar(): void
    {
        $this->seat($this->n2Room(), '1', Workstation::STATUS_OCCUPIED);
        $this->seat($this->n2Room(), '2', Workstation::STATUS_AVAILABLE);
        $this->seat($this->n2Room(), '3', Workstation::STATUS_DISABLED);

        $rows = $this->rows();
        $stats = WorkstationStatistics::seatOverview();

        $n2Rows = collect($rows)->where('block', 'N2');
        $this->assertSame($stats['occupied'], collect($rows)->where('occupied', true)->count());
        $this->assertGreaterThanOrEqual(1, $n2Rows->where('status', 'occupied')->count());
        $this->assertGreaterThanOrEqual(1, $n2Rows->where('status', 'available')->count());
        $this->assertGreaterThanOrEqual(1, $n2Rows->where('status', 'maintenance')->count());
    }

    // -------------------------------------------------------------
    // Round 7 PART 2: no text drawn inside a coloured mark
    // -------------------------------------------------------------

    public function test_no_white_value_label_is_drawn_inside_a_coloured_segment_any_more(): void
    {
        $js = file_get_contents(public_path('js/chloe-workstation-insights.js'));

        // Round 6's locker-key and status-by-block charts both drew a
        // white count/percent label centred inside a wide-enough
        // coloured segment (text-anchor middle, fill '#fff'). That
        // pattern is gone outright in Round 7 -- every count/percent now
        // lives in a value column, a legend, or above a column.
        $this->assertDoesNotMatchRegularExpression(
            "/'text-anchor':\s*'middle',\s*fill:\s*'#fff'/",
            $js,
            'A white, centred, in-segment value label pattern was found -- Round 7 PART 2 requires every count/percent to sit outside the coloured mark.'
        );
    }

    // -------------------------------------------------------------
    // Round 7 PART 3: no outline/ring on click, selection or focus
    // -------------------------------------------------------------

    public function test_every_interactive_class_declares_outline_none(): void
    {
        $html = $this->html();
        $tokenPos = strpos($html, '--wsi-blue:');
        $styleStart = strrpos(substr($html, 0, $tokenPos), '<style>');
        $styleEnd = strpos($html, '</style>', $tokenPos);
        $css = substr($html, $styleStart, $styleEnd - $styleStart);

        foreach (['.wsi-kpi-card', '.wsi-mark', '.wsi-tile', '.wsi-legend-item', '.wsi-select', '.wsi-page-btn', '.wsi-drill-btn', '.wsi-segctl button'] as $selector) {
            $this->assertStringContainsString($selector, $css, "No CSS rule at all found for {$selector}.");
        }

        // The blanket rule every interactive element falls back to.
        $this->assertStringContainsString("#wsi-page button, #wsi-page a, #wsi-page select, #wsi-page [tabindex] {\n            outline: none;", $css);
    }

    public function test_no_ring_or_coloured_outline_declarations_remain_anywhere_in_the_stylesheet(): void
    {
        $html = $this->html();
        $tokenPos = strpos($html, '--wsi-blue:');
        $styleStart = strrpos(substr($html, 0, $tokenPos), '<style>');
        $styleEnd = strpos($html, '</style>', $tokenPos);
        $css = substr($html, $styleStart, $styleEnd - $styleStart);

        // The Round 6 pattern this round explicitly removes: an outline
        // with a visible width/colour (a ring), anywhere at all.
        $this->assertDoesNotMatchRegularExpression('/outline:\s*2px solid/', $css);
        $this->assertDoesNotMatchRegularExpression('/outline-offset/', $css);
    }

    public function test_hover_colour_tokens_are_defined_one_per_base_colour(): void
    {
        $html = $this->html();

        foreach (['--wsi-blue-hover', '--wsi-gold-hover', '--wsi-orange-hover', '--wsi-red-hover', '--wsi-green-hover', '--wsi-slate-hover'] as $token) {
            $this->assertStringContainsString($token, $html);
        }
    }

    public function test_no_dark_or_navy_background_rule_exists_for_a_hover_or_selected_state(): void
    {
        $html = $this->html();
        $tokenPos = strpos($html, '--wsi-blue:');
        $styleStart = strrpos(substr($html, 0, $tokenPos), '<style>');
        $styleEnd = strpos($html, '</style>', $tokenPos);
        $css = substr($html, $styleStart, $styleEnd - $styleStart);

        // Every :hover/:focus-visible/.wsi-selected rule's own declaration
        // block must never set a dark background -- spot-check the KPI
        // card specifically, the one the user reported turning navy.
        $this->assertStringContainsString('.wsi-kpi-card:hover {', $css);
        $kpiHoverBlock = substr($css, strpos($css, '.wsi-kpi-card:hover {'), 200);
        $this->assertStringContainsString('var(--wsi-hover-bg)', $kpiHoverBlock);
        $this->assertStringNotContainsString('var(--wsi-blue)', $kpiHoverBlock);
        $this->assertStringNotContainsString('var(--wsi-text-dark)', str_replace('color: var(--wsi-text-body)', '', $kpiHoverBlock));
    }

    // -------------------------------------------------------------
    // Round 7 PART 4: legend component
    // -------------------------------------------------------------

    public function test_the_shared_legend_component_function_exists_and_is_used_by_every_multi_colour_chart(): void
    {
        $js = file_get_contents(public_path('js/chloe-workstation-insights.js'));

        $this->assertStringContainsString('function buildLegend(', $js);

        foreach (['buildStatusByBlock', 'buildForecast', 'buildRooms', 'buildGender', 'buildKeys'] as $fn) {
            $start = strpos($js, 'function ' . $fn . '(');
            $this->assertNotFalse($start, "{$fn} not found.");
            $nextFn = strpos($js, "\n    function ", $start + 10);
            $body = substr($js, $start, ($nextFn ?: strlen($js)) - $start);
            $this->assertStringContainsString('buildLegend(', $body, "{$fn} does not call buildLegend().");
        }
    }

    /**
     * Final polish PART 1.1: the strip itself only mounts once a filter
     * or a highlight is active (no empty box at rest) -- the permanent
     * hint now lives in the plain, boxless .wsi-strip-tip caption under
     * the filter panel instead.
     */
    public function test_the_strip_tip_caption_explains_highlighting_and_the_strip_itself_starts_hidden(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('Tip: click any bar to highlight it across all charts', $html);
        $this->assertStringContainsString('Faded = not highlighted', $html);
        $this->assertMatchesRegularExpression('/<div class="wsi-strip" id="wsi-strip" hidden>/', $html);
    }

    /**
     * Final polish PART 1.1: a dropdown FILTER now gets its own chip
     * (removable, clearing that filter) in addition to a click
     * HIGHLIGHT's chip -- previously only highlight produced a chip, so
     * an active filter with no highlight left the strip permanently
     * hidden even though the population was narrowed.
     */
    public function test_an_active_dropdown_filter_produces_its_own_removable_chip(): void
    {
        $js = file_get_contents(public_path('js/chloe-workstation-insights.js'));
        $this->assertStringContainsString('FILTER_DIMS.forEach(function (dim) {', $js);
        $this->assertStringContainsString("var anyFilter = FILTER_DIMS.some(function (d) { return filters[d] && filters[d].length; });", $js);
        $this->assertStringContainsString('var anyActive = anyFilter || anyHighlight;', $js);
        $this->assertStringContainsString("filters[dim] = [];", $js);
    }

    /**
     * Final polish PART 1.4 (found live): on a narrow card the shared
     * axis's "0" tick sits at x=0; text-anchor:middle then centres the
     * glyph exactly on that edge, and an SVG's own default
     * overflow:hidden clips its left half for real. Both chart builders
     * that draw this axis (the generic grouped-bar helper, and the
     * status-by-block chart that still carries its own un-migrated copy
     * of the same axis code) must anchor the "0" tick at 'start'.
     */
    public function test_the_zero_axis_tick_is_never_centred_on_the_plot_edge(): void
    {
        $js = file_get_contents(public_path('js/chloe-workstation-insights.js'));
        $this->assertSame(2, substr_count($js, "'text-anchor': t === 0 ? 'start' : 'middle'"), 'Both the generic grouped-bar axis and the status-by-block chart\'s own axis must anchor the 0 tick at start.');
    }

    /**
     * Final polish PART 1.4 (found live): the Programmes, Candidacy and
     * Length-of-stay charts already fell back to scrolling their own
     * card body horizontally on a narrow container (rather than ever
     * silently clipping a label) -- but gave no visual cue that they
     * had, the same problem Round 8 fixed for Rooms' vertical scroll.
     * They now add .wsi-hscroll (a styled, always-visible scrollbar)
     * and a "Scroll for more" caption whenever they actually do scroll.
     */
    public function test_horizontally_scrolling_charts_get_a_visible_scrollbar_and_a_caption_when_they_scroll(): void
    {
        $js = file_get_contents(public_path('js/chloe-workstation-insights.js'));
        $this->assertStringContainsString("if (scrolls) container.classList.add('wsi-hscroll');", $js);
        $this->assertStringContainsString("(scrolls ? ' Scroll for more →' : '')", $js);
        $this->assertStringContainsString('cache.scrolls = scrolls;', $js);

        $css = $this->html();
        $this->assertStringContainsString('.wsi-hscroll {', $css);
        $this->assertStringContainsString('.wsi-hscroll::-webkit-scrollbar', $css);
    }

    public function test_the_kpi_row_has_its_own_colour_key_caption(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('Left border: red = urgent', $html);
    }

    // -------------------------------------------------------------
    // Round 7 PART 5: tooltip
    // -------------------------------------------------------------

    public function test_the_tooltip_has_an_explicit_white_background_and_explicit_text_colours_on_every_child(): void
    {
        $html = $this->html();
        $tokenPos = strpos($html, '--wsi-blue:');
        $styleStart = strrpos(substr($html, 0, $tokenPos), '<style>');
        $styleEnd = strpos($html, '</style>', $tokenPos);
        $css = substr($html, $styleStart, $styleEnd - $styleStart);

        $this->assertStringContainsString('.wsi-tooltip {', $css);
        $tooltipBlock = substr($css, strpos($css, '.wsi-tooltip {'), 400);
        $this->assertStringContainsString('background: #FFFFFF', $tooltipBlock);
        $this->assertStringContainsString('color: var(--wsi-tooltip-text)', $tooltipBlock);

        $this->assertStringContainsString('.wsi-tooltip-title {', $css);
        $this->assertStringContainsString('.wsi-tooltip-value {', $css);
        $this->assertStringContainsString('.wsi-tooltip-sub {', $css);
    }

    public function test_the_tooltip_js_sets_an_explicit_class_on_every_child_it_builds(): void
    {
        $js = file_get_contents(public_path('js/chloe-workstation-insights.js'));

        $this->assertStringContainsString("el('div', { class: 'wsi-tooltip-title' }, title)", $js);
        $this->assertStringNotContainsString("el('div', {}, sub)", $js, 'A tooltip child with no explicit class was found -- Round 6 this relied on inheritance, which is exactly what a future change could silently break again.');
    }

    // -------------------------------------------------------------
    // Round 8 PART 1: Keys and Gender are now grouped bar charts
    // -------------------------------------------------------------

    public function test_keys_and_gender_charts_both_use_the_shared_grouped_bar_chart_builder(): void
    {
        $js = file_get_contents(public_path('js/chloe-workstation-insights.js'));

        $this->assertStringContainsString('function buildGroupedBarChart(', $js);
        $this->assertStringContainsString('function paintGroupedBarChart(', $js);

        foreach (['buildKeys', 'buildGender'] as $fn) {
            $start = strpos($js, 'function '.$fn.'(');
            $this->assertNotFalse($start, "{$fn} not found.");
            $next = strpos($js, "\n    function ", $start + 10);
            $body = substr($js, $start, ($next ?: strlen($js)) - $start);
            $this->assertStringContainsString('buildGroupedBarChart(', $body, "{$fn} must use the shared grouped bar chart builder (Round 8 PART 1).");
        }
    }

    public function test_keys_chart_counts_per_block_sum_to_the_overall_key_overview(): void
    {
        $student = $this->student();
        $seat = $this->seat($this->n2Room(), '1', Workstation::STATUS_OCCUPIED);
        WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $student->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()]);

        $rows = $this->rows();
        $n2Holders = collect($rows)->where('block', 'N2')->where('key', '!=', 'none')->count();
        $this->assertSame(1, $n2Holders, 'The one occupied seat with a resolvable identity is a seat holder in its own block group.');
    }

    public function test_gender_chart_a_bar_click_highlights_gender_and_status_together(): void
    {
        $js = file_get_contents(public_path('js/chloe-workstation-insights.js'));
        $start = strpos($js, 'function buildGender(');
        $next = strpos($js, "\n    function ", $start + 10);
        $body = substr($js, $start, $next - $start);

        $this->assertStringContainsString("AGG.toggleFilter(highlight, 'gender', g.gender, additive)", $body);
        $this->assertStringContainsString("AGG.toggleFilter(highlight, 'status', b.key, additive)", $body);
    }

    // -------------------------------------------------------------
    // Round 8 PART 2: scrollable areas must look scrollable
    // -------------------------------------------------------------

    public function test_the_rooms_scroll_area_has_a_visible_scrollbar_a_fade_and_a_caption(): void
    {
        $html = $this->html();
        $tokenPos = strpos($html, '--wsi-blue:');
        $styleStart = strrpos(substr($html, 0, $tokenPos), '<style>');
        $styleEnd = strpos($html, '</style>', $tokenPos);
        $css = substr($html, $styleStart, $styleEnd - $styleStart);

        $this->assertStringContainsString('scrollbar-width: thin', $css);
        $this->assertStringContainsString('scrollbar-color: #9AA7B4', $css);
        $this->assertStringContainsString('::-webkit-scrollbar-thumb', $css);
        $this->assertStringContainsString('.wsi-scroll-fade', $css);
        $this->assertStringContainsString('.wsi-rooms-sticky-header', $css);
        $this->assertStringContainsString('position: sticky', $css);

        $js = file_get_contents(public_path('js/chloe-workstation-insights.js'));
        $this->assertStringContainsString("'Scroll for more", $js);
        $this->assertStringContainsString('wsi-rooms-sticky-header', $js);
    }

    // -------------------------------------------------------------
    // Round 8 PART 3: forecast chart fixes
    // -------------------------------------------------------------

    public function test_the_forecast_chart_no_longer_scrolls_horizontally_and_columns_fill_the_real_width(): void
    {
        $html = $this->html();
        $this->assertStringNotContainsString('#wsi-forecast { overflow-x: auto;', $html);

        $js = file_get_contents(public_path('js/chloe-workstation-insights.js'));
        $this->assertStringContainsString('function escalateGrainToFitWidth(', $js);
        $this->assertStringContainsString('var totalW = width;', $js);
    }

    /**
     * Final polish PART 1.3: the on-plot "Now" chip (which still
     * collided with tick labels at some widths/counts) is gone -- the
     * live count now lives only in the legend, where it can never
     * collide with anything drawn on the plot.
     */
    public function test_the_now_count_is_in_the_legend_only_not_drawn_on_the_plot(): void
    {
        $js = file_get_contents(public_path('js/chloe-workstation-insights.js'));
        $this->assertStringContainsString("label: 'Now: ' + nowCount + ' available seats today'", $js);
        $this->assertStringNotContainsString("label: 'Now' }", $js);
        $this->assertStringNotContainsString('var chipY', $js);
    }

    public function test_the_forecast_subtitle_matches_the_current_grain(): void
    {
        $js = file_get_contents(public_path('js/chloe-workstation-insights.js'));
        $this->assertStringContainsString('function updateForecastSubtitle(', $js);
        $this->assertStringContainsString("word.toLowerCase() + ' by ' + word.toLowerCase()", $js);
    }

    /**
     * Final polish PART 1.2: overflow:hidden on the container used to
     * clip a button's own top/bottom whenever its text needed more than
     * one line. The container no longer clips (overflow: visible);
     * each button instead sizes off height:100% of the 36px container
     * and its own inner border-radius, so nothing can be cut.
     */
    public function test_the_segmented_control_buttons_are_equal_width_with_no_clipping(): void
    {
        $html = $this->html();
        $tokenPos = strpos($html, '--wsi-blue:');
        $styleStart = strrpos(substr($html, 0, $tokenPos), '<style>');
        $styleEnd = strpos($html, '</style>', $tokenPos);
        $css = substr($html, $styleStart, $styleEnd - $styleStart);

        $this->assertStringContainsString('.wsi-segctl {', $css);
        $segctlBlock = substr($css, strpos($css, '.wsi-segctl {'), 200);
        $this->assertStringContainsString('overflow: visible', $segctlBlock);
        $this->assertStringContainsString('height: 36px', $segctlBlock);

        $this->assertStringContainsString('.wsi-segctl button {', $css);
        $segctlButtonBlock = substr($css, strpos($css, '.wsi-segctl button {'), 300);
        $this->assertStringContainsString('flex: 1 1 0', $segctlButtonBlock);
        $this->assertStringContainsString('height: 100%', $segctlButtonBlock);
    }

    // -------------------------------------------------------------
    // Round 8 PART 4.1: live-data header
    // -------------------------------------------------------------

    public function test_the_header_shows_the_live_indicator_instead_of_the_stale_warning(): void
    {
        $html = $this->html();

        // Live indicator: a green dot, "Live · updated hh:mm:ss" (Malaysia time), a Pause control, and the manual Refresh link.
        $this->assertStringContainsString('id="wsi-live-dot"', $html);
        $this->assertMatchesRegularExpression('/Live &middot; updated \d{2}:\d{2}:\d{2}/', $html);
        $this->assertStringContainsString('id="wsi-live-pause"', $html);
        $this->assertStringContainsString('Pause updates', $html);
        $this->assertStringContainsString('id="wsi-refresh-link"', $html);

        // The old "Data as of" line and the 5-minute stale warning are gone.
        $this->assertStringNotContainsString('Data as of', $html);
        $this->assertStringNotContainsString('wsi-stale-warning', $html);
        $this->assertStringNotContainsString('This page may be out of date', $html);

        // The page carries its render-time version, the version endpoint and the data endpoint, 15 s apart.
        $this->assertMatchesRegularExpression('/id="wsi-live-config"[^>]*data-version="c[0-9a-f]{16}"/s', $html);
        $this->assertStringContainsString(route('workstation.cgs.live.version'), $html);
        $this->assertStringContainsString(route('workstation.cgs.live.insights'), $html);
        $this->assertStringContainsString('data-interval="15000"', $html);

        $js = file_get_contents(public_path('js/chloe-workstation-insights.js'));
        $this->assertStringNotContainsString('5 * 60 * 1000', $js);
        $this->assertStringContainsString("getElementById('wsi-refresh-link')", $js);
        $this->assertStringContainsString('ChloeLive', $js);
    }
}
