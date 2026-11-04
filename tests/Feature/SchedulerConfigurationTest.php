<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Round 4 (A): the reminders must run by themselves. This checks the schedule the module registers (times, time zone, no
 * duplicates), that each job is due at its minute and not before, and that the Docker setup really has a process running
 * `schedule:work`. The shared routes/console.php still schedules some older lines (reported, not edited); only the module's
 * own four jobs are asserted here.
 */
class SchedulerConfigurationTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, list<\Illuminate\Console\Scheduling\Event>> */
    protected function jobs(): array
    {
        $out = [];

        foreach (app(Schedule::class)->events() as $event) {
            if (preg_match('/artisan[\'"]? ([a-z:-]+)/', (string) $event->command, $m)) {
                $out[$m[1]][] = $event;
            }
        }

        return $out;
    }

    public function test_the_four_module_jobs_are_scheduled_once_each_at_the_agreed_time_in_kuala_lumpur(): void
    {
        $expected = [
            'candidacy:close-expired-appeals' => '30 7 * * *',
            'workstation:refresh-daily' => '30 7 * * *',
            'workstation:send-reminders' => '0 8 * * *',
            'candidacy:send-reminders' => '0 8 * * *',
        ];

        $jobs = $this->jobs();

        foreach ($expected as $command => $cron) {
            $this->assertCount(1, $jobs[$command] ?? [], "{$command} must be scheduled exactly once");
            $this->assertSame($cron, $jobs[$command][0]->expression, $command);
            $this->assertSame('Asia/Kuala_Lumpur', (string) $jobs[$command][0]->timezone, $command);
            $this->assertTrue($jobs[$command][0]->withoutOverlapping, "{$command} must not overlap itself");
        }

        $this->assertSame('Asia/Kuala_Lumpur', config('app.timezone'));
    }

    public function test_a_job_is_due_in_its_own_minute_and_not_the_minute_before(): void
    {
        $job = $this->jobs()['candidacy:send-reminders'][0];

        Carbon::setTestNow(Carbon::parse('2026-11-04 07:59:30', 'Asia/Kuala_Lumpur'));
        $this->assertFalse($job->isDue(app()));

        Carbon::setTestNow(Carbon::parse('2026-11-04 08:00:20', 'Asia/Kuala_Lumpur'));
        $this->assertTrue($job->isDue(app()), 'the 08:00 reminders job runs inside the 08:00 minute');

        Carbon::setTestNow(Carbon::parse('2026-11-04 08:01:00', 'Asia/Kuala_Lumpur'));
        $this->assertFalse($job->isDue(app()));

        // the same instant read in UTC is 00:00: the job still follows Kuala Lumpur time, not the server clock
        Carbon::setTestNow(Carbon::parse('2026-11-04 00:00:20', 'UTC'));
        $this->assertTrue($job->isDue(app()));
    }

    public function test_schedule_run_starts_the_0800_jobs_when_the_clock_is_inside_that_minute(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        \Illuminate\Support\Facades\Mail::fake();

        Carbon::setTestNow(Carbon::parse('2026-11-04 08:00:10', 'Asia/Kuala_Lumpur'));
        $this->artisan('schedule:run')
            ->expectsOutputToContain('candidacy:send-reminders')
            ->expectsOutputToContain('workstation:send-reminders')
            ->assertSuccessful();

        Carbon::setTestNow(Carbon::parse('2026-11-04 07:30:10', 'Asia/Kuala_Lumpur'));
        $this->artisan('schedule:run')
            ->expectsOutputToContain('candidacy:close-expired-appeals')
            ->expectsOutputToContain('workstation:refresh-daily')
            ->assertSuccessful();
    }

    public function test_the_refresh_runs_before_the_reminders(): void
    {
        $jobs = $this->jobs();

        $this->assertSame('30 7 * * *', $jobs['workstation:refresh-daily'][0]->expression);
        $this->assertSame('0 8 * * *', $jobs['workstation:send-reminders'][0]->expression);
    }

    public function test_the_docker_setup_runs_the_scheduler_and_the_queue_worker(): void
    {
        $compose = file_get_contents(base_path('docker-compose.yml'));

        $this->assertMatchesRegularExpression('/^  scheduler:\n(?:.*\n)*?    command: php artisan schedule:work\n/m', $compose);
        $this->assertMatchesRegularExpression('/^  scheduler:\n(?:.*\n)*?    restart: unless-stopped\n/m', $compose);
        $this->assertMatchesRegularExpression('/^  queue:\n(?:.*\n)*?    command: php artisan queue:work\n/m', $compose);
        $this->assertStringContainsString('MAIL_HOST: mailpit', $compose);
    }
}
