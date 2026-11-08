<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Http\Controllers\GaApplicationController;
use App\Modules\Haziq\Http\Controllers\GraApplicationController;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Haziq\Models\StageGate;
use App\Modules\Haziq\Models\StageGateRule;
use App\Modules\Haziq\Support\StageGateEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * GRA and GA look almost identical on the surface but diverge on
 * approval in a way that is easy to accidentally un-diverge later:
 * only a new (not extension) GRA appointment gets a Stage Gate
 * schedule at all. GA never does -- confirmed explicitly 2026-11-03,
 * "after approve takdelah stage gate or allowance bagai bagai tu, die
 * just application approve." Invokes the real onApproved() methods
 * directly (bypassing the multi-stage decide() chain's own
 * incidental validation) so this test isolates exactly that one
 * divergence.
 */
class ApprovalStageGateWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['queue.default' => 'sync']);
        StageGateRule::create(['level_of_study' => 'PhD', 'stage_order' => 1, 'stage_name' => 'RPD', 'requirement_description' => 'x', 'deadline_month' => 3, 'grace_end_month' => 6, 'recoverable' => true]);
    }

    protected function student(): User
    {
        return User::create(['name' => 'Student', 'email' => 'student@test.com', 'password' => 'password', 'role' => Role::STUDENT]);
    }

    protected function profile(User $student): GraGaStudentProfile
    {
        return GraGaStudentProfile::create(['user_id' => $student->id, 'level_of_study' => 'PhD', 'programme' => 'CS', 'mode_of_study' => 'full_time', 'admission_date' => now()]);
    }

    public function test_approving_a_new_gra_application_generates_a_stage_gate_schedule(): void
    {
        $student = $this->student();
        $profile = $this->profile($student);
        $application = Application::create(['student_id' => $student->id, 'module_type' => 'gra_application', 'status' => Application::STATUS_APPROVED, 'current_stage' => null, 'submitted_at' => now()]);

        GraApplicationDetail::create([
            'application_id' => $application->id, 'profile_id' => $profile->id, 'application_type' => 'new',
            'candidature_status' => 'active', 'project_leader_name' => 'Dr. Test', 'project_title' => 'Test',
            'funding_source' => 'Internal', 'service_start_date' => now(), 'service_end_date' => now()->addYears(3),
            'proposed_allowance' => 2000,
        ]);

        $method = new ReflectionMethod(GraApplicationController::class, 'onApproved');
        $method->setAccessible(true);
        $method->invoke(new GraApplicationController, $application, $student, app(DocumentStore::class), new StageGateEngine);

        $this->assertGreaterThan(0, StageGate::where('application_id', $application->id)->count());
    }

    public function test_approving_a_gra_extension_does_not_regenerate_the_schedule(): void
    {
        $student = $this->student();
        $profile = $this->profile($student);
        $application = Application::create(['student_id' => $student->id, 'module_type' => 'gra_application', 'status' => Application::STATUS_APPROVED, 'current_stage' => null, 'submitted_at' => now()]);

        GraApplicationDetail::create([
            'application_id' => $application->id, 'profile_id' => $profile->id, 'application_type' => 'extension',
            'candidature_status' => 'active', 'project_leader_name' => 'Dr. Test', 'project_title' => 'Test',
            'funding_source' => 'Internal', 'service_start_date' => now(), 'service_end_date' => now()->addYear(),
            'proposed_allowance' => 2000,
        ]);

        $method = new ReflectionMethod(GraApplicationController::class, 'onApproved');
        $method->setAccessible(true);
        $method->invoke(new GraApplicationController, $application, $student, app(DocumentStore::class), new StageGateEngine);

        $this->assertSame(0, StageGate::where('application_id', $application->id)->count());
    }

    public function test_approving_a_ga_application_never_generates_a_stage_gate_schedule(): void
    {
        $student = $this->student();
        $profile = $this->profile($student);
        $application = Application::create(['student_id' => $student->id, 'module_type' => 'ga_application', 'status' => Application::STATUS_APPROVED, 'current_stage' => null, 'submitted_at' => now()]);

        GaApplicationDetail::create([
            'application_id' => $application->id, 'profile_id' => $profile->id, 'candidature_status' => 'active',
            'department_unit' => 'CS', 'purpose' => 'Teaching', 'proposed_hours_per_week' => 10,
            'service_start_date' => now(), 'service_end_date' => now()->addYear(), 'proposed_allowance' => 1500,
            'interview_result' => 'pass',
        ]);

        $method = new ReflectionMethod(GaApplicationController::class, 'onApproved');
        $method->setAccessible(true);
        $method->invoke(new GaApplicationController, $application, app(DocumentStore::class));

        $this->assertSame(0, StageGate::where('application_id', $application->id)->count());
    }
}
