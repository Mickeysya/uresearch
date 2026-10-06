<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Notifications\CandidacyReminder;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Exact calendar-month reminder dates (Carbon subMonthsNoOverflow, clamped
 * to the last day of a short month), not a fixed 90/60/30-day count -- see
 * StudyCandidacy::reminderDates(). The worked example: a 31 Dec due date
 * gives 30 Sep / 31 Oct / 30 Nov, because September and November clamp
 * from 31 but October doesn't need to.
 */
class CandidacyReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function student(): User
    {
        return User::create([
            'name' => 'Student', 'email' => 'student@test.my',
            'password' => 'password', 'role' => Role::STUDENT,
        ]);
    }

    public function test_reminder_dates_clamp_short_months_per_the_worked_example(): void
    {
        $candidacy = StudyCandidacy::create([
            'student_id' => $this->student()->id,
            'programme_start_date' => now()->subYears(2),
            'candidacy_expiry_date' => '2026-12-31',
            'status' => StudyCandidacy::STATUS_ACTIVE,
        ]);

        $dates = $candidacy->reminderDates();

        $this->assertSame('2026-09-30', $dates[1]->format('Y-m-d'));
        $this->assertSame('2026-10-31', $dates[2]->format('Y-m-d'));
        $this->assertSame('2026-11-30', $dates[3]->format('Y-m-d'));
    }

    public function test_the_daily_command_sends_reminder_one_on_its_exact_date(): void
    {
        Notification::fake();

        $candidacy = StudyCandidacy::create([
            'student_id' => $this->student()->id,
            'programme_start_date' => now()->subYears(2),
            'candidacy_expiry_date' => '2026-12-31',
            'status' => StudyCandidacy::STATUS_ACTIVE,
        ]);

        Carbon::setTestNow('2026-09-30');
        $this->artisan('candidacy:remind')->assertSuccessful();
        Carbon::setTestNow();

        Notification::assertSentTo($candidacy->student, CandidacyReminder::class);
        $this->assertSame([1], $candidacy->reminders()->pluck('reminder_number')->all());
    }

    public function test_a_missed_run_still_catches_up_instead_of_skipping_the_reminder(): void
    {
        Notification::fake();

        $candidacy = StudyCandidacy::create([
            'student_id' => $this->student()->id,
            'programme_start_date' => now()->subYears(2),
            'candidacy_expiry_date' => '2026-12-31',
            'status' => StudyCandidacy::STATUS_ACTIVE,
        ]);

        // The job "misses" 30 Sep entirely and next runs on 5 Oct -- today
        // is past reminder 1's date (30 Sep) and not yet reminder 2's
        // (31 Oct), so it should still send reminder 1 rather than nothing.
        Carbon::setTestNow('2026-10-05');
        $this->artisan('candidacy:remind')->assertSuccessful();
        Carbon::setTestNow();

        $this->assertSame([1], $candidacy->reminders()->pluck('reminder_number')->all());
    }

    public function test_reminders_stop_once_an_appeal_is_open(): void
    {
        Notification::fake();

        $candidacy = StudyCandidacy::create([
            'student_id' => $this->student()->id,
            'programme_start_date' => now()->subYears(2),
            'candidacy_expiry_date' => '2026-12-31',
            'status' => StudyCandidacy::STATUS_ACTIVE,
        ]);

        // Simplest way to simulate an open appeal without building a full
        // application chain: an appeal detail whose application is pending.
        $supervisor = User::create([
            'name' => 'Supervisor', 'email' => 'sup@test.my',
            'password' => 'password', 'role' => Role::SUPERVISOR,
        ]);
        $application = \App\Modules\Core\Models\Application::create([
            'student_id' => $candidacy->student_id,
            'module_type' => 'candidacy_appeal',
            'status' => \App\Modules\Core\Models\Application::STATUS_PENDING,
            'current_stage' => 'supervisor',
        ]);
        \App\Modules\Chloe\Models\CandidacyAppealDetail::create([
            'application_id' => $application->id,
            'study_candidacy_id' => $candidacy->id,
            'supervisor_id' => $supervisor->id,
            'reason' => '',
            'requested_extension_months' => 3,
        ]);

        Carbon::setTestNow('2026-09-30');
        $this->artisan('candidacy:remind')->assertSuccessful();
        Carbon::setTestNow();

        Notification::assertNothingSent();
    }
}
