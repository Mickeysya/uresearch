<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\SlaReminderLog;
use App\Modules\Hani\Notifications\SlaReminder;
use App\Modules\Hani\Support\StageSla;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\TestCase;

/**
 * `hani:sla-reminders` (Prompt 4a): the per-stage limits in hani.sla, sent
 * once per threshold, scoped to the candidate's department, and never for a
 * stage another command already chases.
 */
class SlaRemindersTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['hani.scheduling.public_holidays' => []]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function ae(string $department): User
    {
        return User::firstOrCreate(
            ['role' => Role::ACADEMIC_EXEC, 'department' => $department],
            ['name' => 'AE '.$department, 'email' => 'ae.'.str($department)->slug().'@test.my', 'password' => 'password'],
        );
    }

    protected function seniorExec(): User
    {
        return User::firstOrCreate(
            ['email' => 'senior@test.my'],
            ['name' => 'Senior Exec', 'password' => 'password', 'role' => Role::SENIOR_EXEC_CGS],
        );
    }

    /** A notice filed at $when, waiting for the AE (notice_received). */
    protected function notice(string $when, string $department = 'Computing', string $matric = '22001001'): Application
    {
        Carbon::setTestNow($when);

        $student = User::create([
            'name' => 'Student '.$matric, 'email' => "s{$matric}@test.my", 'password' => 'password',
            'role' => Role::STUDENT, 'matric_no' => $matric, 'programme' => 'MSc Full-Time', 'department' => $department,
        ]);
        $notice = Application::create(['student_id' => $student->id, 'module_type' => 'viva_voce', 'status' => Application::STATUS_DRAFT]);

        return app(WorkflowEngine::class)->submit($notice);
    }

    protected function runAt(string $when, array $options = [])
    {
        Carbon::setTestNow($when);

        return $this->artisan('hani:sla-reminders', $options);
    }

    public function test_it_counts_working_days_and_reminds_the_departments_ae_at_the_first_threshold(): void
    {
        $ae = $this->ae('Computing');
        $other = $this->ae('Petroleum Engineering');
        $this->seniorExec();

        // Filed Monday 1 Nov 2027. Mon 8 Nov is 5 working days later, but the
        // Friday before is only 4.
        $notice = $this->notice('2027-11-01 09:00');

        $this->runAt('2027-11-05 09:00')->assertSuccessful();
        Notification::assertNothingSent();

        $this->runAt('2027-11-08 09:00')->expectsOutputToContain('Sent 1 SLA reminder(s)')->assertSuccessful();

        Notification::assertSentTo($ae, SlaReminder::class, fn ($n) => $n->threshold === 1);
        Notification::assertNotSentTo($other, SlaReminder::class);
        Notification::assertNotSentTo($this->seniorExec(), SlaReminder::class);

        $this->assertSame(5, app(StageSla::class)->waiting()->firstWhere('application.id', $notice->id)['days']);
    }

    public function test_public_holidays_do_not_count(): void
    {
        config(['hani.scheduling.public_holidays' => ['2027-11-04' => 'Fictional holiday']]);
        $this->ae('Computing');

        $this->notice('2027-11-01 09:00');

        $this->runAt('2027-11-08 09:00');
        Notification::assertNothingSent();

        $this->runAt('2027-11-09 09:00');
        Notification::assertCount(1);
    }

    public function test_each_threshold_is_sent_once_and_the_second_copies_the_senior_executive(): void
    {
        $ae = $this->ae('Computing');
        $senior = $this->seniorExec();
        $this->notice('2027-11-01 09:00');

        $this->runAt('2027-11-08 09:00');
        $this->runAt('2027-11-09 09:00')->expectsOutputToContain('1 already sent');
        Notification::assertSentToTimes($ae, SlaReminder::class, 1);

        // 10 working days: Mon 15 Nov.
        $this->runAt('2027-11-15 09:00');
        Notification::assertSentTo($ae, SlaReminder::class, fn ($n) => $n->threshold === 2);
        Notification::assertSentTo($senior, SlaReminder::class, fn ($n) => $n->threshold === 2);

        $this->runAt('2027-11-16 09:00');
        Notification::assertSentToTimes($ae, SlaReminder::class, 2);
        $this->assertSame(2, SlaReminderLog::count());
    }

    public function test_an_application_already_past_both_thresholds_gets_only_the_second(): void
    {
        $ae = $this->ae('Computing');
        $this->seniorExec();
        $this->notice('2027-11-01 09:00');

        $this->runAt('2027-11-22 09:00');

        Notification::assertSentToTimes($ae, SlaReminder::class, 1);
        Notification::assertSentTo($ae, SlaReminder::class, fn ($n) => $n->threshold === 2);
        $this->assertFalse(SlaReminderLog::where('threshold', 1)->value('sent'));
    }

    public function test_the_clock_starts_at_the_decision_that_brought_it_to_the_stage(): void
    {
        $ae = $this->ae('Computing');
        $senior = $this->seniorExec();

        Carbon::setTestNow('2027-11-01 09:00');
        $student = User::create(['name' => 'Candidate', 'email' => 'c@test.my', 'password' => 'password', 'role' => Role::STUDENT, 'department' => 'Computing']);
        $nomination = Application::create(['student_id' => $student->id, 'module_type' => 'examiner_nomination', 'status' => Application::STATUS_DRAFT]);
        app(WorkflowEngine::class)->submit($nomination);

        // The AE clears it on Wed 10 Nov; CGS compile's clock starts then.
        Carbon::setTestNow('2027-11-10 09:00');
        app(WorkflowEngine::class)->decide($nomination, $ae, 'approve');

        $this->runAt('2027-11-16 09:00');
        Notification::assertNotSentTo($senior, SlaReminder::class);

        $this->runAt('2027-11-17 09:00');
        Notification::assertSentTo($senior, SlaReminder::class, fn ($n) => $n->threshold === 1);
    }

    public function test_a_list_returned_to_a_stage_starts_a_fresh_clock(): void
    {
        $ae = $this->ae('Computing');
        $senior = $this->seniorExec();
        $director = User::create(['name' => 'Director', 'email' => 'director@test.my', 'password' => 'password', 'role' => Role::SENIOR_DIRECTOR_CGS]);
        $engine = app(WorkflowEngine::class);

        Carbon::setTestNow('2027-11-01 09:00');
        $student = User::create(['name' => 'Candidate', 'email' => 'c@test.my', 'password' => 'password', 'role' => Role::STUDENT, 'department' => 'Computing']);
        $nomination = $engine->submit(Application::create(['student_id' => $student->id, 'module_type' => 'examiner_nomination', 'status' => Application::STATUS_DRAFT]));

        $this->runAt('2027-11-08 09:00');
        Notification::assertSentToTimes($ae, SlaReminder::class, 1);

        Carbon::setTestNow('2027-11-08 10:00');
        $engine->decide($nomination, $ae, 'approve');
        $engine->decide($nomination, $senior, 'approve');
        $engine->returnTo($nomination->refresh(), $director, 'academic_exec', 'Backup examiner is not from the department.');

        // Back at academic_exec from 8 Nov: 5 working days is 15 Nov.
        $this->runAt('2027-11-12 09:00');
        Notification::assertSentToTimes($ae, SlaReminder::class, 1);

        $this->runAt('2027-11-15 09:00');
        Notification::assertSentToTimes($ae, SlaReminder::class, 2);
    }

    public function test_stages_another_command_chases_are_skipped(): void
    {
        $sla = app(StageSla::class);

        foreach (array_keys(config('hani.sla.skip')) as $key) {
            [$module, $stage] = explode('.', $key);
            $this->assertNull($sla->rule($module, $stage), "{$key} is chased elsewhere and must not have an SLA reminder.");
        }

        $this->assertArrayHasKey('re_viva.under_panel_review', config('hani.sla.skip'));

        // Even if someone adds a limit for it, the skip wins.
        config(['hani.sla.stages.re_viva.under_panel_review' => ['days' => [1, 2], 'working_days' => false]]);
        $this->assertNull($sla->rule('re_viva', 'under_panel_review'));
    }

    public function test_dry_run_lists_without_sending_or_logging(): void
    {
        $this->ae('Computing');
        $this->notice('2027-11-01 09:00');

        $this->runAt('2027-11-08 09:00', ['--dry-run' => true])
            ->expectsOutputToContain('would send sla_1 for Notice of Thesis Submission')
            ->expectsOutputToContain('Dry run: 1 SLA reminder(s) would be sent.')
            ->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame(0, SlaReminderLog::count());
    }

    public function test_finished_applications_are_never_reminded(): void
    {
        $ae = $this->ae('Computing');
        $notice = $this->notice('2027-11-01 09:00');
        app(WorkflowEngine::class)->decide($notice, $ae, 'approve');

        $this->runAt('2027-12-01 09:00');

        Notification::assertNotSentTo($ae, SlaReminder::class);
        $this->assertSame(0, SlaReminderLog::count());
    }

    public function test_it_is_scheduled_daily_from_the_module(): void
    {
        $events = collect(app(Schedule::class)->events())->map->command->filter()->implode(' ');

        $this->assertStringContainsString('hani:sla-reminders', $events);
    }
}
