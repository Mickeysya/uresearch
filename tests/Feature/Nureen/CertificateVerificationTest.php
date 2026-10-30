<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\GaAppointmentRecord;
use App\Modules\Nureen\Models\GaCertificationDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Reference numbers, the public verification page, revocation, and the
 * automatic appointment check -- the supervisor's "make letters verifiable
 * and reduce manual checks" feedback.
 */
class CertificateVerificationTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function approvedCertification(int $seq = 1): Application
    {
        $supervisor = $this->user(Role::SUPERVISOR, [
            'name' => "Supervisor {$seq}", 'email' => "supervisor{$seq}@test.my",
        ]);
        $student = $this->student("9900100{$seq}", "student{$seq}@test.my", ['supervisor_id' => $supervisor->id]);
        $director = $this->user(Role::SENIOR_DIRECTOR_CGS, [
            'name' => "Director {$seq}", 'email' => "director{$seq}@test.my",
        ]);
        $cgs = $this->user(Role::NON_EXEC_CGS, [
            'name' => "CGS {$seq}", 'email' => "cgs{$seq}@test.my",
        ]);

        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_certification',
            'status' => Application::STATUS_DRAFT,
        ]);

        GaCertificationDetail::create([
            'application_id' => $application->id,
            'supervisor_id' => $supervisor->id,
            'appointment_type' => 'GA',
            'period_start' => now()->subYear(),
            'period_end' => now(),
            'purpose' => 'Scholarship application',
        ]);

        app(WorkflowEngine::class)->submit($application);
        app(WorkflowEngine::class)->decide($application, $supervisor, 'approve');
        app(WorkflowEngine::class)->decide($application, $cgs, 'approve');

        $this->actingAs($director)->post(route('ga-certification.decide', $application), ['decision' => 'approve']);

        return $application->refresh();
    }

    /* -----------------------------------------------------------------
     | Reference numbers
     |------------------------------------------------------------------*/

    public function test_final_approval_assigns_a_unique_formatted_reference_number(): void
    {
        Notification::fake();

        $application = $this->approvedCertification();
        $detail = GaCertificationDetail::where('application_id', $application->id)->sole();

        $this->assertMatchesRegularExpression('#^CGS/GACERT/\d{4}/\d{4}$#', $detail->reference_number);
    }

    public function test_successive_certificates_get_sequential_reference_numbers(): void
    {
        Notification::fake();

        $first = GaCertificationDetail::where('application_id', $this->approvedCertification(1)->id)->sole();
        $second = GaCertificationDetail::where('application_id', $this->approvedCertification(2)->id)->sole();

        $this->assertNotSame($first->reference_number, $second->reference_number);
    }

    /* -----------------------------------------------------------------
     | The public verification page
     |------------------------------------------------------------------*/

    public function test_the_verification_page_shows_the_four_public_facts_for_a_valid_certificate(): void
    {
        Notification::fake();

        $application = $this->approvedCertification();
        $detail = GaCertificationDetail::where('application_id', $application->id)->sole();

        // No login at all.
        $response = $this->get(route('certificate.verify', $detail->reference_number));

        $response->assertOk()
            ->assertSee($application->student->name)
            ->assertSee('Graduate Assistant')
            ->assertSee('Valid')
            ->assertSee($detail->reference_number);
    }

    public function test_an_unknown_reference_number_shows_a_clear_not_found_message(): void
    {
        $this->get(route('certificate.verify', 'CGS/GACERT/2026/9999'))
            ->assertOk()
            ->assertSee('No certificate was found');
    }

    public function test_a_revoked_certificate_shows_revoked_not_valid(): void
    {
        Notification::fake();

        $application = $this->approvedCertification();
        $detail = GaCertificationDetail::where('application_id', $application->id)->sole();

        $this->actingAs($this->cgs())
            ->post(route('ga-certification.revoke', $application), ['revoked_reason' => 'Issued in error.'])
            ->assertRedirect();

        $this->get(route('certificate.verify', $detail->reference_number))
            ->assertOk()
            ->assertSee('Revoked')
            ->assertDontSee('Valid');
    }

    /* -----------------------------------------------------------------
     | The Issued Certificates list -- where Revoke is actually reachable
     | once an application is no longer pending
     |------------------------------------------------------------------*/

    public function test_the_issued_list_shows_a_generated_certificate_and_its_revoke_form(): void
    {
        Notification::fake();

        $application = $this->approvedCertification();
        $detail = GaCertificationDetail::where('application_id', $application->id)->sole();

        $this->actingAs($this->cgs())
            ->get(route('ga-certification.issued'))
            ->assertOk()
            ->assertSee($detail->reference_number)
            ->assertSee('Valid')
            ->assertSee('Revoke Certificate');
    }

    public function test_a_student_cannot_open_the_issued_list(): void
    {
        $this->actingAs($this->student())
            ->get(route('ga-certification.issued'))
            ->assertForbidden();
    }

    /* -----------------------------------------------------------------
     | Revoking -- access control
     |------------------------------------------------------------------*/

    public function test_a_student_cannot_revoke_a_certificate(): void
    {
        Notification::fake();

        $application = $this->approvedCertification();

        $this->actingAs($this->student())
            ->post(route('ga-certification.revoke', $application), ['revoked_reason' => 'Trying my luck.'])
            ->assertForbidden();
    }

    public function test_revoking_requires_a_reason(): void
    {
        Notification::fake();

        $application = $this->approvedCertification();

        $this->actingAs($this->cgs())
            ->post(route('ga-certification.revoke', $application), ['revoked_reason' => ''])
            ->assertSessionHasErrors('revoked_reason');
    }

    public function test_revoking_does_not_change_the_applications_own_status(): void
    {
        Notification::fake();

        $application = $this->approvedCertification();

        $this->actingAs($this->cgs())
            ->post(route('ga-certification.revoke', $application), ['revoked_reason' => 'Issued in error.']);

        // The application stays approved -- revoking is about the
        // certificate's validity, not the workflow outcome.
        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
    }

    /* -----------------------------------------------------------------
     | The automatic appointment check
     |------------------------------------------------------------------*/

    public function test_the_appointment_check_reads_verified_against_a_matching_record(): void
    {
        config(['nureen.ga_certification.appointment_check_enabled' => true]);

        $student = $this->student(attributes: ['supervisor_id' => $this->supervisor()->id]);
        $this->certificationQueueApplication($student, 'GA');

        GaAppointmentRecord::create([
            'student_id' => $student->id,
            'appointment_type' => 'GA',
            'period_start' => now()->subYear(),
            'period_end' => now()->addYear(),
        ]);

        $this->actingAs($this->cgs())
            ->get(route('ga-certification.queue'))
            ->assertOk()
            ->assertSee('Verified');
    }

    public function test_the_appointment_check_reads_not_found_with_no_record_on_file(): void
    {
        config(['nureen.ga_certification.appointment_check_enabled' => true]);

        $student = $this->student();
        $this->certificationQueueApplication($student, 'GA');

        $this->actingAs($this->cgs())
            ->get(route('ga-certification.queue'))
            ->assertOk()
            ->assertSee('Not Found');
    }

    public function test_the_appointment_check_reads_mismatch_against_a_different_type(): void
    {
        config(['nureen.ga_certification.appointment_check_enabled' => true]);

        $student = $this->student();
        $this->certificationQueueApplication($student, 'GA');

        GaAppointmentRecord::create([
            'student_id' => $student->id,
            'appointment_type' => 'GRA',
            'period_start' => now()->subYear(),
            'period_end' => now()->addYear(),
        ]);

        $this->actingAs($this->cgs())
            ->get(route('ga-certification.queue'))
            ->assertOk()
            ->assertSee('Mismatch');
    }

    /** A pending-at-cgs_verify certification application the queue will list. */
    protected function certificationQueueApplication($student, string $appointmentType): Application
    {
        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_certification',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'cgs_verify',
            'submitted_at' => now(),
        ]);

        GaCertificationDetail::create([
            'application_id' => $application->id,
            'appointment_type' => $appointmentType,
            'period_start' => now()->subMonths(2),
            'period_end' => now()->addMonths(2),
            'purpose' => 'Test fixture.',
        ]);

        return $application;
    }
}
