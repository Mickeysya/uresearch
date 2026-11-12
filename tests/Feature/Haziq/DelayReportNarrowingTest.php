<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Support\DelayByActorReport;
use App\Modules\Haziq\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Confirmed explicitly 2026-11-10: "role yang general siapa siapa leh
 * verify cam admin, cgs, senior director taleh nak narrow down analysis
 * siapa banyak cause delay... tapi supervisor and research centre...
 * each student memang ada specific supervisor and research centre."
 * Individual-level drill-down only makes sense for a role a given
 * student is actually tied to one specific person for -- Admin, CGS and
 * Senior Director are picked up by whoever is free, so "this one person
 * is slow" predicts nothing about that student's next application.
 */
class DelayReportNarrowingTest extends TestCase
{
    use RefreshDatabase;

    protected function approver(string $role, string $email, string $name, ?string $department = null): User
    {
        return User::create(['name' => $name, 'email' => $email, 'password' => 'password', 'role' => $role, 'department' => $department]);
    }

    protected function application(string $moduleType, Carbon $submittedAt): Application
    {
        $student = User::create(['name' => 'Student', 'email' => 'student'.uniqid().'@test.com', 'password' => 'password', 'role' => Role::STUDENT]);

        return Application::create(['student_id' => $student->id, 'module_type' => $moduleType, 'status' => Application::STATUS_APPROVED, 'current_stage' => null, 'submitted_at' => $submittedAt]);
    }

    protected function decided(Application $application, User $approver, string $stageKey, Carbon $decidedAt): ApprovalHistory
    {
        $history = ApprovalHistory::create([
            'application_id' => $application->id, 'approver_id' => $approver->id,
            'stage_key' => $stageKey, 'stage_label' => ucfirst($stageKey), 'decision' => 'approved',
        ]);
        $history->created_at = $decidedAt;
        $history->save();

        return $history;
    }

    public function test_gra_applications_narrowable_roles_are_supervisor_only(): void
    {
        $this->assertSame([Role::SUPERVISOR], DelayByActorReport::narrowableRolesFor('gra_application')->all());
        $this->assertSame([Role::SUPERVISOR], DelayByActorReport::narrowableRolesFor('gra_extension')->all());
    }

    public function test_ga_applications_narrowable_roles_are_supervisor_and_research_centre(): void
    {
        $this->assertEqualsCanonicalizing(
            [Role::SUPERVISOR, Roles::RESEARCH_CENTRE],
            DelayByActorReport::narrowableRolesFor('ga_application')->all()
        );
    }

    public function test_ga_extensions_narrowable_roles_are_supervisor_only_since_it_has_no_research_centre_stage(): void
    {
        $this->assertSame([Role::SUPERVISOR], DelayByActorReport::narrowableRolesFor('ga_extension')->all());
    }

    public function test_the_individual_breakdown_table_excludes_admin_cgs_and_senior_director(): void
    {
        $admin = $this->approver(Role::ADMIN, 'admin-narrow@test.com', 'Firdaus Admin Person');
        $cgs = $this->approver(Role::NON_EXEC_CGS, 'cgs-narrow@test.com', 'Zarina Cgs Person');
        $supervisor = $this->approver(Role::SUPERVISOR, 'sup-narrow@test.com', 'Kamal Supervisor Person');
        $director = $this->approver(Role::SENIOR_DIRECTOR_CGS, 'director-narrow@test.com', 'Latifah Director Person');

        $submitted = Carbon::parse('2026-01-01 00:00:00');
        $application = $this->application('gra_application', $submitted);
        $this->decided($application, $admin, 'admin_verify', $submitted->copy()->addHour());
        $this->decided($application, $cgs, 'cgs_review', $submitted->copy()->addHours(2));
        $this->decided($application, $supervisor, 'supervisor', $submitted->copy()->addHours(3));
        $this->decided($application, $director, 'senior_director', $submitted->copy()->addHours(4));

        $viewer = $this->approver(Role::ADMIN, 'viewer@test.com', 'Report Viewer');

        // Checked against the view's own data, not the rendered page --
        // the raw "every decision" audit table at the bottom of the page
        // intentionally still names every actor regardless of role; only
        // the aggregated "By individual actor" breakdown (this $byActor
        // data) is restricted.
        $this->actingAs($viewer)->get(route('delay-report.index', ['module' => 'gra_application']))
            ->assertOk()
            ->assertViewHas('byActor', function ($byActor) use ($supervisor) {
                return $byActor->pluck('role')->all() === [Role::SUPERVISOR]
                    && $byActor->first()['approver_name'] === $supervisor->name;
            });
    }

