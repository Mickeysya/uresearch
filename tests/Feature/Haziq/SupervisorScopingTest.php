<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirmed explicitly 2026-11-10: "supervisor akan approve student yang
 * connected to die je... bukan semua supervisor approve for any student."
 * The engine's own authorisation is role-based only (Role::SUPERVISOR),
 * not person-based -- without this module's own scoping on top, any
 * Supervisor could see and decide on any student's application at this
 * stage, not only their own supervisees. Same pattern Nureen's
 * SupervisionController already uses for its own 'supervisor' stage.
 */
class SupervisorScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function student(User $supervisor): User
    {
        return User::create([
            'name' => 'Student', 'email' => 'student'.uniqid().'@test.com',
            'password' => 'password', 'role' => Role::STUDENT, 'supervisor_id' => $supervisor->id,
        ]);
    }

    protected function supervisor(string $email): User
    {
        return User::create(['name' => 'Supervisor', 'email' => $email, 'password' => 'password', 'role' => Role::SUPERVISOR]);
    }

    protected function graApplicationAtSupervisorStage(User $student): Application
    {
        $application = Application::create([
            'student_id' => $student->id, 'module_type' => 'gra_application',
            'status' => Application::STATUS_PENDING, 'current_stage' => 'supervisor', 'submitted_at' => now(),
        ]);

        GraApplicationDetail::create([
            'application_id' => $application->id,
            'profile_id' => GraGaStudentProfile::create(['user_id' => $student->id, 'level_of_study' => 'PhD', 'programme' => 'CS', 'mode_of_study' => 'full_time', 'admission_date' => now()])->id,
            'application_type' => 'new', 'candidature_status' => 'active',
            'project_leader_name' => 'Dr. Test', 'project_title' => 'Test', 'funding_source' => 'Internal',
            'service_start_date' => now(), 'service_end_date' => now()->addYears(2),
        ]);

        return $application;
    }

    protected function gaApplicationAtSupervisorStage(User $student): Application
    {
        $application = Application::create([
            'student_id' => $student->id, 'module_type' => 'ga_application',
            'status' => Application::STATUS_PENDING, 'current_stage' => 'supervisor', 'submitted_at' => now(),
        ]);

        GaApplicationDetail::create([
            'application_id' => $application->id,
            'profile_id' => GraGaStudentProfile::create(['user_id' => $student->id, 'level_of_study' => 'PhD', 'programme' => 'CS', 'mode_of_study' => 'full_time', 'admission_date' => now()])->id,
            'candidature_status' => 'active', 'department_unit' => 'CS Dept', 'purpose' => 'Teaching',
            'proposed_hours_per_week' => 10, 'interview_result' => 'pass', 'proposed_allowance' => 2000,
            'service_start_date' => now(), 'service_end_date' => now()->addYears(2),
        ]);

        return $application;
    }

    public function test_gra_queue_hides_a_student_not_under_this_supervisor(): void
    {
        $assigned = $this->supervisor('assigned-gra@test.com');
        $other = $this->supervisor('other-gra@test.com');
        $student = $this->student($assigned);
        $student->update(['name' => 'Carefully Named Student']);
        $this->graApplicationAtSupervisorStage($student);

        $this->actingAs($other)->get(route('gra-application.queue'))
            ->assertOk()
            ->assertDontSee('Carefully Named Student');

        $this->actingAs($assigned)->get(route('gra-application.queue'))
            ->assertOk()
            ->assertSee('Carefully Named Student');
    }

    public function test_gra_decide_refuses_a_supervisor_not_assigned_to_this_student(): void
    {
        $assigned = $this->supervisor('assigned-gra2@test.com');
        $other = $this->supervisor('other-gra2@test.com');
        $student = $this->student($assigned);
        $application = $this->graApplicationAtSupervisorStage($student);

        $this->actingAs($other)
            ->post(route('gra-application.decide', $application), ['decision' => 'approve'])
            ->assertForbidden();

        $this->actingAs($assigned)
            ->post(route('gra-application.decide', $application), ['decision' => 'approve'])
            ->assertRedirect();

        $this->assertSame('senior_director', $application->fresh()->current_stage);
    }

    public function test_ga_queue_hides_a_student_not_under_this_supervisor(): void
    {
        $assigned = $this->supervisor('assigned-ga@test.com');
        $other = $this->supervisor('other-ga@test.com');
        $student = $this->student($assigned);
        $student->update(['name' => 'Another Carefully Named Student']);
        $this->gaApplicationAtSupervisorStage($student);

        $this->actingAs($other)->get(route('ga-application.queue'))
            ->assertOk()
            ->assertDontSee('Another Carefully Named Student');

        $this->actingAs($assigned)->get(route('ga-application.queue'))
            ->assertOk()
            ->assertSee('Another Carefully Named Student');
    }

    public function test_ga_decide_refuses_a_supervisor_not_assigned_to_this_student(): void
    {
        $assigned = $this->supervisor('assigned-ga2@test.com');
        $other = $this->supervisor('other-ga2@test.com');
        $student = $this->student($assigned);
        $application = $this->gaApplicationAtSupervisorStage($student);

        $this->actingAs($other)
            ->post(route('ga-application.decide', $application), ['decision' => 'approve'])
            ->assertForbidden();

        $this->actingAs($assigned)
            ->post(route('ga-application.decide', $application), ['decision' => 'approve'])
            ->assertRedirect();

        $this->assertSame('senior_director', $application->fresh()->current_stage);
    }
}
