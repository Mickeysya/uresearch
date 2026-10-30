<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Notifications\CertificationIssued;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * GA/GRA Certification Letters — `docs/scope/nureen.md` Module 4, realigned
 * to CGS's confirmed UResearch process.
 *
 * The chain gained a new first stage, addressed to the student's own named
 * supervisor (CertificationLetterWorkflow::stages()), and the form now asks
 * for type-specific fields instead of one generic "purpose" -- a GA
 * certifies against a course, a GRA against a research project (see
 * config('nureen.ga_certification.fields')).
 */
class CertificationTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    protected function gaPayload(array $overrides = []): array
    {
        return $overrides + [
            'appointment_type' => 'GA',
            'period_start' => now()->subYear()->toDateString(),
            'period_end' => now()->toDateString(),
            'course_code' => 'CCB2012',
            'course_name' => 'Process Control',
            'course_owner_name' => 'Dr. Aisyah Rahman',
            'semester' => 'Sept 2025',
            'supporting_documents' => [UploadedFile::fake()->create('evidence.pdf', 80, 'application/pdf')],
        ];
    }

    /** @return array<string, mixed> */
    protected function graPayload(array $overrides = []): array
    {
        return $overrides + [
            'appointment_type' => 'GRA',
            'period_start' => now()->subYear()->toDateString(),
            'period_end' => now()->toDateString(),
            'project_title' => 'Reservoir Simulation under Uncertainty',
            'project_leader_name' => 'Dr. Aisyah Rahman',
            'semester' => 'Sept 2025',
            'supporting_documents' => [UploadedFile::fake()->create('evidence.pdf', 80, 'application/pdf')],
        ];
    }

    public function test_submission_is_blocked_when_the_student_has_no_supervisor(): void
    {
        Storage::fake('local');

        $student = $this->student();
        $this->assertNull($student->supervisor_id);

        $this->actingAs($student)
            ->post(route('ga-certification.store'), $this->gaPayload())
            ->assertRedirect();

        $this->assertSame(0, Application::where('module_type', 'ga_certification')->count());
    }

    public function test_a_ga_request_shows_and_validates_only_ga_fields(): void
    {
        Storage::fake('local');

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        // GRA-only field present on a GA submission is simply ignored --
        // not validated, not stored.
        $this->actingAs($student)
            ->post(route('ga-certification.store'), $this->gaPayload(['project_title' => 'Should be ignored']))
            ->assertRedirect(route('applications.index'));

        $detail = GaCertificationDetail::sole();
        $this->assertSame('CCB2012', $detail->course_code);
        $this->assertNull($detail->project_title);
        $this->assertSame($supervisor->id, $detail->supervisor_id);
        $this->assertSame('supervisor', $detail->application->current_stage);
    }

    public function test_a_gra_request_shows_and_validates_only_gra_fields(): void
    {
        Storage::fake('local');

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $this->actingAs($student)
            ->post(route('ga-certification.store'), $this->graPayload())
            ->assertRedirect(route('applications.index'));

        $detail = GaCertificationDetail::sole();
        $this->assertSame('Reservoir Simulation under Uncertainty', $detail->project_title);
        $this->assertNull($detail->course_code);
    }

    public function test_a_ga_request_missing_a_required_type_field_is_rejected(): void
    {
        Storage::fake('local');

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $this->actingAs($student)
            ->post(route('ga-certification.store'), $this->gaPayload(['course_code' => null]))
            ->assertSessionHasErrors('course_code');

        $this->assertSame(0, Application::where('module_type', 'ga_certification')->count());
    }

    public function test_purpose_is_optional(): void
    {
        Storage::fake('local');

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $this->actingAs($student)
            ->post(route('ga-certification.store'), $this->gaPayload())
            ->assertRedirect(route('applications.index'));

        $this->assertNull(GaCertificationDetail::sole()->purpose);
    }

    public function test_the_supervisor_stage_is_addressed_to_the_students_own_named_supervisor(): void
    {
        Storage::fake('local');

        $named = $this->supervisor('named@test.my');
        $other = $this->supervisor('other@test.my');
        $student = $this->student(attributes: ['supervisor_id' => $named->id]);

        $this->actingAs($student)->post(route('ga-certification.store'), $this->gaPayload());
        $application = Application::where('module_type', 'ga_certification')->sole();

        $this->actingAs($other)
            ->post(route('ga-certification.decide', $application), ['decision' => 'approve'])
            ->assertForbidden();

        $this->actingAs($named)
            ->post(route('ga-certification.decide', $application), ['decision' => 'approve']);

        $this->assertSame('cgs_verify', $application->fresh()->current_stage);
    }

    public function test_the_chain_needs_supervisor_cgs_and_senior_director(): void
    {
        Storage::fake('local');
        Notification::fake();

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);
        $cgs = $this->cgs();
        $director = $this->seniorDirector();

        $this->actingAs($student)->post(route('ga-certification.store'), $this->gaPayload());
        $application = Application::where('module_type', 'ga_certification')->sole();

        $this->actingAs($supervisor)->post(route('ga-certification.decide', $application), ['decision' => 'approve']);
        $application->refresh();
        $this->assertSame('cgs_verify', $application->current_stage);

        $this->actingAs($cgs)->post(route('ga-certification.decide', $application), ['decision' => 'approve']);
        $application->refresh();
        $this->assertSame('senior_director', $application->current_stage);

        $this->actingAs($director)->post(route('ga-certification.decide', $application), ['decision' => 'approve']);

        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);

        $certificate = $application->documents()->where('doc_type', 'GA/GRA Certification Letter')->sole();
        $this->assertSame('GA/GRA Certification Letter', $certificate->doc_type);
        Notification::assertSentTo($student, CertificationIssued::class);
    }

    public function test_an_in_flight_application_submitted_before_the_supervisor_stage_still_ends_at_senior_director(): void
    {
        Storage::fake('local');
        Notification::fake();

        $student = $this->student();
        $director = $this->seniorDirector();

        // Built the way a pre-existing row would be: no supervisor_id at
        // all, sitting at cgs_verify, as every ga_certification row did
        // before this module had a supervisor stage.
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
            'appointment_type' => 'GA',
            'period_start' => now()->subYear(),
            'period_end' => now(),
            'course_code' => 'CCB2012',
            'course_name' => 'Process Control',
            'course_owner_name' => 'Dr. Aisyah Rahman',
            'semester' => 'Sept 2025',
        ]);

        // Still found and decidable exactly where it is -- inserting a
        // stage before cgs_verify in the declared chain does not require
        // this row to pass through it.
        app(WorkflowEngine::class)->decide($application, $this->cgs(), 'approve');

        $application->refresh();
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('senior_director', $application->current_stage);

        $this->actingAs($director)
            ->post(route('ga-certification.decide', $application), ['decision' => 'approve'])
            ->assertRedirect();

        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
    }

    public function test_appointment_check_is_hidden_from_the_queue_when_disabled(): void
    {
        Storage::fake('local');

        config(['nureen.ga_certification.appointment_check_enabled' => false]);

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $this->actingAs($student)->post(route('ga-certification.store'), $this->gaPayload());
        $application = Application::where('module_type', 'ga_certification')->sole();

        app(WorkflowEngine::class)->decide($application, $supervisor, 'approve');

        $this->actingAs($this->cgs())
            ->get(route('ga-certification.queue'))
            ->assertOk()
            ->assertDontSee('Appointment check');
    }

    public function test_appointment_check_shows_on_the_queue_when_enabled(): void
    {
        Storage::fake('local');

        config(['nureen.ga_certification.appointment_check_enabled' => true]);

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $this->actingAs($student)->post(route('ga-certification.store'), $this->gaPayload());
        $application = Application::where('module_type', 'ga_certification')->sole();

        app(WorkflowEngine::class)->decide($application, $supervisor, 'approve');

        $this->actingAs($this->cgs())
            ->get(route('ga-certification.queue'))
            ->assertOk()
            ->assertSee('Appointment check');
    }

    public function test_charter_days_are_readable_from_config(): void
    {
        $this->assertSame([
            'supervisor' => 3,
            'cgs_verify' => 2,
            'senior_director' => 2,
        ], config('nureen.charter_days.ga_certification'));
    }
}
