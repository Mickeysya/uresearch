<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Console\Commands\SeedDemoApplications;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\ClaimsDetail;
use App\Modules\Norhanis\Models\TravelDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The one list of Travel, Claims and Publication applications that approvers
 * read, and the demo data it is shown with.
 *
 * The rule under test is visibility: a supervisor sees their own students'
 * applications and nobody else's, a student sees none of it, and a draft is
 * not on the list for anyone.
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

    public function test_a_supervisor_sees_only_their_own_students(): void
    {
        $supervisor = $this->supervisor();
        $other = $this->supervisor('other.sup@test.my');

        $this->claim($this->student('22001001', 'aina@test.my', ['name' => 'Aina Zulkifli', 'supervisor_id' => $supervisor->id]));
        $this->claim($this->student('22001002', 'hakim@test.my', ['name' => 'Hakim Osman', 'supervisor_id' => $other->id]));

        $this->actingAs($supervisor)
            ->get(route('norhanis.overview'))
            ->assertOk()
            ->assertSee('Aina Zulkifli')
            ->assertDontSee('Hakim Osman')
            // It is on the supervisor's own stage, so it links to their queue.
            ->assertSee('Review');
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

    public function test_each_role_gets_a_separate_link_and_list_for_the_modules_it_approves(): void
    {
        $registry = app(\App\Modules\Core\Services\ModuleRegistry::class);
        $labels = fn ($user) => collect($registry->linksFor($user))->pluck('label')->all();

        $dean = $this->dean();
        $cgs = $this->cgs();

        // CGS reviews all three; the Dean only has a part in travel.
        foreach (['Travel Applications', 'Claims Applications', 'Publication Applications'] as $label) {
            $this->assertContains($label, $labels($cgs));
        }

        $this->assertContains('Travel Applications', $labels($dean));
        $this->assertNotContains('Claims Applications', $labels($dean));
        $this->assertNotContains('Publication Applications', $labels($dean));
        $this->assertNotContains('Travel Applications', $labels($this->student()));

        $this->actingAs($dean)->get(route('norhanis.overview', ['module' => 'travel']))->assertOk();
        $this->actingAs($dean)->get(route('norhanis.overview', ['module' => 'claims']))->assertForbidden();

        // And the combined list shows the Dean nothing from the other two.
        $this->claim($this->student('22001005', 'aina@test.my', ['name' => 'Aina Zulkifli']));
        $this->actingAs($dean)->get(route('norhanis.overview'))->assertOk()->assertDontSee('Aina Zulkifli');
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
        $list([])->assertSee('Non-Executive CGS (my stage)')->assertSee('Pending, waiting for');

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

        // Aina: submitted 10 days ago, endorsed by the supervisor 6 days ago,
        // so 4 days on the supervisor's stage and now waiting on the Chair.
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
        // Two decisions on the supervisor's stage: 4 days and none at all.
        $this->assertSame(2.0, $view->viewData('average')['claims']['supervisor']);
        $this->assertNull($view->viewData('average')['claims']['chair']);
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

        // Every other approver's dashboard is their own decisions, on the
        // list -- no report, no export, and no button pointing at either.
        $chair = $this->user(Role::CHAIR, ['email' => 'chair@test.my', 'department' => 'Computer & Information Sciences']);

        foreach ([$supervisor, $chair, $this->dean()] as $reader) {
            $this->actingAs($reader)->get(route('norhanis.report'))->assertForbidden();
            $this->actingAs($reader)->get(route('norhanis.report.export'))->assertForbidden();
        }

        // One escape, not two: the header's & and ' reach the browser as
        // characters, not as a visible "&amp;" or "&#039;".
        $this->actingAs($chair)->get(route('norhanis.overview'))->assertOk()
            ->assertSee('<h2>Travel, Claims &amp; Publication Applications</h2>', false)
            ->assertDontSee('&amp;amp;', false)
            ->assertSee('<p>Dashboard of your actions and your department&#039;s applications.</p>', false)
            ->assertDontSee('Reports &amp; analytics', false);
        $this->actingAs($cgs)->get(route('norhanis.overview'))->assertSee('Reports &amp; analytics', false);
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
        $this->assertSame(12, Application::whereIn('student_id', $demo)->count());
        $this->assertSame(0, Application::whereIn('student_id', $demo)->where('status', Application::STATUS_DRAFT)->count());
        $this->assertEqualsCanonicalizing(
            ['claims_student', 'publication', 'travel'],
            Application::whereIn('student_id', $demo)->distinct()->pluck('module_type')->all(),
        );

        // Running it again adds nothing.
        $this->artisan('norhanis:demo-applications', ['--count' => 4])->assertSuccessful();
        $this->assertSame(12, Application::whereIn('student_id', $demo)->count());

        $this->artisan('norhanis:demo-applications', ['--clear' => true])->assertSuccessful();

        $this->assertSame(0, User::where('email', 'like', '%'.SeedDemoApplications::DEMO_DOMAIN)->count());
        $this->assertSame(0, Application::whereIn('student_id', $demo)->count());
        $this->assertTrue($real->fresh()->exists);
        $this->assertTrue($kept->fresh()->exists);
    }

    public function test_a_chair_sees_only_their_departments_applications_that_reached_the_chair(): void
    {
        $supervisor = $this->supervisor();
        $chair = $this->user(Role::CHAIR, ['email' => 'chair@test.my', 'department' => 'Computer & Information Sciences']);
        $cgs = $this->cgs();
        $engine = app(WorkflowEngine::class);
        $cis = ['department' => 'Computer & Information Sciences', 'supervisor_id' => $supervisor->id];

        // Waiting on the Chair now: seen.
        $engine->decide($this->claim($this->student('22001001', 'aina@test.my', ['name' => 'Aina Zulkifli'] + $cis)), $supervisor, 'approve');

        // Already past the Chair, now with CGS: seen, through the history.
        $past = $this->claim($this->student('22001002', 'hakim@test.my', ['name' => 'Hakim Osman'] + $cis));
        $engine->decide($past, $supervisor, 'approve');
        $engine->decide($past->fresh(), $chair, 'approve');

        // Still with the supervisor, and rejected before the Chair: not yet
        // the Chair's business, and never will be.
        $this->claim($this->student('22001003', 'farah@test.my', ['name' => 'Farah Lee'] + $cis));
        $engine->decide($this->claim($this->student('22001004', 'imran@test.my', ['name' => 'Imran Yusof'] + $cis)), $supervisor, 'reject');

        // Waiting on the Chair, but another department's.
        $engine->decide($this->claim($this->student('22001005', 'mei@test.my', [
            'name' => 'Tan Mei Ling', 'department' => 'Chemical Engineering', 'supervisor_id' => $supervisor->id,
        ])), $supervisor, 'approve');

        $view = $this->actingAs($chair)->get(route('norhanis.overview', ['module' => 'claims']))->assertOk();

        $view->assertSee('Aina Zulkifli')->assertSee('Hakim Osman')
            ->assertDontSee('Farah Lee')->assertDontSee('Imran Yusof')->assertDontSee('Tan Mei Ling')
            ->assertSee("Dashboard of your actions and your department's applications.");

        // The dashboard counts the same two rows, one of them waiting on the
        // Chair, and no stage before the Chair's is offered.
        $this->assertSame(['pending' => 2, 'approved' => 0, 'rejected' => 0], $view->viewData('totals'));
        $this->assertSame(1, $view->viewData('waiting')['chair']);
        $this->assertArrayNotHasKey('supervisor', $view->viewData('stages'));
        $view->assertSee('Waiting on You');

        // A Chair's chart is their own decisions, not the whole chain's
        // stages: one waiting on them, one they passed on, none rejected.
        $this->assertSame(['Waiting on me', 'Endorsed by me', 'Rejected by me'], $view->viewData('mine')['labels']);
        $this->assertSame([1, 1, 0], $view->viewData('mine')['counts']);
        // The Outcomes line names whose applications these are, and who
        // else on the claims chain a pending one may be with.
        $view->assertSee('Applications from your department that reached you as Chair of Department. A pending one is waiting on you, Non-Executive CGS or Manager CGS.');

        $view->assertSee('My decisions')->assertSee('overview-mine')->assertDontSee('Pending, waiting for');

        // Each bar is a filter on the list.
        $this->actingAs($chair)->get(route('norhanis.overview', ['module' => 'claims', 'decided' => 'passed']))
            ->assertSee('Hakim Osman')->assertDontSee('Aina Zulkifli');
        $this->actingAs($chair)->get(route('norhanis.overview', ['module' => 'claims', 'decided' => 'rejected']))
            ->assertSee('No applications match');

        // CGS still sees all five.
        $this->actingAs($cgs)->get(route('norhanis.overview', ['module' => 'claims']))
            ->assertSee('Showing 1 to 5 of 5');
    }

    public function test_a_chair_with_no_department_on_record_sees_nothing(): void
    {
        $supervisor = $this->supervisor();
        $chair = $this->chair();

        // A student with no department either: where('department', null)
        // must not be what lets them through.
        app(WorkflowEngine::class)->decide($this->claim($this->student('22001001', 'aina@test.my', ['name' => 'Aina Zulkifli'])), $supervisor, 'approve');

        $this->actingAs($chair)->get(route('norhanis.overview', ['module' => 'claims']))
            ->assertOk()
            ->assertDontSee('Aina Zulkifli')
            ->assertSee('No applications match');
    }

    public function test_the_dean_sees_international_travel_that_reached_the_dean_and_no_local_travel(): void
    {
        $supervisor = $this->supervisor();
        $chair = $this->user(Role::CHAIR, ['email' => 'chair@test.my', 'department' => 'Computer & Information Sciences']);
        $cgs = $this->cgs();
        $dean = $this->dean();
        $engine = app(WorkflowEngine::class);
        $cis = ['department' => 'Computer & Information Sciences'];

        // International, through the supervisor, Chair and CGS: on the Dean.
        $abroad = $this->travelFor($this->student('22001001', 'aina@test.my', ['name' => 'Aina Zulkifli'] + $cis), international: true);
        $engine->decide($abroad, $supervisor, 'approve');
        $engine->decide($abroad->fresh(), $chair, 'approve');
        $engine->decide($abroad->fresh(), $cgs, 'approve');

        // International but still with the supervisor: not the Dean's yet.
        $this->travelFor($this->student('22001002', 'hakim@test.my', ['name' => 'Hakim Osman'] + $cis), international: true);

        // Local, approved all the way to the Senior Director: finished without
        // ever reaching the Dean.
        $local = $this->travelFor($this->student('22001003', 'farah@test.my', ['name' => 'Farah Lee'] + $cis), international: false);
        $engine->decide($local, $supervisor, 'approve');
        $engine->decide($local->fresh(), $chair, 'approve');
        $engine->decide($local->fresh(), $cgs, 'approve');
        $engine->decide($local->fresh(), $this->seniorDirector(), 'approve');
        $this->assertSame(Application::STATUS_APPROVED, $local->fresh()->status);

        $view = $this->actingAs($dean)->get(route('norhanis.overview', ['module' => 'travel']))->assertOk();

        $view->assertSee('Aina Zulkifli')->assertDontSee('Hakim Osman')->assertDontSee('Farah Lee');
        $this->assertSame(1, $view->viewData('waiting')['dean_pgr']);
        $this->assertSame(['dean_pgr'], array_keys($view->viewData('stages')));
        $this->assertSame(['Waiting on me', 'Approved by me', 'Rejected by me'], $view->viewData('mine')['labels']);
        $this->assertSame([1, 0, 0], $view->viewData('mine')['counts']);
        $view->assertSee('Applications that reached you as Dean of PGR. You are the last approver, so a pending one is waiting on you.');
    }

    public function test_the_dashboard_summarises_what_the_reader_may_see(): void
    {
        $supervisor = $this->supervisor();
        $engine = app(WorkflowEngine::class);

        $this->claim($this->student('22001001', 'aina@test.my', ['name' => 'Aina Zulkifli']));
        $engine->decide($this->claim($this->student('22001002', 'hakim@test.my', ['name' => 'Hakim Osman'])), $supervisor, 'reject');

        $view = $this->actingAs($this->cgs())->get(route('norhanis.overview'))->assertOk();

        $this->assertSame(['pending' => 1, 'approved' => 0, 'rejected' => 1], $view->viewData('totals'));

        $chart = $view->viewData('chart');
        $this->assertSame([1, 0, 1], $chart['statusTotals']);
        $this->assertSame(1, $chart['waiting']['claims'][0]);
        $this->assertContains('Non-Executive CGS (you)', $chart['stages']);

        // CGS keeps the stage chart: where the whole process is backing up.
        $this->assertNull($view->viewData('mine'));
        $view->assertSee('Every submitted application, at every stage: Lecturer/Supervisor, Chair of Department, Non-Executive CGS');
        $view->assertSee('Total Applications')->assertSee('Waiting on You')->assertSee('Outcomes')
            ->assertSee('overview-waiting')->assertSee('overview-status')->assertDontSee('My decisions');
    }

    /** A travel application for $student, submitted to its first stage. */
    protected function travelFor(User $student, bool $international): Application
    {
        $application = Application::create([
            'student_id' => $student->id,
            'module_type' => 'travel',
            'status' => Application::STATUS_DRAFT,
        ]);

        TravelDetail::create([
            'application_id' => $application->id,
            'type_of_request' => 'data_collection',
            'travel_start_date' => now()->addDays(10),
            'travel_end_date' => now()->addDays(12),
            'duration_days' => 3,
            'reason_for_travel' => 'Fieldwork',
            'destination_address' => $international ? 'Singapore' : 'Kuala Lumpur',
            'is_international' => $international,
            'contact_person_email' => 'contact@test.my',
            'is_ga_gra' => false,
        ]);

        return app(WorkflowEngine::class)->submit($application);
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
