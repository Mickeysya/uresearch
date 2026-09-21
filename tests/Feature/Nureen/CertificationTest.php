<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Notifications\CertificationIssued;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * GA/GRA Certification Letters — `docs/scope/nureen.md` Module 4.
 *
 * The scope asks for "generate, format, AND dispatch". Generation was there
 * from the start; dispatch is the half this covers, along with the letter
 * staying an ApplicationDocument behind the authorised download route rather
 * than becoming a loose file.
 */
class CertificationTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    public function test_final_approval_generates_the_letter_and_dispatches_it(): void
    {
        Notification::fake();

        $student = $this->student();
        $director = $this->seniorDirector();

        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_certification',
            'status' => Application::STATUS_DRAFT,
        ]);

        GaCertificationDetail::create([
            'application_id' => $application->id,
            'appointment_type' => 'GA',
            'period_start' => now()->subYear(),
            'period_end' => now(),
            'purpose' => 'Scholarship application',
        ]);

        app(WorkflowEngine::class)->submit($application);

        // Clear the first stage so the Senior Director's decision is final.
        app(WorkflowEngine::class)->decide($application, $this->cgs(), 'approve');

        $this->actingAs($director)
            ->post(route('ga-certification.decide', $application), ['decision' => 'approve'])
            ->assertRedirect();

        $application->refresh();
        $this->assertSame(Application::STATUS_APPROVED, $application->status);

        // Generated, stored as a real document behind the authorised route...
        $certificate = $application->documents()->sole();
        $this->assertSame('GA/GRA Certification Letter', $certificate->doc_type);

        // ...and actually sent, which is the half the scope asked for and the
        // generic decision email did not do.
        Notification::assertSentTo($student, CertificationIssued::class);
    }
}
