<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Haziq\Assistant\AssistantContext;
use App\Modules\Haziq\Models\ApplicationCorrectionRequest;
use App\Modules\Haziq\Models\CandidatureAppeal;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Core\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The structural guarantees behind AssistantContext -- never what a
 * model is told to do with the text, but what the text is actually
 * built FROM. "Never includes another student's data" and "never
 * includes a pending allowance figure" are provable here because
 * there is no query that could ever pull them in, not because a
 * prompt asks the model nicely not to say them.
 */
class AssistantContextTest extends TestCase
{
    use RefreshDatabase;

    protected function student(string $email): User
    {
        return User::create(['name' => 'Student', 'email' => $email, 'password' => 'password', 'role' => Role::STUDENT]);
    }

    protected function profileFor(User $student): GraGaStudentProfile
    {
        return GraGaStudentProfile::create([
            'user_id' => $student->id, 'level_of_study' => 'PhD', 'programme' => 'Computer Science',
            'mode_of_study' => 'full_time', 'admission_date' => now()->subYear(),
        ]);
    }

    protected function graApplication(User $student, array $detail = [], string $status = Application::STATUS_APPROVED): Application
    {
        $application = Application::create([
            'student_id' => $student->id, 'module_type' => 'gra_application',
            'status' => $status, 'current_stage' => 'senior_director', 'submitted_at' => now(),
        ]);

        GraApplicationDetail::create($detail + [
            'application_id' => $application->id, 'profile_id' => $this->profileFor($student)->id,
            'application_type' => 'new', 'candidature_status' => 'active',
            'project_leader_name' => 'Dr. Test', 'project_title' => 'Test Project',
            'funding_source' => 'Internal', 'service_start_date' => now()->subMonth(),
            'service_end_date' => now()->addYears(3),
        ]);

        return $application;
    }

    public function test_it_never_includes_another_students_application(): void
    {
        $studentA = $this->student('a@test.com');
        $studentB = $this->student('b@test.com');

        $this->graApplication($studentA);
        $appB = $this->graApplication($studentB);

        $context = AssistantContext::forStudent($studentA);

        $this->assertStringNotContainsString($appB->reference(), $context);
    }

    public function test_a_pending_applications_allowance_amount_is_never_in_the_context_even_though_cgs_already_set_it(): void
    {
        $student = $this->student('pending@test.com');
        $this->graApplication($student, ['proposed_allowance' => 3500], Application::STATUS_PENDING);

        $context = AssistantContext::forStudent($student);

        $this->assertStringNotContainsString('3500', $context);
        $this->assertStringNotContainsString('3,500', $context);
    }

    public function test_a_student_with_no_applications_gets_a_plain_message_not_an_empty_string(): void
    {
        $student = $this->student('none@test.com');

        $context = AssistantContext::forStudent($student);

        $this->assertStringContainsString('No GRA or GA application on record.', $context);
    }

    public function test_open_correction_requests_are_included_but_resolved_ones_are_not(): void
    {
        $student = $this->student('correction@test.com');
        $application = $this->graApplication($student);

        ApplicationCorrectionRequest::create([
            'application_id' => $application->id, 'target_type' => 'section_b',
            'target_label' => 'Project Title', 'remarks' => 'Please clarify the scope',
            'requested_by' => $student->id, 'requested_at' => now(),
        ]);

        ApplicationCorrectionRequest::create([
            'application_id' => $application->id, 'target_type' => 'document',
            'target_label' => 'CV', 'remarks' => 'Old version attached',
            'requested_by' => $student->id, 'requested_at' => now()->subDays(3), 'resolved_at' => now(),
        ]);

        $context = AssistantContext::forStudent($student);

        $this->assertStringContainsString('Project Title', $context);
        $this->assertStringContainsString('Please clarify the scope', $context);
        $this->assertStringNotContainsString('Old version attached', $context);
    }

    public function test_a_candidature_appeals_status_and_decision_notes_are_included_when_one_exists(): void
    {
        $student = $this->student('appeal@test.com');
        $application = $this->graApplication($student);

        CandidatureAppeal::create([
            'application_id' => $application->id, 'student_id' => $student->id,
            'status' => 'approved', 'decided_at' => now(), 'decision_notes' => 'Appeal upheld on review',
        ]);

        $context = AssistantContext::forStudent($student);

        $this->assertStringContainsString('status=approved', $context);
        $this->assertStringContainsString('Appeal upheld on review', $context);
    }

    public function test_ga_interview_result_and_notes_are_included_when_present(): void
    {
        $student = $this->student('ga@test.com');
        $profile = $this->profileFor($student);

        $application = Application::create([
            'student_id' => $student->id, 'module_type' => 'ga_application',
            'status' => Application::STATUS_APPROVED, 'current_stage' => 'senior_director', 'submitted_at' => now(),
        ]);

        GaApplicationDetail::create([
            'application_id' => $application->id, 'profile_id' => $profile->id,
            'candidature_status' => 'active', 'department_unit' => 'CS Department',
            'purpose' => 'Teaching assistance', 'proposed_hours_per_week' => 10,
            'proposed_allowance' => 1500,
            'service_start_date' => now()->subMonth(), 'service_end_date' => now()->addYear(),
            'interview_result' => 'pass', 'interview_notes' => 'Strong candidate',
        ]);

        $context = AssistantContext::forStudent($student);

        $this->assertStringContainsString('pass', $context);
        $this->assertStringContainsString('Strong candidate', $context);
    }
}
