<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Console\Commands\SeedDemoApplications;
use App\Modules\Norhanis\Models\ClaimsDetail;
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

    public function test_the_report_and_its_export_follow_the_same_visibility_as_the_lists(): void
    {
        $supervisor = $this->supervisor();
        $other = $this->supervisor('other.sup@test.my');

        $this->claim($this->student('22001001', 'aina@test.my', ['name' => 'Aina Zulkifli', 'supervisor_id' => $supervisor->id]));
        $this->claim($this->student('22001002', 'hakim@test.my', ['name' => '=HYPERLINK("http://x")', 'supervisor_id' => $other->id]));

        $csv = $this->actingAs($supervisor)->get(route('norhanis.report.export'))->assertOk()->streamedContent();

        $this->assertStringContainsString('Aina Zulkifli', $csv);
        $this->assertStringNotContainsString('HYPERLINK', $csv);

        // For CGS the row is there, with the formula defused.
        $all = $this->actingAs($this->cgs())->get(route('norhanis.report.export', ['module' => 'claims']))->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $all);

        $dean = $this->dean();
        $this->actingAs($dean)->get(route('norhanis.report', ['module' => 'claims']))->assertForbidden();
        $this->actingAs($dean)->get(route('norhanis.report.export', ['module' => 'claims']))->assertForbidden();
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
