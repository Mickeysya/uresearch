<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaEligibility;
use App\Modules\Hani\Notifications\VivaScheduled;
use App\Modules\Hani\Support\VivaInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Viva step 3: the AE sets the date only once the nomination is approved and
 * the softbound is verified, and the calendar goes to everyone on the panel.
 */
class VivaScheduleTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected User $student;

    protected User $supervisor;

    protected User $ae;

    protected Application $notice;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();

        $this->supervisor = $this->supervisor();
        $this->student = $this->student(attributes: [
            'programme' => 'MSc Full-Time', 'department' => 'Computing', 'supervisor_id' => $this->supervisor->id,
        ]);
        $this->ae = $this->academicExec();
        $this->ae->update(['department' => 'Computing']);

        VivaEligibility::create(['student_id' => $this->student->id] + array_fill_keys([
            'research_methodology_passed', 'publication_accepted', 'registered_current_semester',
            'no_outstanding_fees', 'within_candidacy_period',
        ], true));

        $this->actingAs($this->student)->post(route('viva.notice.store'), [
            'thesis_title' => 'Constraint-based examiner matching',
            'expected_softbound_date' => now()->addMonths(4)->toDateString(),
            'publication_proof' => UploadedFile::fake()->create('accepted.pdf', 100, 'application/pdf'),
        ]);
        $this->notice = Application::where('module_type', 'viva_voce')->sole();
        $this->actingAs($this->ae)->post(route('viva.decide', $this->notice), ['decision' => 'approve']);

        $this->actingAs($this->student)->post(route('viva.softbound.store'), [
            'field_of_study' => 'Data analytics', 'contact_number' => '0133372001',
            'personal_email' => 'ahmad@gmail.com', 'session' => 'May', 'year' => now()->year,
            'thesis_title' => 'Constraint-based examiner matching',
            'similarity_percent' => 12, 'word_count' => 40000,
            'co_supervisor_email' => 'co.sv@utp.edu.my', 'declaration' => 1,
            'format_confirmation' => UploadedFile::fake()->create('format.pdf', 100, 'application/pdf'),
            'turnitin_report' => UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'),
            'thesis' => UploadedFile::fake()->create('thesis.pdf', 1000, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $softbound = Application::where('module_type', 'viva_softbound')->sole();
        $this->actingAs($this->ae)->post(route('viva.softbound.decide', $softbound), ['decision' => 'approve']);
    }

    /** A finished nomination chain, as ExaminerNominationTest leaves one. */
    protected function approveNomination(): void
    {
        $nomination = Application::create([
            'student_id' => $this->student->id,
            'submitted_by_id' => $this->supervisor->id,
            'module_type' => 'examiner_nomination',
            'status' => Application::STATUS_APPROVED,
            'current_stage' => 'cgs_release',
            'submitted_at' => now(),
        ]);

        ExaminerNomination::create([
            'application_id' => $nomination->id,
            'internal_main_id' => Examiner::create([
                'name' => 'Dr. Internal', 'email' => 'internal@test.my', 'department' => 'Computing',
                'faculty' => 'FSMC', 'type' => Examiner::TYPE_INTERNAL, 'is_active' => true,
            ])->id,
            'external_main_id' => Examiner::create([
                'name' => 'Prof. External', 'email' => 'external@test.my', 'department' => 'External',
                'type' => Examiner::TYPE_EXTERNAL, 'institution' => 'Universiti Malaya', 'is_active' => true,
            ])->id,
            'thesis_title' => 'Constraint-based examiner matching',
        ]);
    }

    protected function schedule(array $overrides = [])
    {
        return $this->actingAs($this->ae)->post(route('viva.schedule.update', $this->notice), $overrides + [
            'viva_date' => now()->addMonth()->toDateString(),
            'viva_time' => '10:00',
            'venue' => 'Chancellor Hall, Block 2',
            'chair_name' => 'Prof. Chair',
            'chair_email' => 'chair@test.my',
        ]);
    }

    public function test_no_date_until_the_nomination_is_approved(): void
    {
        $this->actingAs($this->ae)->get(route('viva.schedule.index'))
            ->assertOk()
            ->assertSee('Examiner nomination not started');

        $this->actingAs($this->ae)->get(route('viva.schedule.edit', $this->notice))
            ->assertRedirect(route('viva.schedule.index'));

        $this->schedule()->assertSessionHas('error');
        $this->assertNull(VivaDetail::sole()->viva_at);
        Notification::assertNotSentTo($this->student, VivaScheduled::class);
    }

    public function test_scheduling_invites_the_whole_panel_and_rescheduling_bumps_the_sequence(): void
    {
        $this->approveNomination();

        $this->actingAs($this->ae)->get(route('viva.schedule.edit', $this->notice))
            ->assertOk()
            ->assertSee('Dr. Internal')
            ->assertSee('Prof. External')
            // Carried over from the student's Form #019.
            ->assertSee('co.sv@utp.edu.my');

        $this->schedule()->assertSessionHasNoErrors()->assertSessionHas('status');

        $detail = VivaDetail::sole();
        $this->assertSame('10:00', $detail->viva_at->format('H:i'));
        $this->assertSame(0, $detail->schedule_sequence);

        Notification::assertSentTo([$this->student, $this->supervisor], VivaScheduled::class);
        foreach (['internal@test.my', 'external@test.my', 'chair@test.my'] as $email) {
            Notification::assertSentOnDemand(VivaScheduled::class,
                fn ($n, $channels, AnonymousNotifiable $to) => array_key_exists($email, (array) $to->routes['mail']));
        }

        // 10:00 in Kuala Lumpur is 02:00 UTC.
        $ics = VivaInvite::ics($this->notice, $detail);
        $this->assertStringContainsString('DTSTART:'.$detail->viva_at->format('Ymd').'T020000Z', $ics);
        $this->assertStringContainsString('LOCATION:Chancellor Hall\, Block 2', $ics);

        $this->schedule(['viva_time' => '14:30'])->assertSessionHasNoErrors();
        $this->assertSame(1, $detail->fresh()->schedule_sequence);

        // The student, the supervisor and the calendar all show it.
        $this->actingAs($this->student)->get(route('viva.eligibility'))->assertSee('2:30 pm');
        $this->actingAs($this->supervisor)->get(route('viva.supervisees'))->assertOk()->assertSee('2:30 pm');

        $events = app(ModuleRegistry::class)->get('viva_voce')
            ->calendarEvents($this->student, now()->startOfMonth(), now()->addMonths(2));
        $this->assertCount(1, $events);
    }

    public function test_another_departments_ae_cannot_schedule(): void
    {
        $this->approveNomination();
        $other = $this->user('academic_exec', ['email' => 'ae2@test.my', 'department' => 'Chemical Engineering']);

        $this->actingAs($other)->get(route('viva.schedule.edit', $this->notice))->assertForbidden();
        $this->actingAs($other)->get(route('viva.schedule.index'))->assertOk()->assertDontSee('Ahmad Danial');
    }

    public function test_a_co_supervisor_needs_both_name_and_email(): void
    {
        $this->approveNomination();

        $this->schedule(['co_supervisor_name' => 'Dr. Co'])->assertSessionHasErrors('co_supervisor_email');
        $this->schedule(['viva_date' => now()->subDay()->toDateString()])->assertSessionHasErrors('viva_date');
    }
}
