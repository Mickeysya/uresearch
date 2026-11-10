<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
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

    protected function approver(string $role, string $email, string $name): User
    {
        return User::create(['name' => $name, 'email' => $email, 'password' => 'password', 'role' => $role]);
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
