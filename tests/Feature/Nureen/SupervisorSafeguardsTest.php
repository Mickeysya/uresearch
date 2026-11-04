<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Notifications\ApplicationAwaitingYou;
use App\Modules\Nureen\Notifications\CertificationRevoked;
use App\Modules\Nureen\Support\ProcessingTimeAnalytics;
use App\Modules\Nureen\Support\StuckSupervisorStages;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nureen\Concerns\FilesAttendanceAppeals;
use Tests\Feature\Nureen\Concerns\FilesGaExtensions;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * After Lee Xin Yi's GA Extension "went missing" (3 Nov 2026): it hadn't --
 * her supervisor had changed -- but nobody was told it was waiting, and
 * nothing would have caught a supervisor who genuinely could not act.
 *
 *   a. submitting is blocked when there is no supervisor who can act
 *   b. the details page names who it is waiting for
 *   c. CGS sees requests nobody can act on, and can reassign them
 *   d. demo seeders only use documented supervisors
 *   + whoever acts first is emailed on submission
 *   + a revoked certification emails the student and the supervisor
 *
 * Monday 2 November 2026 throughout.
 */
class SupervisorSafeguardsTest extends TestCase
{
    use FilesAttendanceAppeals;
    use FilesGaExtensions;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00'));
    }

    /** The generated form's fields and the required documents -- Concerns\FilesGaExtensions. */
    protected function gaExtensionPayload(): array
    {
        return $this->gaExtensionFields([
            'current_end_date' => '2026-12-01',
            'requested_new_end_date' => '2027-06-01',
            'reason_for_extension' => 'Fieldwork slipped two semesters and the analysis needs the assistantship to continue.',
        ]) + $this->gaExtensionDocuments();
    }

    protected function certificationPayload(): array
    {
        return [
            'appointment_type' => 'GA',
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-30',
            'course_code' => 'CCB2012',
            'course_name' => 'Process Control',
            'course_owner_name' => 'Dr. Lim',
            'semester' => 'Jan 2026',
            // CGS's online form (CertificationController::onlineFormRules()).
            'study_level' => 'research',
            'level_of_study' => 'phd',
            'mode_of_study' => 'full_time',
            'identity_number' => '900101-08-1234',
            'personal_email' => 'student.personal@gmail.test',
            'declaration' => '1',
            'supporting_documents' => [UploadedFile::fake()->create('evidence.pdf', 80, 'application/pdf')],
        ];
    }

    protected function supervised(string $supervisorEmail = 'sup@test.my'): User
    {
        return $this->student(attributes: ['supervisor_id' => $this->supervisor($supervisorEmail)->id]);
    }

    /* -----------------------------------------------------------------
     | a. No supervisor who can act: blocked, with a clear message
     |------------------------------------------------------------------*/

    public function test_submission_is_blocked_without_a_supervisor_or_when_the_supervisor_cannot_act(): void
    {
        $none = $this->student('22001001', 'none@test.my');
        $wrongRole = $this->student('22001002', 'wrong@test.my', ['supervisor_id' => $this->chair()->id]);

        foreach ([[$none, 'no supervisor is assigned'], [$wrongRole, 'does not have the Supervisor role']] as [$student, $message]) {
            $this->actingAs($student)->get(route('ga-extension.create'))->assertOk()->assertSee($message);
            $this->actingAs($student)->get(route('ga-certification.create'))->assertOk()->assertSee($message);

            $this->actingAs($student)->post(route('ga-extension.store'), $this->gaExtensionPayload())
                ->assertRedirect()->assertSessionHas('error', fn ($m) => str_contains($m, $message));
            $this->actingAs($student)->post(route('ga-certification.store'), $this->certificationPayload())
                ->assertRedirect()->assertSessionHas('error', fn ($m) => str_contains($m, $message));
        }

        $this->assertSame(0, Application::count());
        Notification::assertNothingSent();
    }

    /* -----------------------------------------------------------------
     | Whoever acts first is emailed on submission
     |------------------------------------------------------------------*/

    public function test_the_named_supervisor_is_emailed_on_a_ga_extension_submission_with_the_charter_due_date(): void
    {
        $student = $this->supervised();
        $supervisor = User::find($student->supervisor_id);

        $this->actingAs($student)->post(route('ga-extension.store'), $this->gaExtensionPayload())->assertSessionHasNoErrors();
        $application = Application::sole();

        Notification::assertSentTo($supervisor, ApplicationAwaitingYou::class, function ($n) use ($supervisor, $application) {
            $mail = $n->toMail($supervisor);
            $text = implode(' ', array_merge($mail->introLines, [$mail->subject]));

            // Monday 2 Nov + 3 working days: Thursday 5 Nov.
            return str_contains($mail->subject, $application->reference().' is waiting for your endorsement')
                && str_contains($text, 'please decide by Thursday, 5 November 2026 (3 working days)')
                && $mail->actionUrl === route('ga-extension.queue');
        });
        Notification::assertSentTimes(ApplicationAwaitingYou::class, 1);
    }

    public function test_certification_and_supervision_submissions_email_the_supervisor_who_acts_first(): void
    {
        $student = $this->supervised();
        $own = User::find($student->supervisor_id);

        $this->actingAs($student)->post(route('ga-certification.store'), $this->certificationPayload())->assertSessionHasNoErrors();
        Notification::assertSentTo($own, ApplicationAwaitingYou::class, fn ($n) => $n->toMail($own)->actionUrl === route('ga-certification.queue'));

        // Supervision: the supervisor the student REQUESTS, not their current one.
        $requested = $this->supervisor('requested@test.my');
        $this->actingAs($student)->post(route('supervision.store'), [
            'requested_supervisor_id' => $requested->id,
            'appointment_role' => SupervisionDetail::ROLE_CO,
            'appointment_type' => 'new',
            'justification' => 'We share a research interest in reservoir simulation and modelling.',
            'supporting_documents' => [UploadedFile::fake()->create('proposal.pdf', 120, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        Notification::assertSentTo($requested, ApplicationAwaitingYou::class, fn ($n) => $n->toMail($requested)->actionUrl === route('supervision.queue'));
        Notification::assertSentToTimes($own, ApplicationAwaitingYou::class, 1);
    }

    public function test_an_attendance_appeal_emails_every_non_executive_cgs_account(): void
    {
        $cgsA = $this->cgs();
        $cgsB = $this->user(Role::NON_EXEC_CGS, ['email' => 'cgs2@test.my']);
        $student = $this->student();
        $record = AttendanceRecord::recordPeriod($student->id, '2026-10-30', 6, 10);

        $this->actingAs($student)->post(route('attendance-appeal.store'), $this->appealDocuments() + [
            'attendance_record_id' => $record->id,
            'sessions_requested' => 2,
            'reason' => 'I was on approved medical leave for the sessions marked absent that week.',
        ])->assertSessionHasNoErrors();

        Notification::assertSentTo([$cgsA, $cgsB], ApplicationAwaitingYou::class,
            fn ($n, $channels, $to) => $n->toMail($to)->actionUrl === route('attendance-appeal.queue'));
    }

    /* -----------------------------------------------------------------
     | b. The details page names who it is waiting for
     |------------------------------------------------------------------*/

    public function test_the_details_page_names_the_supervisor_it_is_waiting_for(): void
    {
        $student = $this->student(attributes: ['supervisor_id' => User::create(['name' => 'Dr. Aisyah Rahman', 'email' => 'aisyah@test.my', 'password' => 'password', 'role' => Role::SUPERVISOR])->id]);

        $this->actingAs($student)->post(route('ga-extension.store'), $this->gaExtensionPayload());

        $this->actingAs($student)->get(route('applications.show', Application::sole()))
            ->assertOk()
            ->assertSee('Waiting for <b>Dr. Aisyah Rahman, your supervisor</b>.', false);
    }

    /* -----------------------------------------------------------------
     | c. CGS: requests nobody can act on, and reassignment
     |------------------------------------------------------------------*/

    protected function stuckGaExtension(User $student, ?int $supervisorId): Application
    {
        $application = Application::create([
            'student_id' => $student->id, 'submitted_by_id' => $student->id, 'module_type' => 'ga_extension',
            'status' => Application::STATUS_PENDING, 'current_stage' => 'supervisor', 'submitted_at' => '2026-10-20 09:00',
        ]);
        GaExtensionDetail::create([
            'application_id' => $application->id, 'supervisor_id' => $supervisorId,
            'current_end_date' => '2026-12-01', 'requested_new_end_date' => '2027-06-01',
            'reason_for_extension' => 'Test fixture.', 'final_approver_snapshot' => 'cgs',
        ]);

        return $application;
    }

    public function test_cgs_sees_requests_no_account_can_act_on(): void
    {
        $student = $this->student();
        $demoted = $this->supervisor('demoted@test.my');
        $demoted->update(['role' => Role::CHAIR, 'name' => 'Dr. Demoted']);
        $healthy = $this->supervisor('fine@test.my');

        $stuck = $this->stuckGaExtension($student, $demoted->id);
        $orphan = $this->stuckGaExtension($student, null);
        $fine = $this->stuckGaExtension($student, $healthy->id);

        // A Supervision request at the Chair stage in a department with no Chair.
        $ee = $this->student('22001015', 'ee@test.my', ['department' => 'Electrical & Electronics Engineering']);
        $noChair = Application::create(['student_id' => $ee->id, 'submitted_by_id' => $ee->id, 'module_type' => 'supervision', 'status' => Application::STATUS_PENDING, 'current_stage' => 'chair', 'submitted_at' => now()]);
        SupervisionDetail::create(['application_id' => $noChair->id, 'requested_supervisor_id' => $healthy->id, 'justification' => 'x', 'appointment_role' => SupervisionDetail::ROLE_CO, 'appointment_type' => 'new', 'cgs_stage_snapshot' => 'endorse']);

        $this->actingAs($this->cgs())->get(route('nureen.stuck-reviews'))->assertOk()
            ->assertSee($stuck->reference())
            ->assertSee('Dr. Demoted (demoted@test.my) no longer has the Supervisor role.')
            ->assertSee($orphan->reference())
            ->assertSee('No supervisor is named on this request.')
            ->assertDontSee($fine->reference())
            ->assertSee($noChair->reference())
            ->assertSee('There is no Chair account for Electrical &amp; Electronics Engineering', false);

        // CGS only.
        $this->actingAs($student)->get(route('nureen.stuck-reviews'))->assertForbidden();
        $this->actingAs($healthy)->get(route('nureen.stuck-reviews'))->assertForbidden();
    }

    public function test_reassigning_records_the_reason_moves_the_queue_and_emails_the_new_supervisor(): void
    {
        $student = $this->student();
        $gone = $this->supervisor('gone@test.my');
        $gone->update(['role' => Role::ADMIN]);
        $application = $this->stuckGaExtension($student, $gone->id);
        $new = $this->supervisor('new@test.my');
        $cgs = $this->cgs();

        // Not a supervisor: refused.
        $this->actingAs($cgs)->post(route('nureen.stuck-reviews.reassign', $application), ['supervisor_id' => $this->chair()->id, 'reason' => 'The supervisor has left UTP.'])
            ->assertSessionHasErrors('supervisor_id');

        $this->actingAs($cgs)->post(route('nureen.stuck-reviews.reassign', $application), ['supervisor_id' => $new->id, 'reason' => 'The supervisor has left UTP.'])
            ->assertRedirect(route('nureen.stuck-reviews'));

        $application->refresh();
        $this->assertSame([Application::STATUS_PENDING, 'supervisor'], [$application->status, $application->current_stage]);
        $this->assertSame($new->id, GaExtensionDetail::where('application_id', $application->id)->value('supervisor_id'));

        $row = ApprovalHistory::where('application_id', $application->id)->sole();
        $this->assertSame([StuckSupervisorStages::REASSIGNED, 'supervisor', $cgs->id], [$row->decision, $row->stage_key, $row->approver_id]);
        $this->assertStringContainsString('The supervisor has left UTP.', $row->remarks);

        Notification::assertSentTo($new, ApplicationAwaitingYou::class, fn ($n) => str_contains(implode(' ', $n->toMail($new)->introLines), 'Reason given: The supervisor has left UTP.'));

        // The new supervisor's queue has it; the list no longer does.
        $this->actingAs($new)->get(route('ga-extension.queue'))->assertSee('#'.$application->id);
        $this->actingAs($cgs)->get(route('nureen.stuck-reviews'))->assertDontSee($application->reference());

        // The student sees why, and it is not counted as a decision.
        $this->actingAs($student)->get(route('applications.show', $application))->assertSee('The supervisor has left UTP.');
        $this->assertTrue(app(ProcessingTimeAnalytics::class)->complianceByStageAndModule()->where('module', 'ga_extension')->isEmpty());
    }

    public function test_a_supervision_request_is_listed_but_never_reassigned(): void
    {
        $student = $this->student();
        $gone = $this->supervisor('gone@test.my');
        $gone->update(['role' => Role::ADMIN]);
        $application = Application::create(['student_id' => $student->id, 'submitted_by_id' => $student->id, 'module_type' => 'supervision', 'status' => Application::STATUS_PENDING, 'current_stage' => 'supervisor', 'submitted_at' => now()]);
        SupervisionDetail::create(['application_id' => $application->id, 'requested_supervisor_id' => $gone->id, 'justification' => 'x', 'appointment_role' => SupervisionDetail::ROLE_CO, 'appointment_type' => 'new', 'cgs_stage_snapshot' => 'endorse']);

        $this->actingAs($this->cgs())->get(route('nureen.stuck-reviews'))
            ->assertSee($application->reference())
            ->assertSee("A Supervision request can't be reassigned", false);

        $this->actingAs(User::where('role', Role::NON_EXEC_CGS)->first())
            ->post(route('nureen.stuck-reviews.reassign', $application), ['supervisor_id' => $this->supervisor('x@test.my')->id, 'reason' => 'Trying anyway, should fail.'])
            ->assertNotFound();
    }

    /* -----------------------------------------------------------------
     | Revoked certification: student AND supervisor are told
     |------------------------------------------------------------------*/

    public function test_revoking_a_certification_emails_the_student_and_their_supervisor(): void
    {
        $student = $this->supervised();
        $supervisor = User::find($student->supervisor_id);
        $application = Application::create(['student_id' => $student->id, 'submitted_by_id' => $student->id, 'module_type' => 'ga_certification', 'status' => Application::STATUS_APPROVED, 'current_stage' => 'senior_director', 'submitted_at' => now()]);
        GaCertificationDetail::create([
            'application_id' => $application->id, 'supervisor_id' => $supervisor->id, 'appointment_type' => 'GA',
            'period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'purpose' => 'x',
            'course_code' => 'C1', 'course_name' => 'Course', 'course_owner_name' => 'Dr. X', 'semester' => 'Jan 2026',
            'reference_number' => 'CGS/GACERT/2026/0099',
        ]);
        $this->actingAs($this->cgs())
            ->post(route('ga-certification.revoke', $application), ['revoked_reason' => 'Issued against the wrong appointment period.'])
            ->assertRedirect();

        foreach ([$student, $supervisor] as $person) {
            Notification::assertSentTo($person, CertificationRevoked::class,
                fn ($n) => str_contains(implode(' ', $n->toMail($person)->introLines), 'Reason given: Issued against the wrong appointment period.'));
        }
    }

    public function test_the_revoke_forms_say_who_sees_the_reason(): void
    {
        foreach (['ga_certification/_certificate_status', 'supervision/_letter_status'] as $view) {
            $this->assertStringContainsString(
                'This reason is shown to the student and their supervisor. It is not shown on the public verification page.',
                file_get_contents(app_path("Modules/Nureen/Resources/views/{$view}.blade.php")),
            );
        }
    }

    /* -----------------------------------------------------------------
     | d. Demo seeders only use documented supervisors
     |------------------------------------------------------------------*/

    public function test_every_student_my_demo_seeders_create_has_a_documented_supervisor(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $before = User::where('role', Role::STUDENT)->pluck('id');

        $this->seed(\App\Modules\Nureen\Database\Seeders\NureenRealignedWorkflowDemoSeeder::class);
        $this->seed(\App\Modules\Nureen\Database\Seeders\NureenClientCharterDemoSeeder::class);

        $documented = file_get_contents(base_path('docs/test-accounts.md'));
        $created = User::where('role', Role::STUDENT)->whereNotIn('id', $before)->with('supervisor')->get();

        $this->assertNotEmpty($created);
        foreach ($created as $student) {
            $this->assertNotNull($student->supervisor, "{$student->email} has no supervisor");
            $this->assertSame(Role::SUPERVISOR, $student->supervisor->role);
            $this->assertStringContainsString('`'.$student->supervisor->email.'`', $documented, "{$student->email}'s supervisor is not in docs/test-accounts.md");
        }
    }
}
