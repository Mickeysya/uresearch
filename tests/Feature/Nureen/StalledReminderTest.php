<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\ApplicationReminder;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Notifications\ApplicationStalled;
use App\Modules\Nureen\Notifications\SupervisionEscalatedToCgs;
use App\Modules\Nureen\Notifications\SupervisionRequestStalled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesUsers;
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
    use MakesUsers;
    use RefreshDatabase;

    protected function backdate(Application $application, int $days): void
    {
        DB::table('applications')->where('id', $application->id)->update([
            'updated_at' => now()->subDays($days),
        ]);
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
            'current_end_date' => now()->subMonth(),
            'requested_new_end_date' => now()->addMonths(5),
            'reason_for_extension' => 'Test fixture.',
        ]);
        $this->backdate($application, 4);

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
            'current_end_date' => now()->subMonth(),
            'requested_new_end_date' => now()->addMonths(5),
            'reason_for_extension' => 'Test fixture.',
        ]);
        $this->backdate($application, 4);

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
            'current_end_date' => now()->subMonth(),
            'requested_new_end_date' => now()->addMonths(5),
            'reason_for_extension' => 'Test fixture.',
        ]);
        $this->backdate($application, 1);

        // Configured default is 3 days; 1 day old should not qualify.
        $this->artisan('ga-extension:remind-stalled')->assertSuccessful();
        Notification::assertNothingSent();

        // An explicit override of 1 day should.
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
        $this->backdate($application, 4);

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
        ]);
        $this->backdate($application, 9);

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

        // The supervisor decides; the request moves to Supervision's
        // second stage ('cgs_review' -- not GA Extension's 'cgs_verify').
        $application->update(['current_stage' => 'cgs_review']);
        $this->backdate($application, 4);
        SupervisionDetail::where('application_id', $application->id)->update(['reminded_at' => now()->subDays(4)]);

        $this->artisan('supervision:remind-stalled');

        $detail->refresh();
        // A fresh count at the new stage, not 2 carried over from the old one.
        $this->assertSame(1, $detail->reminder_count);
        $this->assertSame('cgs_review', $detail->reminded_stage_key);
    }
}
