<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Nureen\Models\GaExtensionDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The link this module supplies to Nureen's GA Extension --
 * Core\Contracts\SuppliesApprovedGaApplication, implemented by
 * Support\ApprovedGaApplicationProvider. Confirmed explicitly 2026-11-07:
 * a student may only apply once their ga_application is approved, and the
 * extension's "current end date" is read from it, never from the request.
 *
 * Nureen's own test suite (GaExtensionTest and friends) is NOT updated by
 * this change -- see TODO.md's 2026-11-07 entry for the 48 tests this
 * leaves failing there, left for the team to pick up.
 */
class GaExtensionLinkTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function studentWithSupervisor(): User
    {
        return $this->student(attributes: ['supervisor_id' => $this->supervisor()->id]);
    }

    protected function approvedGaApplication(User $student, string $serviceEndDate): Application
    {
        $profile = GraGaStudentProfile::create([
            'user_id' => $student->id,
            'level_of_study' => 'master',
            'programme' => 'MSc Data Science',
            'admission_date' => '2025-09-01',
            'bank_name' => 'Maybank',
            'bank_account_no' => '1234567890',
        ]);

        $application = Application::create([
            'student_id' => $student->id,
            'module_type' => 'ga_application',
            'status' => Application::STATUS_APPROVED,
            'submitted_at' => now(),
        ]);

        GaApplicationDetail::create([
            'application_id' => $application->id,
            'profile_id' => $profile->id,
            'service_start_date' => now()->subMonths(6),
            'service_end_date' => $serviceEndDate,
            'proposed_allowance' => 1500,
        ]);

        return $application;
    }

    public function test_ga_extension_is_unreachable_without_an_approved_ga_application(): void
    {
        $student = $this->studentWithSupervisor();

        $this->actingAs($student)->get(route('ga-extension.create'))->assertForbidden();
    }

    public function test_ga_extension_opens_once_the_ga_application_is_approved(): void
    {
        $student = $this->studentWithSupervisor();
        $this->approvedGaApplication($student, now()->addMonths(2)->toDateString());

        $this->actingAs($student)->get(route('ga-extension.create'))->assertOk()
            ->assertSee('Read from your approved GA application');
    }

    public function test_saving_a_draft_is_refused_without_an_approved_application(): void
    {
        $student = $this->studentWithSupervisor();

        $this->actingAs($student)->post(route('ga-extension.draft.save'), [])->assertForbidden();
    }

    public function test_submitting_is_refused_without_an_approved_application(): void
    {
        $student = $this->studentWithSupervisor();

        $this->actingAs($student)->post(route('ga-extension.store'), [])->assertForbidden();
    }

    public function test_the_current_end_date_is_read_from_the_approved_application_not_the_request(): void
    {
        $student = $this->studentWithSupervisor();
        $this->approvedGaApplication($student, '2027-03-31');

        // A tampered/stale value in the (hidden) field is ignored -- the
        // server always reads the live approved application instead.
        $this->actingAs($student)->post(route('ga-extension.draft.save'), [
            'current_end_date' => '1999-01-01',
        ])->assertRedirect();

        $draft = Application::where('module_type', 'ga_extension')->sole();
        $detail = GaExtensionDetail::where('application_id', $draft->id)->sole();

        $this->assertSame('2027-03-31', $detail->current_end_date->toDateString());
    }

    /**
     * Confirmed explicitly 2026-11-10: approving the extension has to
     * actually move the real ga_application's own service_end_date, or
     * approvedFor() (and everything that reads it -- this module's own
     * active-GA checks, Norhanis' Travel TA plan) keeps seeing the
     * original date forever. The write side of the same
     * SuppliesApprovedGaApplication relationship the read side already
     * used.
     */
    public function test_approving_the_extension_moves_the_real_ga_applications_end_date(): void
    {
        $student = $this->studentWithSupervisor();
        $gaApplication = $this->approvedGaApplication($student, '2027-03-31');
        $cgs = $this->cgs();

        $extensionApplication = Application::create([
            'student_id' => $student->id,
            'module_type' => 'ga_extension',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'cgs_verify',
            'submitted_at' => now(),
        ]);

        GaExtensionDetail::create([
            'application_id' => $extensionApplication->id,
            'supervisor_id' => $student->supervisor_id,
            'current_end_date' => '2027-03-31',
            'requested_new_end_date' => '2027-09-30',
            'reason_for_extension' => 'Continuing the same research project.',
            'final_approver_snapshot' => 'cgs',
        ]);

        $this->actingAs($cgs)
            ->post(route('ga-extension.decide', $extensionApplication), ['decision' => 'approve'])
            ->assertRedirect();

        $gaDetail = GaApplicationDetail::where('application_id', $gaApplication->id)->sole();
        $this->assertSame('2027-09-30', $gaDetail->service_end_date->toDateString());
    }
}
