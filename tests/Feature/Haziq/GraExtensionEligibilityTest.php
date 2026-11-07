<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Haziq\Models\GraTermsAcceptance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * GRA Extension eligibility, confirmed explicitly 2026-11-07: no longer
 * gated on the current service period having actually ended -- applicable
 * any time once the GRA application is approved. Replaces the old
 * "boleh isi bila dah end service date je" rule (2026-11).
 */
class GraExtensionEligibilityTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function studentWithTermsAccepted(string $matric = '22001001', string $email = 'student@test.my'): User
    {
        $student = $this->student($matric, $email);
        GraTermsAcceptance::create(['user_id' => $student->id, 'accepted_at' => now()]);

        return $student;
    }

    protected function graApplication(User $student, string $status, array $overrides = []): Application
    {
        $profile = GraGaStudentProfile::firstOrCreate(
            ['user_id' => $student->id],
            [
                'level_of_study' => 'phd',
                'programme' => 'PhD Petroleum Engineering',
                'admission_date' => '2024-09-01',
                'bank_name' => 'Maybank',
                'bank_account_no' => '1234567890',
            ]
        );

        $application = Application::create([
            'student_id' => $student->id,
            'module_type' => 'gra_application',
            'status' => $status,
            'current_stage' => $status === Application::STATUS_PENDING ? 'admin_verify' : null,
            'submitted_at' => now(),
        ]);

        GraApplicationDetail::create($overrides + [
            'application_id' => $application->id,
            'profile_id' => $profile->id,
            'application_type' => 'new',
            'project_leader_name' => 'Dr. Lim',
            'project_title' => 'Reservoir Simulation under Uncertainty',
            'funding_source' => 'UTP Internal Grant',
            // Two months in the FUTURE -- the old rule would have blocked
            // this; the new rule does not care.
            'service_start_date' => now()->subMonth(),
            'service_end_date' => now()->addMonths(2),
        ]);

        return $application;
    }

    public function test_an_approved_application_can_be_extended_even_though_its_service_period_has_not_ended(): void
    {
        $student = $this->studentWithTermsAccepted();
        $parent = $this->graApplication($student, Application::STATUS_APPROVED);

        $this->actingAs($student)->get(route('gra-application.extension.create'))->assertOk();

        $this->actingAs($student)->post(route('gra-application.extension.store'), [
            'parent_application_id' => $parent->id,
            'project_leader_name' => 'Dr. Lim',
            'project_title' => 'Reservoir Simulation under Uncertainty',
            'funding_source' => 'UTP Internal Grant',
            'funding_continuation' => 'existing',
            'requested_new_end_date' => now()->addMonths(50)->toDateString(),
            'extension_reason' => 'Fieldwork slipped two semesters and needs the assistantship to continue.',
        ])->assertRedirect(route('applications.index'));

        $this->assertSame(2, Application::where('module_type', 'gra_application')->count());
    }

    public function test_a_pending_applications_extension_form_is_not_reachable(): void
    {
        $student = $this->studentWithTermsAccepted();
        $this->graApplication($student, Application::STATUS_PENDING);

        $this->actingAs($student)->get(route('gra-application.extension.create'))->assertNotFound();
    }

    public function test_posting_directly_against_a_pending_applications_id_is_refused(): void
    {
        $student = $this->studentWithTermsAccepted();
        $pending = $this->graApplication($student, Application::STATUS_PENDING);

        // This lookup has no status filter of its own -- canBeExtended()
        // (now reading application->status) is the only thing stopping
        // this direct POST from extending a not-yet-approved application.
        $this->actingAs($student)->post(route('gra-application.extension.store'), [
            'parent_application_id' => $pending->id,
            'project_leader_name' => 'Dr. Lim',
            'project_title' => 'Reservoir Simulation under Uncertainty',
            'funding_source' => 'UTP Internal Grant',
            'funding_continuation' => 'existing',
            'requested_new_end_date' => now()->addMonths(50)->toDateString(),
            'extension_reason' => 'Attempting to extend a pending application directly.',
        ])->assertForbidden();

        $this->assertSame(1, Application::where('module_type', 'gra_application')->count());
    }

    public function test_posting_directly_against_a_rejected_applications_id_is_refused(): void
    {
        $student = $this->studentWithTermsAccepted();
        $rejected = $this->graApplication($student, Application::STATUS_REJECTED);

        $this->actingAs($student)->post(route('gra-application.extension.store'), [
            'parent_application_id' => $rejected->id,
            'project_leader_name' => 'Dr. Lim',
            'project_title' => 'Reservoir Simulation under Uncertainty',
            'funding_source' => 'UTP Internal Grant',
            'funding_continuation' => 'existing',
            'requested_new_end_date' => now()->addMonths(50)->toDateString(),
            'extension_reason' => 'Attempting to extend a rejected application directly.',
        ])->assertForbidden();

        $this->assertSame(1, Application::where('module_type', 'gra_application')->count());
    }
}
