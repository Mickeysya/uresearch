<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Console\Commands\SeedDemoApplications;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\ClaimsDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The one list of Travel, Claims and Publication applications that CGS
 * reads, and the demo data it is shown with.
 *
 * The rule under test is visibility: CGS sees every submitted application,
 * nobody else opens the list at all -- the other approvers act from their own
 * queue -- and a draft is not on it for anyone.
 */
class ApplicationOverviewTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    public function test_cgs_sees_every_submitted_application_with_the_students_name(): void
    {
        $mine = $this->claim($this->student('22001001', 'aina@test.my', ['name' => 'Aina Zulkifli']));
        $this->claim($this->student('22001002', 'hakim@test.my', ['name' => 'Hakim Osman']));
        $draft = $this->claim($this->student('22001003', 'draft@test.my', ['name' => 'Never Submitted']), submit: false);

        $this->actingAs($this->cgs())
            ->get(route('norhanis.overview'))
            ->assertOk()
            ->assertSee('Aina Zulkifli')
            ->assertSee('22001001')
            ->assertSee('Hakim Osman')
            ->assertSee('Showing 1 to 2 of 2')
            // A draft has not been submitted to anyone.
            ->assertDontSee('Never Submitted');

        $this->assertSame(Application::STATUS_DRAFT, $draft->status);
        $this->assertSame('supervisor', $mine->current_stage);
    }

    public function test_only_cgs_has_the_lists_the_other_approvers_work_from_their_queue(): void
    {
        $supervisor = $this->supervisor();
        $student = $this->student('22001001', 'aina@test.my', ['name' => 'Aina Zulkifli', 'supervisor_id' => $supervisor->id]);
        $this->claim($student);

        $chair = $this->user(Role::CHAIR, ['email' => 'chair@test.my', 'department' => 'Computer & Information Sciences']);

        // The list shows every approver's stage, so only CGS opens it...
        foreach ([$supervisor, $chair, $this->dean()] as $reader) {
            foreach ([null, 'travel', 'claims', 'publication'] as $module) {
                $this->actingAs($reader)->get(route('norhanis.overview', ['module' => $module]))->assertForbidden();
            }
        }

        // ...while the supervisor's queue still has the application, on
        // their stage alone.
        $this->actingAs($supervisor)->get(route('claims.queue'))->assertOk()->assertSee('Aina Zulkifli');
    }

    public function test_the_list_filters_by_student_module_and_status(): void
    {
        $aina = $this->student('22001001', 'aina@test.my', ['name' => 'Aina Zulkifli']);
        $hakim = $this->student('22001002', 'hakim@test.my', ['name' => 'Hakim Osman']);
        $this->claim($aina);
        $rejected = $this->claim($hakim);

        app(WorkflowEngine::class)->decide($rejected, $this->supervisor(), 'reject', 'Not eligible.');

        $cgs = $this->cgs();
        $list = fn (array $query) => $this->actingAs($cgs)->get(route('norhanis.overview', $query))->assertOk();

        $list(['q' => 'aina'])->assertSee('Aina Zulkifli')->assertDontSee('Hakim Osman');
        $list(['q' => '22001002'])->assertSee('Hakim Osman')->assertDontSee('Aina Zulkifli');
        $list(['q' => '#'.$rejected->id])->assertSee('Hakim Osman')->assertDontSee('Aina Zulkifli');
        $list(['status' => 'rejected'])->assertSee('Hakim Osman')->assertDontSee('Aina Zulkifli');

        // Each module also has its own list, and a module that is not one of
        // the three is not a page at all.
        $list(['module' => 'claims'])->assertSee('Student Claims Applications')->assertSee('Aina Zulkifli');
        $list(['module' => 'travel'])->assertSee('Travel Applications')->assertSee('No applications match');
        $this->actingAs($cgs)->get('/applications-overview/hardbound')->assertNotFound();

        // On the combined list, a filter value that is not a choice is refused.
        $this->actingAs($cgs)->get('/applications-overview?module=hardbound')->assertSessionHasErrors('module');
    }

    public function test_cgs_gets_a_separate_link_and_list_for_each_module_it_approves(): void
    {
        $registry = app(\App\Modules\Core\Services\ModuleRegistry::class);
        $labels = fn ($user) => collect($registry->linksFor($user))->pluck('label')->all();
        $all = ['Travel Applications', 'Claims Applications', 'Publication Applications'];

        // Non-Executive CGS reviews all three; Manager CGS only approves claims.
        $this->assertSame($all, array_values(array_intersect($all, $labels($this->cgs()))));

        $manager = $this->user(Role::MANAGER_CGS, ['email' => 'manager@test.my']);
        $this->assertSame(['Claims Applications'], array_values(array_intersect($all, $labels($manager))));
        $this->actingAs($manager)->get(route('norhanis.overview', ['module' => 'claims']))->assertOk();
        $this->actingAs($manager)->get(route('norhanis.overview', ['module' => 'travel']))->assertForbidden();

        // Nobody else has any of them in the sidebar, though each approves one.
        $chair = $this->user(Role::CHAIR, ['email' => 'chair@test.my', 'department' => 'Computer & Information Sciences']);

        foreach ([$this->supervisor(), $chair, $this->dean(), $this->student()] as $reader) {
            $this->assertSame([], array_values(array_intersect($all, $labels($reader))));
        }
    }

    public function test_the_list_filters_by_whose_stage_an_application_is_waiting_on(): void
    {
        $supervisor = $this->supervisor();
        $this->chair();
        $cgs = $this->cgs();

        $this->claim($this->student('22001001', 'aina@test.my', ['name' => 'Aina Zulkifli']));
        $further = $this->claim($this->student('22001002', 'hakim@test.my', ['name' => 'Hakim Osman']));

        // Hakim's claim moves on to the Chair; Aina's stays with the supervisor.
        app(WorkflowEngine::class)->decide($further, $supervisor, 'approve');

        $list = fn (array $query) => $this->actingAs($cgs)->get(route('norhanis.overview', ['module' => 'claims'] + $query))->assertOk();

        $list(['stage' => 'supervisor'])->assertSee('Aina Zulkifli')->assertDontSee('Hakim Osman');
        $list(['stage' => 'chair'])->assertSee('Hakim Osman')->assertDontSee('Aina Zulkifli');
        $list(['stage' => 'non_exec_cgs'])->assertSee('No applications match');

        // The reader's own stage is pointed out, and each stage has a count.
        $list([])->assertSee('Non-Executive CGS (my stage)');

        // The Dean has no stage in the claims chain, so it is not a choice.
        $this->actingAs($cgs)
            ->get(route('norhanis.overview', ['module' => 'claims', 'stage' => 'dean_pgr']))
            ->assertSessionHasErrors('stage');
    }

    public function test_the_report_counts_outcomes_waiting_and_days_per_stage(): void
    {
        $supervisor = $this->supervisor();
        $this->chair();
        $cgs = $this->cgs();
        $engine = app(WorkflowEngine::class);

        // Stage time is counted in working days, so the clock is fixed:
        // Friday 6 Nov 2026, noon.
        $this->travelTo(Carbon::parse('2026-11-06 12:00'));

        // Aina: submitted 10 days ago (Tue noon), endorsed by the supervisor
        // 6 days ago (Sat noon), so 3.5 working days on the supervisor's stage
        // and now waiting on the Chair.
        $aina = $this->claim($this->student('22001001', 'aina@test.my', ['name' => 'Aina Zulkifli']));
        $aina->forceFill(['submitted_at' => now()->subDays(10)])->save();
        $engine->decide($aina, $supervisor, 'approve');
        $aina->history()->update(['created_at' => now()->subDays(6)]);

        // Hakim: rejected by the supervisor. Farah: still with the supervisor.
        $engine->decide($this->claim($this->student('22001002', 'hakim@test.my', ['name' => 'Hakim Osman'])), $supervisor, 'reject');
        $this->claim($this->student('22001003', 'farah@test.my', ['name' => 'Farah Lee']));

        $view = $this->actingAs($cgs)->get(route('norhanis.report', ['module' => 'claims']))->assertOk();

        $this->assertSame(['pending' => 2, 'approved' => 0, 'rejected' => 1], $view->viewData('totals')['claims']);
        $this->assertSame(1, $view->viewData('waiting')['claims']['supervisor']);
        $this->assertSame(1, $view->viewData('waiting')['claims']['chair']);
        // Two decisions on the supervisor's stage, 3.5 working days and none
        // at all: a median of 1.75, shown to one place.
        $this->assertSame(1.8, $view->viewData('median')['claims']['supervisor']);
        $this->assertNull($view->viewData('median')['claims']['chair']);
        // The client charter sits beside it.
        $this->assertSame(2, $view->viewData('charter')['claims']['supervisor']);
        $this->assertSame(3, array_sum($view->viewData('months')['series']['claims']));

        // Aina has waited longest on her current stage, and is named.
        $this->assertSame('Aina Zulkifli', $view->viewData('longest')->first()['application']->student->name);
        $view->assertSee('Aina Zulkifli')->assertSee('Export as CSV');

        // A period that leaves her out leaves her out of every figure.
        $recent = $this->actingAs($cgs)->get(route('norhanis.report', ['module' => 'claims', 'period' => 30]))->assertOk();
        $this->assertSame(3, array_sum($recent->viewData('totals')['claims']));
        $this->actingAs($cgs)->get(route('norhanis.report', ['period' => 7]))->assertSessionHasErrors('period');
    }

    public function test_the_report_and_its_export_are_for_cgs_only(): void
    {
        $supervisor = $this->supervisor();

        $this->claim($this->student('22001001', 'aina@test.my', ['name' => 'Aina Zulkifli', 'supervisor_id' => $supervisor->id]));
        $this->claim($this->student('22001002', 'hakim@test.my', ['name' => '=HYPERLINK("http://x")']));

        // CGS analyses the whole process: the report opens, and the export
        // carries every row with the formula defused.
        $cgs = $this->cgs();
        $this->actingAs($cgs)->get(route('norhanis.report'))->assertOk();
        $all = $this->actingAs($cgs)->get(route('norhanis.report.export', ['module' => 'claims']))->streamedContent();
        $this->assertStringContainsString('Aina Zulkifli', $all);
        $this->assertStringContainsString("'=HYPERLINK", $all);

        // No other approver gets the report or the export.
        $chair = $this->user(Role::CHAIR, ['email' => 'chair@test.my', 'department' => 'Computer & Information Sciences']);

        foreach ([$supervisor, $chair, $this->dean()] as $reader) {
            $this->actingAs($reader)->get(route('norhanis.report'))->assertForbidden();
            $this->actingAs($reader)->get(route('norhanis.report.export'))->assertForbidden();
        }

        // One escape, not two: the header's & and ' reach the browser as
        // characters, not as a visible "&amp;" or "&#039;".
        $this->actingAs($cgs)->get(route('norhanis.overview'))->assertOk()
            ->assertSee('<h2>Travel, Claims &amp; Publication Applications</h2>', false)
            ->assertDontSee('&amp;amp;', false)
            ->assertSee('Reports &amp; analytics', false);
        $this->actingAs($this->student('22001009', 's9@test.my'))->get(route('norhanis.report'))->assertForbidden();
    }

    public function test_the_list_is_paged(): void
    {
        $student = $this->student();

        foreach (range(1, 27) as $n) {
            $this->claim($student);
        }

        $this->actingAs($this->cgs())
            ->get(route('norhanis.overview'))
            ->assertOk()
            ->assertSee('Showing 1 to 25 of 27');
    }

    public function test_students_and_roles_outside_the_chains_cannot_open_it(): void
    {
        $this->actingAs($this->student())->get(route('norhanis.overview'))->assertForbidden();
        $this->actingAs($this->registry())->get(route('norhanis.overview'))->assertForbidden();
    }

    public function test_the_demo_command_creates_and_removes_its_own_students_only(): void
    {
        $real = $this->student();
        $this->supervisor();
        $this->chair();
        $this->cgs();
        $this->dean();
        $this->seniorDirector();
        $this->user(\App\Modules\Core\Support\Role::MANAGER_CGS);
        $kept = $this->claim($real);

        $this->artisan('norhanis:demo-applications', ['--count' => 4])->assertSuccessful();

        $demo = User::where('email', 'like', '%'.SeedDemoApplications::DEMO_DOMAIN)->pluck('id');

        $this->assertCount(4, $demo);
        // One application each, the three modules in turn.
        $this->assertSame(4, Application::whereIn('student_id', $demo)->count());
        $this->assertSame(0, Application::whereIn('student_id', $demo)->where('status', Application::STATUS_DRAFT)->count());
        $this->assertEqualsCanonicalizing(
            ['claims_student', 'publication', 'travel'],
            Application::whereIn('student_id', $demo)->distinct()->pluck('module_type')->all(),
        );

        // Running it again adds nothing.
        $this->artisan('norhanis:demo-applications', ['--count' => 4])->assertSuccessful();
        $this->assertSame(4, Application::whereIn('student_id', $demo)->count());

        $this->artisan('norhanis:demo-applications', ['--clear' => true])->assertSuccessful();

        $this->assertSame(0, User::where('email', 'like', '%'.SeedDemoApplications::DEMO_DOMAIN)->count());
        $this->assertSame(0, Application::whereIn('student_id', $demo)->count());
        $this->assertTrue($real->fresh()->exists);
        $this->assertTrue($kept->fresh()->exists);
    }

    public function test_the_dashboard_summarises_what_the_reader_may_see(): void
    {
        $supervisor = $this->supervisor();
        $engine = app(WorkflowEngine::class);

        $this->claim($this->student('22001001', 'aina@test.my', ['name' => 'Aina Zulkifli']));
        $engine->decide($this->claim($this->student('22001002', 'hakim@test.my', ['name' => 'Hakim Osman'])), $supervisor, 'reject');

        $view = $this->actingAs($this->cgs())->get(route('norhanis.overview'))->assertOk();

        $this->assertSame(['pending' => 1, 'approved' => 0, 'rejected' => 1], $view->viewData('totals'));

        // The stat cards but no charts: Reports & analytics has them.
        $view->assertSee('Total Applications')->assertSee('Waiting on You')
            ->assertDontSee('<canvas id="overview-status"', false)
            ->assertDontSee('Outcomes')->assertDontSee('My decisions');
    }

    /** A claims application for $student, submitted to its first stage. */
    protected function claim(User $student, bool $submit = true): Application
    {
        $application = Application::create([
            'student_id' => $student->id,
            'module_type' => 'claims_student',
            'status' => Application::STATUS_DRAFT,
        ]);

        ClaimsDetail::create([
            'application_id' => $application->id,
            'bank_name' => 'Maybank',
            'bank_account_no' => '1234567890',
            'total_claims' => 500,
        ]);

        return $submit ? app(WorkflowEngine::class)->submit($application) : $application;
    }
}
