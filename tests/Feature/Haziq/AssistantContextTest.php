<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Haziq\Assistant\AssistantContext;
use App\Modules\Haziq\Models\ApplicationCorrectionRequest;
use App\Modules\Haziq\Models\AssistantLog;
use App\Modules\Haziq\Models\CandidatureAppeal;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Haziq\Models\StageGate;
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

    public function test_missing_required_documents_are_listed_by_name_for_a_new_application(): void
    {
        $student = $this->student('missingdocs@test.com');
        $this->graApplication($student, status: Application::STATUS_PENDING);

        $context = AssistantContext::forStudent($student);

        $this->assertStringContainsString('Still missing these required documents:', $context);
        $this->assertStringContainsString('Resume/CV', $context);
    }

    public function test_missing_documents_are_never_reported_for_a_gra_extension(): void
    {
        $student = $this->student('extensiondocs@test.com');
        $this->graApplication($student, ['application_type' => 'extension'], Application::STATUS_PENDING);

        $context = AssistantContext::forStudent($student);

        $this->assertStringNotContainsString('Still missing these required documents:', $context);
    }

    public function test_a_stage_gates_own_requirement_is_included_not_just_its_name(): void
    {
        $student = $this->student('requirement@test.com');
        $application = $this->graApplication($student);

        StageGate::create([
            'application_id' => $application->id, 'stage_order' => 1, 'stage_name' => 'RPD Completion',
            'requirement_description' => 'Research Progress Defense (RPD) completed.',
            'deadline_date' => now()->addMonths(3), 'grace_end_date' => now()->addMonths(6),
            'completion_status' => 'not_recorded', 'timeline_status' => 'upcoming', 'allowance_status' => 'eligible',
        ]);

        $context = AssistantContext::forStudent($student);

        $this->assertStringContainsString('requires "Research Progress Defense (RPD) completed."', $context);
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

    /* -----------------------------------------------------------------
     | Conversation history -- "store all the prompt, know everything
     | about the student (solely only to them) with their prompt"
     |------------------------------------------------------------------*/

    public function test_a_students_own_earlier_question_and_answer_are_included(): void
    {
        $student = $this->student('history@test.com');

        AssistantLog::create([
            'student_id' => $student->id, 'question' => 'When is my Stage 1 deadline?',
            'answer' => '01 Dec 2026.', 'outcome' => 'ok',
        ]);

        $context = AssistantContext::forStudent($student);

        $this->assertStringContainsString('When is my Stage 1 deadline?', $context);
        $this->assertStringContainsString('01 Dec 2026.', $context);
    }

    public function test_it_never_includes_another_students_earlier_questions(): void
    {
        $studentA = $this->student('historya@test.com');
        $studentB = $this->student('historyb@test.com');

        AssistantLog::create([
            'student_id' => $studentB->id, 'question' => "What is studentB's secret allowance figure?",
            'answer' => 'RM9,999.00.', 'outcome' => 'ok',
        ]);

        $context = AssistantContext::forStudent($studentA);

        $this->assertStringNotContainsString('secret allowance figure', $context);
        $this->assertStringNotContainsString('9,999', $context);
    }

    public function test_a_failed_attempt_is_never_recalled_as_history(): void
    {
        $student = $this->student('failed@test.com');

        AssistantLog::create([
            'student_id' => $student->id, 'question' => 'Why did this fail?',
            'answer' => null, 'outcome' => 'unavailable', 'error_code' => 'no_credentials',
        ]);

        $context = AssistantContext::forStudent($student);

        $this->assertStringNotContainsString('Why did this fail?', $context);
    }

    public function test_only_the_most_recent_history_limit_exchanges_are_carried_and_in_order(): void
    {
        $student = $this->student('many@test.com');

        for ($i = 1; $i <= AssistantContext::HISTORY_LIMIT + 2; $i++) {
            AssistantLog::create([
                'student_id' => $student->id, 'question' => "Question number {$i}?",
                'answer' => "Answer number {$i}.", 'outcome' => 'ok',
            ]);
        }

        $context = AssistantContext::forStudent($student);

        $this->assertStringNotContainsString('Question number 1?', $context);
        $this->assertStringNotContainsString('Question number 2?', $context);

        $oldestKept = strpos($context, 'Question number 3?');
        $newestKept = strpos($context, 'Question number 7?');
        $this->assertNotFalse($oldestKept);
        $this->assertNotFalse($newestKept);
        $this->assertLessThan($newestKept, $oldestKept, 'oldest-kept question should read before the newest, not after');
    }
}
