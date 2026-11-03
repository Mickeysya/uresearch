<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Nureen\Models\GaCertificationDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * GA/GRA Certification against the Postgraduate Handbook 2025: GA and GRA
 * are for full-time research students. Choosing Coursework, Part-time or
 * Full-time ODL gets a note on the form and a warning in CGS's System
 * checks -- never a block, because a former GA may still need a letter.
 */
class CertificationHandbookTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected function payload(array $overrides = []): array
    {
        return $overrides + [
            'appointment_type' => 'GA',
            'study_level' => 'research',
            'level_of_study' => 'phd',
            'mode_of_study' => 'full_time',
            'identity_number' => '900101-08-1234',
            'personal_email' => 'student.personal@gmail.test',
            'declaration' => '1',
            'period_start' => now()->subYear()->toDateString(),
            'period_end' => now()->toDateString(),
            'course_code' => 'CCB2012',
            'course_name' => 'Process Control',
            'course_owner_name' => 'Dr. Aisyah Rahman',
            'semester' => 'Sept 2025',
            'supporting_documents' => [UploadedFile::fake()->create('evidence.pdf', 80, 'application/pdf')],
        ];
    }

    public function test_a_full_time_research_student_passes_the_check(): void
    {
        foreach (['phd', 'master'] as $level) {
            $check = (new GaCertificationDetail)->forceFill(['study_level' => 'research', 'mode_of_study' => 'full_time', 'level_of_study' => $level])->eligibilityCheck();
            $this->assertSame('pass', $check['status']);
            $this->assertSame('Research · Full-time.', $check['reason']);
        }
    }

    public function test_coursework_part_time_or_odl_is_warned_about(): void
    {
        foreach ([['coursework', 'full_time'], ['research', 'part_time'], ['research', 'full_time_odl']] as [$type, $mode]) {
            $check = (new GaCertificationDetail)->forceFill(['study_level' => $type, 'mode_of_study' => $mode])->eligibilityCheck();
            $this->assertSame('fail', $check['status'], "{$type} {$mode}");
            $this->assertStringContainsString('A former GA may still legitimately need a letter', $check['reason']);
        }

        $this->assertSame('unknown', (new GaCertificationDetail)->eligibilityCheck()['status']);
    }

    public function test_a_part_time_student_can_still_submit_and_cgs_sees_the_warning(): void
    {
        Storage::fake('local');
        Notification::fake();
        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $this->actingAs($student)->post(route('ga-certification.store'), $this->payload(['mode_of_study' => 'part_time']))
            ->assertSessionHasNoErrors();
        $application = Application::sole();
        $this->assertSame(Application::STATUS_PENDING, $application->status);

        $this->actingAs($supervisor)->post(route('ga-certification.decide', $application), ['decision' => 'approve']);

        $this->actingAs($this->cgs())->get(route('ga-certification.queue'))
            ->assertSee('System checks')
            ->assertSee('✗ Full-time research student', false)
            ->assertSee('Research · Part-time: GA and GRA are for full-time research students.');
    }

    public function test_an_eligible_request_shows_a_pass(): void
    {
        Storage::fake('local');
        Notification::fake();
        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $this->actingAs($student)->post(route('ga-certification.store'), $this->payload())->assertSessionHasNoErrors();

        $this->actingAs($supervisor)->get(route('ga-certification.queue'))->assertSee('✓ Full-time research student', false);
    }

    public function test_the_form_carries_the_note_hidden_until_needed_and_shows_it_on_a_draft_that_needs_it(): void
    {
        Storage::fake('local');
        $student = $this->student(attributes: ['supervisor_id' => $this->supervisor()->id]);

        $this->actingAs($student)->get(route('ga-certification.create'))
            ->assertSee('GA and GRA appointments are for full-time research students.')
            ->assertSee('data-eligibility-note', false)
            ->assertSee('hidden>', false);

        $this->actingAs($student)->post(route('ga-certification.draft.save'), ['study_level' => 'coursework']);
        $page = $this->actingAs($student)->get(route('ga-certification.draft.edit', Application::sole()))->getContent();

        // Rendered visible straight away for a coursework draft.
        $this->assertMatchesRegularExpression('/data-eligibility-note\s+data-eligible="[^"]*"\s*>/', $page);
    }
}