    /**
     * Confirmed explicitly 2026-11-10: "research centre... bila specific
     * akan tahu department mana ah, die takde by individual... supervisor
     * ada by individual sebab narrow down supervisor is individual is
     * individual lah." Research Centre's narrowed row is the department,
     * not whichever named officer happens to staff it -- so two different
     * officers in the same department still collapse into one row.
     */
    public function test_research_centre_rows_in_the_same_department_collapse_into_one_row_by_department(): void
    {
        $officer1 = $this->approver(Roles::RESEARCH_CENTRE, 'rc1-narrow@test.com', 'Dr. Officer One', 'Computing');
        $officer2 = $this->approver(Roles::RESEARCH_CENTRE, 'rc2-narrow@test.com', 'Dr. Officer Two', 'Computing');

        $submitted = Carbon::parse('2026-01-01 00:00:00');
        $application1 = $this->application('ga_application', $submitted);
        $this->decided($application1, $officer1, 'research_centre_interview', $submitted->copy()->addHours(2));
        $application2 = $this->application('ga_application', $submitted);
        $this->decided($application2, $officer2, 'research_centre_interview', $submitted->copy()->addHours(4));

        $rows = DelayByActorReport::rows('ga_application');
        $byActor = DelayByActorReport::byActor($rows->where('approver_role', Roles::RESEARCH_CENTRE), $rows);

        $this->assertCount(1, $byActor);
        $this->assertSame('Computing', $byActor->first()['approver_name']);
        $this->assertSame(2, $byActor->first()['decisions']);
    }

    public function test_research_centre_rows_in_different_departments_stay_separate(): void
    {
        $officer1 = $this->approver(Roles::RESEARCH_CENTRE, 'rc3-narrow@test.com', 'Dr. Officer Three', 'Computing');
        $officer2 = $this->approver(Roles::RESEARCH_CENTRE, 'rc4-narrow@test.com', 'Dr. Officer Four', 'Chemical Engineering');

        $submitted = Carbon::parse('2026-01-01 00:00:00');
        $application1 = $this->application('ga_application', $submitted);
        $this->decided($application1, $officer1, 'research_centre_interview', $submitted->copy()->addHours(2));
        $application2 = $this->application('ga_application', $submitted);
        $this->decided($application2, $officer2, 'research_centre_interview', $submitted->copy()->addHours(4));

        $rows = DelayByActorReport::rows('ga_application');
        $byActor = DelayByActorReport::byActor($rows->where('approver_role', Roles::RESEARCH_CENTRE), $rows);

        $this->assertEqualsCanonicalizing(['Computing', 'Chemical Engineering'], $byActor->pluck('approver_name')->all());
    }

    /**
     * actorOptionsFor() -- the Actor select's own options -- confirmed
     * explicitly 2026-11-10: "dalam research centre can be multiple
     * email in the department... analysis by keseluruhan department,
     * not individual in every department." Two officers sharing one
     * department produce exactly one selectable option, not two.
     */
    public function test_research_centre_actor_options_are_one_per_department_even_with_two_officers_in_it(): void
    {
        $this->approver(Roles::RESEARCH_CENTRE, 'rc5-narrow@test.com', 'Dr. Officer Five', 'Computing');
        $this->approver(Roles::RESEARCH_CENTRE, 'rc6-narrow@test.com', 'Dr. Officer Six', 'Computing');
        $this->approver(Roles::RESEARCH_CENTRE, 'rc7-narrow@test.com', 'Dr. Officer Seven', 'Chemical Engineering');

        $options = DelayByActorReport::actorOptionsFor(Roles::RESEARCH_CENTRE);

        $this->assertEqualsCanonicalizing(['Computing', 'Chemical Engineering'], $options->pluck('key')->all());
    }

    public function test_supervisor_actor_options_are_still_one_per_person(): void
    {
        $this->approver(Role::SUPERVISOR, 'sup-options@test.com', 'Dr. Option Supervisor');

        $options = DelayByActorReport::actorOptionsFor(Role::SUPERVISOR);

        $this->assertCount(1, $options);
        $this->assertSame('Dr. Option Supervisor', $options->first()['label']);
    }

