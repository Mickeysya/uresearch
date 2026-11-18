<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Two report changes for CGS:
 *
 *  - Core's CGS "Reports and Analytics" page (/cgs/reports) links to the
 *    Travel, Claims and Publication Reports and Analytics, for whoever can
 *    open them and only the modules they may open (report/_cgs_reports_links,
 *    one include line in Core's placeholder view).
 *  - The Applications Reports and Analytics page lists, month by month, the
 *    applications submitted per module and in total, over the page's period
 *    filter, with months that had none at 0; the CSV export carries the
 *    same rows.
 */
class ReportLinksAndMonthlyCountsTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-12 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---- Links on /cgs/reports -------------------------------------------------------

    public function test_cgs_sees_all_four_report_links(): void
    {
        $html = $this->actingAs($this->cgs())->get(route('cgs.reports.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Travel, Claims and Publication reports', $html);
        $this->assertStringContainsString('href="'.route('norhanis.report').'"', $html);
        foreach (['travel', 'claims', 'publication'] as $slug) {
            $this->assertStringContainsString('href="'.route('norhanis.report', ['module' => $slug]).'"', $html, $slug);
        }
        $this->assertStringContainsString('Where Travel applications are waiting and how long each stage takes.', $html);
        // Core's own page is still there, unchanged.
        $this->assertStringContainsString('This is the Reports and Analytics page.', $html);
    }

    public function test_the_manager_sees_only_the_claims_report(): void
    {
        $html = $this->actingAs($this->user(Role::MANAGER_CGS, ['email' => 'manager@test.my']))
            ->get(route('cgs.reports.index'))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('norhanis.report', ['module' => 'claims']).'"', $html);
        $this->assertStringNotContainsString('href="'.route('norhanis.report', ['module' => 'travel']).'"', $html);
        $this->assertStringNotContainsString('href="'.route('norhanis.report', ['module' => 'publication']).'"', $html);
        // One module: no "all together" link.
        $this->assertStringNotContainsString('all together', $html);
    }

    public function test_cgs_staff_without_report_access_see_no_links(): void
    {
        // The Senior Executive CGS opens /cgs/reports but not these reports.
        $seniorExec = $this->seniorExec();
        $this->actingAs($seniorExec)->get(route('norhanis.report'))->assertForbidden();

        $this->actingAs($seniorExec)->get(route('cgs.reports.index'))
            ->assertOk()
            ->assertSee('This is the Reports and Analytics page.')
            ->assertDontSee('Travel, Claims and Publication reports')
            ->assertDontSee(route('norhanis.report'));
    }

    public function test_the_links_appear_on_the_reports_page_only(): void
    {
        $this->actingAs($this->cgs())->get(route('cgs.students.index'))
            ->assertOk()
            ->assertDontSee('Travel, Claims and Publication reports');
    }

    // ---- Reports and Analytics in the sidebar's Actions tree ---------------------------

    public function test_reports_and_analytics_is_inside_actions_and_opens_the_all_modules_report(): void
    {
        $html = $this->actingAs($this->cgs())->get(route('dashboard'))->assertOk()->getContent();

        $actions = substr($html, strpos($html, 'title="Actions"'));
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('norhanis.report'), '/').'"\s+class="nav-subitem\s*"\s+title="Reports and Analytics">Reports and Analytics<\/a>/', $actions);
        // No longer a top-level item as well.
        $this->assertDoesNotMatchRegularExpression('/class="nav-item\s*" title="Reports and Analytics"/', $html);
        // Each module's own report and list stay under that module.
        $this->assertStringContainsString('title="Travel: Travel Reports and Analytics"', $html);
        $this->assertStringContainsString('title="Travel: Travel Applications"', $html);
        $this->assertStringContainsString('title="Student Claims: Claims Reports and Analytics"', $html);
        $this->assertStringContainsString('title="Publication: Publication Reports and Analytics"', $html);
    }

    public function test_it_is_highlighted_on_the_all_modules_report_only(): void
    {
        $cgs = $this->cgs();

        $all = $this->actingAs($cgs)->get(route('norhanis.report'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<div class="nav-tree\s+open\s*">\s*<button type="button" class="nav-item nav-tree-trigger" title="Actions"/', $all);
        $this->assertMatchesRegularExpression('/class="nav-subitem\s+active\s*"\s+title="Reports and Analytics"/', $all);

        // One module's report highlights that module's link, not this one.
        $travel = $this->actingAs($cgs)->get(route('norhanis.report', ['module' => 'travel']))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/class="nav-subitem\s+active\s*"\s+title="Reports and Analytics"/', $travel);
    }

    public function test_without_report_access_it_still_opens_the_cgs_reports_page(): void
    {
        $html = $this->actingAs($this->seniorExec())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('cgs.reports.index'), '/').'"[^>]*title="Reports and Analytics"/s', $html);
        $this->assertStringNotContainsString('href="'.route('norhanis.report').'"', $html);
    }

    public function test_every_cgs_role_keeps_exactly_one_reports_and_analytics_link(): void
    {
        foreach (Role::cgsTeam() as $i => $role) {
            $html = $this->actingAs($this->user($role, ['email' => "cgs-team-{$i}@test.my"]))
                ->get(route('dashboard'))->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'title="Reports and Analytics"'), $role);
            $this->assertMatchesRegularExpression('/<a href="('.preg_quote(route('norhanis.report'), '/').'|'.preg_quote(route('cgs.reports.index'), '/').')"[^>]*title="Reports and Analytics"/s', $html, $role);
        }
    }

    // ---- Monthly counts ---------------------------------------------------------------

    public function test_the_monthly_table_counts_each_module_and_the_total_with_empty_months_at_zero(): void
    {
        $this->submitted('travel', '2026-08-04');
        $this->submitted('travel', '2026-08-20');
        $this->submitted('claims_student', '2026-08-21');
        // September and October: nothing.
        $this->submitted('publication', '2026-11-02');
        $this->submitted('claims_student', '2026-11-05');

        $html = $this->actingAs($this->cgs())->get(route('norhanis.report'))->assertOk()->getContent();

        $this->assertStringContainsString('<h4 style="margin-top: 20px;">Monthly counts</h4>', $html);
        // All time: from the first month with an application to the last.
        $this->assertSame(['2026-08', '2026-09', '2026-10', '2026-11'], $this->months($html));
        $this->assertSame(['travel' => 2, 'claims' => 1, 'publication' => 0, 'total' => 3], $this->row($html, '2026-08'));
        $this->assertSame(['travel' => 0, 'claims' => 0, 'publication' => 0, 'total' => 0], $this->row($html, '2026-09'));
        $this->assertSame(['travel' => 0, 'claims' => 0, 'publication' => 0, 'total' => 0], $this->row($html, '2026-10'));
        $this->assertSame(['travel' => 0, 'claims' => 1, 'publication' => 1, 'total' => 2], $this->row($html, '2026-11'));
    }

    public function test_the_monthly_table_follows_the_period_and_the_module(): void
    {
        $this->submitted('travel', '2026-06-10');
        $this->submitted('travel', '2026-10-01');
        $this->submitted('claims_student', '2026-10-02');

        // Last 90 days from 12 Nov reaches back into August: August to November,
        // the June application left out.
        $cgs = $this->cgs();
        $html = $this->actingAs($cgs)->get(route('norhanis.report', ['period' => 90]))->getContent();
        $this->assertSame(['2026-08', '2026-09', '2026-10', '2026-11'], $this->months($html));
        $this->assertSame(2, $this->row($html, '2026-10')['total']);

        // One module's page counts that module only.
        $travel = $this->actingAs($cgs)->get(route('norhanis.report', ['module' => 'travel']))->getContent();
        $this->assertSame(['travel' => 1, 'total' => 1], $this->row($travel, '2026-10'));
        $this->assertSame(['travel' => 1, 'total' => 1], $this->row($travel, '2026-06'));
    }

    public function test_a_period_with_no_applications_says_so(): void
    {
        $this->actingAs($this->cgs())->get(route('norhanis.report'))
            ->assertOk()
            ->assertSee('No applications submitted in this period.');
    }

    public function test_the_csv_export_carries_the_monthly_rows(): void
    {
        $this->submitted('travel', '2026-09-03');
        $this->submitted('publication', '2026-11-04');

        $csv = $this->actingAs($this->cgs())->get(route('norhanis.report.export'))->streamedContent();

        $this->assertStringContainsString("\n\n\"Applications submitted each month\"\n", $csv);
        $this->assertStringContainsString("Month,Travel,\"Student Claims\",Publication,Total\n", $csv);
        $this->assertStringContainsString("\"Sep 2026\",1,0,0,1\n", $csv);
        $this->assertStringContainsString("\"Oct 2026\",0,0,0,0\n", $csv);
        $this->assertStringContainsString("\"Nov 2026\",0,0,1,1\n", $csv);
        // The per-application rows are still first, as before.
        $this->assertStringStartsWith('"Application No."', $csv);
    }

    // ---- Helpers ------------------------------------------------------------------------

    protected int $students = 0;

    /** A decided application of $module, submitted on $date. */
    protected function submitted(string $module, string $date): Application
    {
        $n = ++$this->students;
        $student = $this->student('2200'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), "monthly{$n}@test.my");

        return Application::create([
            'student_id' => $student->id,
            'module_type' => $module,
            'status' => Application::STATUS_APPROVED,
            'submitted_at' => Carbon::parse($date.' 10:00:00'),
        ]);
    }

    /** @return array<int, string> the months the table lists, as YYYY-MM */
    protected function months(string $html): array
    {
        preg_match_all('/<tr data-month="(\d{4}-\d{2})">/', $html, $m);

        return $m[1];
    }

    /** @return array<string, int> slug => count, and 'total', for one month's row */
    protected function row(string $html, string $month): array
    {
        $this->assertSame(1, preg_match('/<tr data-month="'.$month.'">(.*?)<\/tr>/s', $html, $m), "No row for {$month}");
        preg_match_all('/<td data-module="(\w+)">(\d+)<\/td>/', $m[1], $cells, PREG_SET_ORDER);
        preg_match('/<td data-total><b>(\d+)<\/b><\/td>/', $m[1], $total);

        $row = [];
        foreach ($cells as [, $slug, $count]) {
            $row[$slug] = (int) $count;
        }

        return $row + ['total' => (int) $total[1]];
    }
}
