<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Haziq\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * GaApplicationWorkflow::calendarEvents() -- confirmed explicitly
 * 2026-11-10: "die yang set interview bile and keluar noti, and mark
 * terus kat calender student." The date Research Centre sets via
 * scheduleInterview() should land on the Calendar page, not just in the
 * GaInterviewScheduled email, for the student and for whichever Research
 * Centre reviewer is actually conducting it.
 */
class GaInterviewCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function researchCentreUser(string $department): User
    {
        return User::create([
            'name' => 'Research Centre', 'email' => 'rc'.uniqid().'@test.com', 'password' => 'password',
            'role' => Roles::RESEARCH_CENTRE, 'department' => $department,
        ]);
    }

    protected function gaApplicationWithInterviewOn(string $department, Carbon $interviewAt): Application
    {
        $student = User::create([
            'name' => 'Student', 'email' => 'student'.uniqid().'@test.com',
            'password' => 'password', 'role' => Role::STUDENT, 'department' => $department,
        ]);

        $application = Application::create([
            'student_id' => $student->id, 'module_type' => 'ga_application',
            'status' => Application::STATUS_PENDING, 'current_stage' => 'research_centre_interview', 'submitted_at' => now(),
        ]);

        GaApplicationDetail::create([
            'application_id' => $application->id,
            'profile_id' => GraGaStudentProfile::create(['user_id' => $student->id, 'level_of_study' => 'phd', 'programme' => 'CS', 'admission_date' => now()])->id,
            'interview_date' => $interviewAt,
            'proposed_hours_per_week' => 10, 'proposed_allowance' => 2000,
            'service_start_date' => now(), 'service_end_date' => now()->addYears(2),
        ]);

        return $application;
    }

    public function test_the_student_sees_their_own_scheduled_interview(): void
    {
        $application = $this->gaApplicationWithInterviewOn('Computing', Carbon::parse('2026-12-10 14:00'));

        $events = app(ModuleRegistry::class)->get('ga_application')
            ->calendarEvents($application->student, Carbon::parse('2026-12-01'), Carbon::parse('2026-12-31'));

        $this->assertCount(1, $events);
        $this->assertSame('Your GA interview', $events[0]['title']);
    }

    public function test_the_matching_departments_research_centre_reviewer_sees_it_named(): void
    {
        $application = $this->gaApplicationWithInterviewOn('Computing', Carbon::parse('2026-12-10 14:00'));
        $application->student->update(['name' => 'Calendar Test Student']);
        $reviewer = $this->researchCentreUser('Computing');

        $events = app(ModuleRegistry::class)->get('ga_application')
            ->calendarEvents($reviewer, Carbon::parse('2026-12-01'), Carbon::parse('2026-12-31'));

        $this->assertCount(1, $events);
        $this->assertSame('GA interview: Calendar Test Student', $events[0]['title']);
    }

    public function test_a_research_centre_reviewer_from_another_department_sees_nothing(): void
    {
        $this->gaApplicationWithInterviewOn('Computing', Carbon::parse('2026-12-10 14:00'));
        $otherReviewer = $this->researchCentreUser('Chemical Engineering');

        $events = app(ModuleRegistry::class)->get('ga_application')
            ->calendarEvents($otherReviewer, Carbon::parse('2026-12-01'), Carbon::parse('2026-12-31'));

        $this->assertCount(0, $events);
    }

    public function test_admin_cgs_and_supervisor_see_nothing_on_this_calendar(): void
    {
        $this->gaApplicationWithInterviewOn('Computing', Carbon::parse('2026-12-10 14:00'));

        foreach ([Role::ADMIN, Role::NON_EXEC_CGS, Role::SUPERVISOR] as $role) {
            $viewer = User::create(['name' => 'Viewer', 'email' => $role.uniqid().'@test.com', 'password' => 'password', 'role' => $role]);
            $events = app(ModuleRegistry::class)->get('ga_application')
                ->calendarEvents($viewer, Carbon::parse('2026-12-01'), Carbon::parse('2026-12-31'));

            $this->assertCount(0, $events, "Expected nothing for role {$role}");
        }
    }

    public function test_an_interview_outside_the_requested_range_is_not_returned(): void
    {
        $application = $this->gaApplicationWithInterviewOn('Computing', Carbon::parse('2027-03-10 14:00'));

        $events = app(ModuleRegistry::class)->get('ga_application')
            ->calendarEvents($application->student, Carbon::parse('2026-12-01'), Carbon::parse('2026-12-31'));

        $this->assertCount(0, $events);
    }
}
