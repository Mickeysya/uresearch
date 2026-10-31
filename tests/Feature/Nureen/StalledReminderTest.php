<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\ApplicationReminder;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Notifications\ApplicationStalled;
use App\Modules\Nureen\Notifications\SupervisionEscalatedToCgs;
use App\Modules\Nureen\Notifications\SupervisionRequestStalled;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesUsers;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\TestCase;

/**
 * "Stop applications from getting stuck without anyone noticing" — the
 * supervisor's feedback, across all three of Nureen's approval-chain
 * modules.
 *
 * GA Extension and Certification go through the generic
 * Support\StalledApplicationReminder (every stage's role holder genuinely
 * is who should be nagged); Supervision keeps its own command, since its
 * 'supervisor' stage's real recipient is one named person, and gets the
 * extra escalation-to-CGS behaviour the other two do not need.
 */
class StalledReminderTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    /**
     * Pinned to a Friday, so "submitted N days ago" means a known number of
     * working days: reminders now fire on each stage's Client Charter,
     * counted in working days from when the application reached the stage
     * (its newest approval_history row, or submitted_at when there is none
     * -- the case for every fixture here). See Support\ClientCharter.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-06 10:00'));
    }

    /* -----------------------------------------------------------------
     | GA Extension
     |------------------------------------------------------------------*/

    public function test_ga_extension_reminds_the_current_stages_role_holder(): void
    {
        Notification::fake();

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_extension',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'supervisor',
            'submitted_at' => now()->subDays(5),
        ]);
        GaExtensionDetail::create([
            'application_id' => $application->id,
            'supervisor_id' => $supervisor->id,
            'current_end_date' => now()->subMonth(),
            'requested_new_end_date' => now()->addMonths(5),
            'reason_for_extension' => 'Test fixture.',
        ]);

        $this->artisan('ga-extension:remind-stalled')->assertSuccessful();

        Notification::assertSentTo($supervisor, ApplicationStalled::class);
        $this->assertSame(1, ApplicationReminder::where('application_id', $application->id)->value('reminder_count'));
    }

    public function test_ga_extension_does_not_remind_twice_inside_the_same_window(): void
    {
        Notification::fake();

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_extension',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'supervisor',
            'submitted_at' => now()->subDays(5),
        ]);
        GaExtensionDetail::create([
            'application_id' => $application->id,
            'supervisor_id' => $supervisor->id,
            'current_end_date' => now()->subMonth(),
            'requested_new_end_date' => now()->addMonths(5),
            'reason_for_extension' => 'Test fixture.',
        ]);

        $this->artisan('ga-extension:remind-stalled');
        $this->artisan('ga-extension:remind-stalled');

        Notification::assertSentToTimes($supervisor, ApplicationStalled::class, 1);
    }

    public function test_ga_extension_respects_an_overridden_days_option(): void
    {
        Notification::fake();

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_extension',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'supervisor',
            'submitted_at' => now()->subDays(2),
        ]);
        GaExtensionDetail::create([
            'application_id' => $application->id,
            'supervisor_id' => $supervisor->id,
            'current_end_date' => now()->subMonth(),
            'requested_new_end_date' => now()->addMonths(5),
            'reason_for_extension' => 'Test fixture.',
        ]);

        // 2 working days in (Thu, Fri): under the supervisor stage's
        // 3-day Client Charter, so nothing yet.
        $this->artisan('ga-extension:remind-stalled')->assertSuccessful();
        Notification::assertNothingSent();

        // An explicit override of 1 working day replaces the charter.
        $this->artisan('ga-extension:remind-stalled', ['--days' => 1])->assertSuccessful();
        Notification::assertSentTo($supervisor, ApplicationStalled::class);
    }

    /* -----------------------------------------------------------------
     | Certification
     |------------------------------------------------------------------*/

    public function test_certification_reminds_cgs_staff_when_stalled_at_cgs_verify(): void
    {
        Notification::fake();

        $cgs = $this->cgs();
        $student = $this->student();

        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_certification',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'cgs_verify',
            'submitted_at' => now()->subDays(5),
        ]);
        GaCertificationDetail::create([
            'application_id' => $application->id,
            'appointment_type' => 'GA',
            'period_start' => now()->subMonths(6),
            'period_end' => now(),
            'purpose' => 'Test fixture.',
        ]);

        $this->artisan('ga-certification:remind-stalled')->assertSuccessful();

        Notification::assertSentTo($cgs, ApplicationStalled::class);
    }

    /* -----------------------------------------------------------------
     | Supervision — escalation to CGS Staff
     |------------------------------------------------------------------*/

    protected function stalledSupervisionRequest(User $supervisor, User $student): Application
    {
        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'supervision',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'supervisor',
            'submitted_at' => now()->subDays(10),
        ]);
        SupervisionDetail::create([
            'application_id' => $application->id,
            'requested_supervisor_id' => $supervisor->id,
            'justification' => 'Test fixture.',
            'appointment_role' => SupervisionDetail::ROLE_MAIN,
            // 'manual' so a later move to 'chair' then 'cgs_review' in these
            // tests reflects a real stage on this application's own chain.
            'cgs_stage_snapshot' => 'manual',
        ]);

        return $application;
    }

    public function test_a_single_reminder_does_not_escalate_to_cgs(): void
    {
        Notification::fake();

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);
        $cgs = $this->cgs();
        $this->stalledSupervisionRequest($supervisor, $student);

        $this->artisan('supervision:remind-stalled')->assertSuccessful();

        Notification::assertSentTo($supervisor, SupervisionRequestStalled::class);
        Notification::assertNotSentTo($cgs, SupervisionEscalatedToCgs::class);
    }

    public function test_escalates_to_cgs_after_the_configured_number_of_reminders(): void
    {
        Notification::fake();

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);
        $cgs = $this->cgs();
        $application = $this->stalledSupervisionRequest($supervisor, $student);

        // Reminder 1.
        $this->artisan('supervision:remind-stalled');
        Notification::assertNotSentTo($cgs, SupervisionEscalatedToCgs::class);

        // Simulate the window passing without a decision, so reminder 2 is
        // allowed through the "already reminded recently" gate.
        SupervisionDetail::where('application_id', $application->id)
            ->update(['reminded_at' => now()->subDays(4)]);

        // Reminder 2 reaches the configured escalate_after_reminders (2).
        $this->artisan('supervision:remind-stalled');

        Notification::assertSentTo($supervisor, SupervisionRequestStalled::class);
        Notification::assertSentTo($cgs, SupervisionEscalatedToCgs::class);
    }

    public function test_moving_stage_resets_the_reminder_count(): void
    {
        Notification::fake();

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);
        $application = $this->stalledSupervisionRequest($supervisor, $student);

        $this->artisan('supervision:remind-stalled');

        $detail = SupervisionDetail::where('application_id', $application->id)->first();
        $this->assertSame(1, $detail->reminder_count);
        $this->assertSame('supervisor', $detail->reminded_stage_key);

        // The supervisor decides; the request moves to this 'manual'-mode
        // application's third stage ('cgs_review' -- not GA Extension's
        // 'cgs_verify').
        $application->update(['current_stage' => 'cgs_review']);
        SupervisionDetail::where('application_id', $application->id)->update(['reminded_at' => now()->subDays(4)]);

        $this->artisan('supervision:remind-stalled');

        $detail->refresh();
        // A fresh count at the new stage, not 2 carried over from the old one.
        $this->assertSame(1, $detail->reminder_count);
        $this->assertSame('cgs_review', $detail->reminded_stage_key);
    }

    /* -----------------------------------------------------------------
     | Supervision — the Chair of Department stage
     |------------------------------------------------------------------*/

    public function test_the_chair_stage_reminds_only_the_chair_of_the_students_own_department(): void
    {
        Notification::fake();

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id, 'department' => 'Chemical Engineering']);
        $ownChair = $this->user(\App\Modules\Core\Support\Role::CHAIR, ['name' => 'Own Chair', 'email' => 'own-chair@test.my', 'department' => 'Chemical Engineering']);
        $otherChair = $this->user(\App\Modules\Core\Support\Role::CHAIR, ['name' => 'Other Chair', 'email' => 'other-chair@test.my', 'department' => 'Civil Engineering']);

        $application = $this->stalledSupervisionRequest($supervisor, $student);
        $application->update(['current_stage' => 'chair']);

        $this->artisan('supervision:remind-stalled')->assertSuccessful();

        Notification::assertSentTo($ownChair, SupervisionRequestStalled::class);
        Notification::assertNotSentTo($otherChair, SupervisionRequestStalled::class);
    }

    public function test_the_chair_stage_also_escalates_to_cgs_after_the_configured_number_of_reminders(): void
    {
        Notification::fake();

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);
        $chair = $this->chair();
        $cgs = $this->cgs();

        $application = $this->stalledSupervisionRequest($supervisor, $student);
        $application->update(['current_stage' => 'chair']);

        $this->artisan('supervision:remind-stalled');
        Notification::assertSentTo($chair, SupervisionRequestStalled::class);
        Notification::assertNotSentTo($cgs, SupervisionEscalatedToCgs::class);

        SupervisionDetail::where('application_id', $application->id)->update(['reminded_at' => now()->subDays(4)]);
        $this->artisan('supervision:remind-stalled');

        Notification::assertSentTo($cgs, SupervisionEscalatedToCgs::class);
    }

    /* -----------------------------------------------------------------
     | Client Charter timing
     |------------------------------------------------------------------*/

    public function test_a_reminder_fires_on_the_working_day_after_the_charter_due_date_and_not_before(): void
    {
        Notification::fake();

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        // Reached the supervisor stage Monday 2 Nov; 3-day charter, so due
        // Thursday 5 Nov and overdue from Friday 6 Nov.
        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_extension',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'supervisor',
            'submitted_at' => Carbon::parse('2026-11-02 09:00'),
        ]);
        GaExtensionDetail::create([
            'application_id' => $application->id,
            'supervisor_id' => $supervisor->id,
            'current_end_date' => now()->subMonth(),
            'requested_new_end_date' => now()->addMonths(5),
            'reason_for_extension' => 'Test fixture.',
            'final_approver_snapshot' => 'cgs',
        ]);

        $this->travelTo(Carbon::parse('2026-11-05 17:00'));
        $this->artisan('ga-extension:remind-stalled')->assertSuccessful();
        Notification::assertNothingSent();

        $this->travelTo(Carbon::parse('2026-11-06 08:00'));
        $this->artisan('ga-extension:remind-stalled')->assertSuccessful();
        Notification::assertSentToTimes($supervisor, ApplicationStalled::class, 1);
    }

    public function test_a_weekend_never_triggers_a_reminder_on_its_own(): void
    {
        Notification::fake();

        $cgs = $this->cgs();
        $student = $this->student();

        // Reached cgs_verify Wednesday 4 Nov; 2-day charter, due Friday 6
        // Nov. Saturday and Sunday are not working days, so the first
        // reminder is Monday 9 Nov.
        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_certification',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'cgs_verify',
            'submitted_at' => Carbon::parse('2026-11-04 09:00'),
        ]);
        GaCertificationDetail::create([
            'application_id' => $application->id,
            'appointment_type' => 'GA',
            'period_start' => now()->subMonths(6),
            'period_end' => now(),
            'purpose' => 'Test fixture.',
        ]);

        $this->travelTo(Carbon::parse('2026-11-08 08:00'));
        $this->artisan('ga-certification:remind-stalled');
        Notification::assertNothingSent();

        $this->travelTo(Carbon::parse('2026-11-09 08:00'));
        $this->artisan('ga-certification:remind-stalled');
        Notification::assertSentTo($cgs, ApplicationStalled::class);
    }

    public function test_a_stage_with_no_charter_falls_back_to_stalled_days(): void
    {
        Notification::fake();

        $supervisor = $this->supervisor();
        $cgs = $this->cgs();
        $director = $this->user(\App\Modules\Core\Support\Role::SENIOR_DIRECTOR_CGS);
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        // 'senior_director' mode: the Senior Director stage has a null
        // charter, so its reminder threshold is stalled_days instead.
        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_extension',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'senior_director',
            'submitted_at' => Carbon::parse('2026-10-26 09:00'),
        ]);
        GaExtensionDetail::create([
            'application_id' => $application->id,
            'supervisor_id' => $supervisor->id,
            'current_end_date' => now()->subMonth(),
            'requested_new_end_date' => now()->addMonths(5),
            'reason_for_extension' => 'Test fixture.',
            'final_approver_snapshot' => 'senior_director',
        ]);
        // Reached the Senior Director Monday 2 Nov: 4 working days ago.
        $moved = ApprovalHistory::create([
            'application_id' => $application->id,
            'approver_id' => $cgs->id,
            'stage_key' => 'cgs_verify',
            'stage_label' => 'CGS Staff',
            'decision' => 'reviewed',
        ]);
        DB::table('approval_history')->where('id', $moved->id)->update(['created_at' => '2026-11-02 11:00:00']);

        config(['nureen.reminders.ga_extension.stalled_days' => 5]);
        $this->artisan('ga-extension:remind-stalled');
        Notification::assertNothingSent();

        config(['nureen.reminders.ga_extension.stalled_days' => 3]);
        $this->artisan('ga-extension:remind-stalled');
        Notification::assertSentTo($director, ApplicationStalled::class);
    }
}
