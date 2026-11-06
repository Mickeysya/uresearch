<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Models\CandidatureAppeal;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Haziq\Models\StageGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The only way back from a Stage 2/3 termination -- confirmed
 * explicitly 2026-10-15 that only a Senior Director may decide it, and
 * that approving one reinstates candidature_status only, never
 * retroactively touching timeline_status/allowance_status or any past
 * month's log row.
 */
class CandidatureAppealControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function student(string $candidatureStatus = 'terminated'): User
    {
        $student = User::create(['name' => 'Student', 'email' => 'student@test.com', 'password' => 'password', 'role' => Role::STUDENT]);

        $application = Application::create(['student_id' => $student->id, 'module_type' => 'gra_application', 'status' => Application::STATUS_APPROVED, 'current_stage' => 'senior_director', 'submitted_at' => now()]);

        GraApplicationDetail::create([
            'application_id' => $application->id,
            'profile_id' => GraGaStudentProfile::create(['user_id' => $student->id, 'level_of_study' => 'PhD', 'programme' => 'CS', 'mode_of_study' => 'full_time', 'admission_date' => now()])->id,
            'application_type' => 'new', 'candidature_status' => $candidatureStatus,
            'project_leader_name' => 'Dr. Test', 'project_title' => 'Test', 'funding_source' => 'Internal',
            'service_start_date' => now()->subYear(), 'service_end_date' => now()->addYears(2), 'proposed_allowance' => 2000,
        ]);

        $student->application = $application;

        return $student;
    }

    protected function seniorDirector(): User
    {
        return User::create(['name' => 'SD', 'email' => 'sd@test.com', 'password' => 'password', 'role' => Role::SENIOR_DIRECTOR_CGS]);
    }

    protected function appeal(Application $application, User $student): CandidatureAppeal
    {
        return CandidatureAppeal::create(['application_id' => $application->id, 'student_id' => $student->id, 'status' => 'pending']);
    }

    // ---- who may even file one -----------------------------------------

    public function test_a_student_cannot_appeal_an_application_that_is_not_terminated(): void
    {
        $student = $this->student('active');

        $this->actingAs($student)
            ->get(route('candidature-appeal.create', $student->application))
            ->assertNotFound();
    }

    public function test_a_second_appeal_cannot_be_filed_while_one_is_still_pending(): void
    {
        $student = $this->student();
        $this->appeal($student->application, $student);

        $response = $this->actingAs($student)->post(route('candidature-appeal.store', $student->application), [
            'appeal_letter' => UploadedFile::fake()->create('letter.pdf', 100, 'application/pdf'),
        ]);

        $response->assertRedirect();
        $this->assertSame(1, CandidatureAppeal::where('application_id', $student->application->id)->count());
    }

    // ---- deciding ---------------------------------------------------------

    public function test_only_a_senior_director_can_decide_an_appeal(): void
    {
        $student = $this->student();
        $appeal = $this->appeal($student->application, $student);
        $cgs = User::create(['name' => 'CGS', 'email' => 'cgs@test.com', 'password' => 'x', 'role' => Role::NON_EXEC_CGS]);

        $this->actingAs($cgs)
            ->post(route('candidature-appeal.decide', $appeal), ['decision' => 'approve'])
            ->assertForbidden();
    }

    public function test_an_already_decided_appeal_cannot_be_decided_again(): void
    {
        $student = $this->student();
        $appeal = $this->appeal($student->application, $student);
        $appeal->update(['status' => 'approved', 'decided_at' => now()]);

        $this->actingAs($this->seniorDirector())
            ->post(route('candidature-appeal.decide', $appeal), ['decision' => 'reject'])
            ->assertNotFound();
    }

    public function test_rejecting_an_appeal_requires_a_reason(): void
    {
        $student = $this->student();
        $appeal = $this->appeal($student->application, $student);

        $this->actingAs($this->seniorDirector())
            ->post(route('candidature-appeal.decide', $appeal), ['decision' => 'reject'])
            ->assertInvalid(['remarks']);
    }

    public function test_approving_an_appeal_reinstates_candidature_but_never_touches_a_gates_own_timeline_or_allowance_status(): void
    {
        $student = $this->student();
        $application = $student->application;
        $appeal = $this->appeal($application, $student);

        $gate = StageGate::create([
            'application_id' => $application->id, 'stage_order' => 2, 'stage_name' => 'Journal',
            'requirement_description' => 'x', 'deadline_date' => now()->subMonths(4), 'grace_end_date' => now()->subMonths(2),
            'timeline_status' => 'grace_expired', 'allowance_status' => 'forfeited',
        ]);

        $this->actingAs($this->seniorDirector())
            ->post(route('candidature-appeal.decide', $appeal), ['decision' => 'approve'])
            ->assertRedirect();

        $this->assertSame('approved', $appeal->refresh()->status);
        $this->assertSame('active', GraApplicationDetail::where('application_id', $application->id)->first()->candidature_status);
        $this->assertSame('grace_expired', $gate->refresh()->timeline_status, 'an appeal reinstates candidature only, never a gates own timeline');
        $this->assertSame('forfeited', $gate->refresh()->allowance_status, 'recovering a gates allowance is recordCompletion()s job, not an appeals');
    }

    public function test_rejecting_an_appeal_leaves_candidature_terminated(): void
    {
        $student = $this->student();
        $appeal = $this->appeal($student->application, $student);

        $this->actingAs($this->seniorDirector())
            ->post(route('candidature-appeal.decide', $appeal), ['decision' => 'reject', 'remarks' => 'Not sufficient grounds'])
            ->assertRedirect();

        $this->assertSame('rejected', $appeal->refresh()->status);
        $this->assertSame('terminated', GraApplicationDetail::where('application_id', $student->application->id)->first()->candidature_status);
    }
}