    /** Drilling down to one department has to combine every officer in it, not just whichever one a stray id pointed at. */
    public function test_drilling_down_to_one_research_centre_department_combines_every_officer_in_it(): void
    {
        $officer1 = $this->approver(Roles::RESEARCH_CENTRE, 'rc8-narrow@test.com', 'Dr. Officer Eight', 'Computing');
        $officer2 = $this->approver(Roles::RESEARCH_CENTRE, 'rc9-narrow@test.com', 'Dr. Officer Nine', 'Computing');

        $submitted = Carbon::parse('2026-01-01 00:00:00');
        $application1 = $this->application('ga_application', $submitted);
        $this->decided($application1, $officer1, 'research_centre_interview', $submitted->copy()->addHours(2));
        $application2 = $this->application('ga_application', $submitted);
        $this->decided($application2, $officer2, 'research_centre_interview', $submitted->copy()->addHours(4));

        $rows = DelayByActorReport::rows('ga_application');
        $bars = DelayByActorReport::chartBars($rows, Roles::RESEARCH_CENTRE, 'Computing');

        $this->assertCount(1, $bars);
        $this->assertSame('Computing', $bars->first()['label']);
        $this->assertSame(2, $bars->first()['decisions']);
    }

    public function test_the_delay_report_page_accepts_a_department_as_the_actor_query_string(): void
    {
        $officer = $this->approver(Roles::RESEARCH_CENTRE, 'rc10-narrow@test.com', 'Dr. Officer Ten', 'Computing');
        $viewer = $this->approver(Role::ADMIN, 'viewer3@test.com', 'Report Viewer Three');

        $this->actingAs($viewer)
            ->get(route('delay-report.index', ['module' => 'ga_application', 'role' => Roles::RESEARCH_CENTRE, 'actor' => 'Computing']))
            ->assertOk()
            ->assertViewHas('actorId', 'Computing');
    }

    /**
     * Confirmed explicitly 2026-11-10, revising an earlier blanket ban in
     * the same conversation: "boleh je tengok delay by actor tapi yang
     * role die related sahaja ah which is ga application" -- Research
     * Centre CAN view the report, but locked to ga_application; a query
     * string naming any other scope is ignored rather than honoured.
     */
    public function test_research_centre_can_view_the_delay_report_but_locked_to_ga_application(): void
    {
        $reviewer = $this->approver(Roles::RESEARCH_CENTRE, 'rc-delay-report@test.com', 'RC Delay Report', 'Computing');

        $this->actingAs($reviewer)->get(route('delay-report.index'))
            ->assertOk()
            ->assertViewHas('module', 'ga_application')
            ->assertViewHas('lockedToModule', 'ga_application');

        $this->actingAs($reviewer)->get(route('delay-report.index', ['module' => 'gra_application']))
            ->assertOk()
            ->assertViewHas('module', 'ga_application');
    }

    public function test_research_centre_gets_its_own_delay_by_actor_sidebar_link_scoped_to_ga(): void
    {
        $reviewer = $this->approver(Roles::RESEARCH_CENTRE, 'rc-delay-link@test.com', 'RC Delay Link', 'Computing');

        $links = app(ModuleRegistry::class)->linksFor($reviewer);

        $this->assertTrue(collect($links)->contains('route', 'delay-report.index'));
    }

    public function test_admin_can_still_view_every_scope_unrestricted(): void
    {
        $admin = $this->approver(Role::ADMIN, 'admin-delay-report@test.com', 'Admin Delay Report');

        $this->actingAs($admin)->get(route('delay-report.index', ['module' => 'gra_application']))
            ->assertOk()
            ->assertViewHas('module', 'gra_application')
            ->assertViewHas('lockedToModule', null);
    }

    public function test_requesting_an_unnarrowable_role_in_the_query_string_is_ignored(): void
    {
        $admin = $this->approver(Role::ADMIN, 'admin-qs@test.com', 'Qs Admin Person');
        $submitted = Carbon::parse('2026-01-01 00:00:00');
        $application = $this->application('gra_application', $submitted);
        $this->decided($application, $admin, 'admin_verify', $submitted->copy()->addHour());

        $viewer = $this->approver(Role::ADMIN, 'viewer2@test.com', 'Report Viewer Two');

        $this->actingAs($viewer)
            ->get(route('delay-report.index', ['module' => 'gra_application', 'role' => Role::ADMIN]))
            ->assertOk()
            // Falls back to "every role" rather than honouring an admin
            // narrow-down -- the "By role" comparison (unrestricted)
            // still shows Admin, just never broken out per person.
            ->assertSee('Every role, each already averaged', false);
    }
}
