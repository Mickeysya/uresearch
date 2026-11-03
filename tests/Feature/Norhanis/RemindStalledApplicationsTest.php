<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Models\StageReminder;
use App\Modules\Norhanis\Models\TravelDetail;
use App\Modules\Norhanis\Notifications\ApplicationTakingLonger;
use App\Modules\Norhanis\Notifications\StalledApplicationReminder;
use App\Modules\Norhanis\Support\StageDurations;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * norhanis:remind-stalled: the approver holding an application past its
 * stage's expected time is reminded, the student is told once, and nobody
 * is reminded twice in a row.
 *
 * Local travel throughout: every stage should take one working day. The
 * clock starts at Friday 6 Nov 2026, noon.
 */
class RemindStalledApplicationsTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected User $supervisor;

    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-06 12:00'));
        Notification::fake();

        $this->supervisor = $this->supervisor();
        $this->student = $this->student('22001001', 'aina@test.my', ['supervisor_id' => $this->supervisor->id]);
    }

    public function test_the_approver_is_reminded_and_the_student_told_once_a_stage_is_overdue(): void
    {
        // Submitted Tuesday noon: three working days against one.
        $stalled = $this->localTravel(submittedDaysAgo: 3);

        $this->artisan('norhanis:remind-stalled')
            ->expectsOutput('Reminded approvers about 1 stalled application.')
            ->assertSuccessful();

        Notification::assertSentTo($this->supervisor, StalledApplicationReminder::class);
        Notification::assertSentTo($this->student, ApplicationTakingLonger::class);
        $this->assertSame(1, StageReminder::where('application_id', $stalled->id)->where('stage_key', 'supervisor')->count());

        // Reminding changes nothing about the application itself.
        $this->assertSame(Application::STATUS_PENDING, $stalled->refresh()->status);
        $this->assertSame('supervisor', $stalled->current_stage);
    }

    public function test_an_application_still_within_its_time_is_left_alone(): void
    {
        $this->localTravel(submittedDaysAgo: 0);

        $this->artisan('norhanis:remind-stalled')
            ->expectsOutput('Reminded approvers about 0 stalled applications.');

        Notification::assertSentTimes(StalledApplicationReminder::class, 0);
        Notification::assertSentTimes(ApplicationTakingLonger::class, 0);
    }

    public function test_reminders_repeat_every_two_working_days_and_the_student_hears_only_once(): void
    {
        $this->localTravel(submittedDaysAgo: 3);
        $this->artisan('norhanis:remind-stalled');

        // The same day, and the next working day: too soon to remind again.
        $this->artisan('norhanis:remind-stalled');
        $this->travelTo(Carbon::parse('2026-11-09 12:00')); // Monday
        $this->artisan('norhanis:remind-stalled');
        Notification::assertSentToTimes($this->supervisor, StalledApplicationReminder::class, 1);

        // Two working days on, over the weekend: reminded again. The
        // student was already told for this stage, so is not told again.
        $this->travelTo(Carbon::parse('2026-11-10 12:00')); // Tuesday
        $this->artisan('norhanis:remind-stalled');
        Notification::assertSentToTimes($this->supervisor, StalledApplicationReminder::class, 2);
        Notification::assertSentToTimes($this->student, ApplicationTakingLonger::class, 1);
    }

    public function test_once_the_approver_acts_the_next_stage_starts_its_own_clock(): void
    {
        $chair = $this->chair();
        $this->student->forceFill(['department' => $chair->department])->save();

        $travel = $this->localTravel(submittedDaysAgo: 3);
        app(WorkflowEngine::class)->decide($travel, $this->supervisor, 'approve');

        $this->artisan('norhanis:remind-stalled');

        Notification::assertSentTimes(StalledApplicationReminder::class, 0);
        Notification::assertSentTimes(ApplicationTakingLonger::class, 0);
    }

    public function test_a_closed_application_is_never_reminded_about(): void
    {
        $travel = $this->localTravel(submittedDaysAgo: 3);
        app(WorkflowEngine::class)->decide($travel, $this->supervisor, 'reject');

        $this->artisan('norhanis:remind-stalled');

        Notification::assertSentTimes(StalledApplicationReminder::class, 0);
        Notification::assertSentTimes(ApplicationTakingLonger::class, 0);
    }

    public function test_it_runs_every_weekday_morning(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'norhanis:remind-stalled'));

        $this->assertNotNull($event);
        $this->assertSame('0 8 * * 1-5', $event->expression);
    }

    protected function localTravel(int $submittedDaysAgo): Application
    {
        $application = Application::create([
            'student_id' => $this->student->id,
            'module_type' => 'travel',
            'status' => Application::STATUS_DRAFT,
        ]);

        TravelDetail::create([
            'application_id' => $application->id,
            'type_of_request' => 'data_collection',
            'travel_start_date' => now()->addDays(20),
            'travel_end_date' => now()->addDays(22),
            'duration_days' => 3,
            'reason_for_travel' => 'Fieldwork',
            'destination_address' => 'Kuala Lumpur',
            'is_international' => false,
            'contact_person_email' => 'contact@test.my',
            'is_ga_gra' => false,
        ]);

        $application = app(WorkflowEngine::class)->submit($application);
        $application->forceFill(['submitted_at' => now()->subDays($submittedDaysAgo)])->save();

        // Each run of the command is a fresh process in real life; here the
        // container would otherwise keep one StageDurations across runs.
        app()->forgetInstance(StageDurations::class);

        return $application;
    }
}
