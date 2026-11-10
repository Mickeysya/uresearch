<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Haziq\Notifications\ApplicationRejected;
use App\Modules\Haziq\Notifications\GaInterviewFailed;
use App\Modules\Haziq\Notifications\GaInterviewPassed;
use App\Modules\Haziq\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A Fail already told the student outright (ApplicationRejected); a Pass
 * used to leave the student to find out only by checking the page --
 * notifyNextApprover() only ever reached CGS. Confirmed explicitly
 * 2026-11-10. See GaInterviewPassed's own docblock.
 */
class GaInterviewOutcomeNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function researchCentreUser(string $department): User
    {
        return User::create([
            'name' => 'Research Centre', 'email' => 'rc'.uniqid().'@test.com', 'password' => 'password',
            'role' => Roles::RESEARCH_CENTRE, 'department' => $department,
        ]);
    }

    protected function gaApplicationAtResearchCentreStage(string $department): Application
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
            'interview_date' => now(),
            'proposed_hours_per_week' => 10, 'proposed_allowance' => 2000,
            'service_start_date' => now(), 'service_end_date' => now()->addYears(2),
        ]);

        return $application;
    }

    public function test_passing_the_interview_notifies_the_student_and_advances_to_cgs(): void
    {
        Notification::fake();
        $reviewer = $this->researchCentreUser('Computing');
        $application = $this->gaApplicationAtResearchCentreStage('Computing');
        $student = $application->student;

        $this->actingAs($reviewer)
            ->post(route('ga-application.decide', $application), ['decision' => 'approve'])
            ->assertRedirect();

        $this->assertSame('cgs_review', $application->fresh()->current_stage);
        Notification::assertSentTo($student, GaInterviewPassed::class);
        Notification::assertNotSentTo($student, ApplicationRejected::class);
    }

    public function test_failing_the_interview_rejects_outright_and_notifies_student_and_stakeholders(): void
    {
        Notification::fake();
        $reviewer = $this->researchCentreUser('Computing');
        $admin = User::create(['name' => 'Admin', 'email' => 'admin-interview@test.com', 'password' => 'password', 'role' => Role::ADMIN]);
        $application = $this->gaApplicationAtResearchCentreStage('Computing');
        $student = $application->student;

        $this->actingAs($reviewer)
            ->post(route('ga-application.decide', $application), ['decision' => 'reject', 'interview_notes' => 'Did not meet the bar'])
            ->assertRedirect();

        $this->assertSame(Application::STATUS_REJECTED, $application->fresh()->status);
        Notification::assertSentTo($student, ApplicationRejected::class);
        Notification::assertNotSentTo($student, GaInterviewPassed::class);
        Notification::assertSentTo($admin, GaInterviewFailed::class);
    }
}
