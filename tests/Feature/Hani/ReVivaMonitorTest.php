<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\ReVivaReminderLog;
use App\Modules\Hani\Notifications\ReVivaUpdate;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\BuildsReVivas;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * `re-viva:monitor`: the panel's time limit in working days (item e), and
 * the student deadlines and overdue flags (item i). Each sent once.
 */
class ReVivaMonitorTest extends TestCase
{
    use BuildsReVivas;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function monitor(): void
    {
        $this->artisan('re-viva:monitor')->assertSuccessful();
    }

    /**
     * Letters sent Fri 1 Oct 2027. Working days, skipping Deepavali (28 Oct)
     * and the Sultan of Perak's birthday (5 Nov): 29 Oct is day 19, 1 Nov
     * day 20, 8 Nov day 24, 9 Nov day 25.
     */
    public function test_the_panel_is_chased_at_twenty_working_days_and_escalated_at_twenty_five_once_each(): void
    {
        $viva = $this->roundUnderPanelReview();
        $senior = $this->senior();
        $chased = fn () => Notification::sent($viva['ae'], ReVivaUpdate::class)->filter(fn ($n) => $n->kind === 'panel_chase')->count();
        $escalated = fn () => Notification::sent($senior, ReVivaUpdate::class)->filter(fn ($n) => $n->kind === 'panel_escalate')->count();

        Carbon::setTestNow('2027-10-29 08:00');
        $this->monitor();
        $this->assertSame(0, $chased());

        Carbon::setTestNow('2027-11-01 08:00');
        $this->monitor();
        $this->monitor();
        $this->assertSame(1, $chased());
        $this->assertSame(0, $escalated());

        $this->actingAs($viva['ae'])->get(route('reviva.queue', ['stage' => 'under_panel_review']))
            ->assertSee('Chase the panel');

        Carbon::setTestNow('2027-11-08 08:00');
        $this->monitor();
        $this->assertSame(0, $escalated());

        Carbon::setTestNow('2027-11-09 08:00');
        $this->monitor();
        $this->monitor();
        $this->assertSame(1, $escalated());
        $this->assertSame(1, $chased());
    }

    public function test_nothing_is_chased_once_the_panel_has_reported(): void
    {
        $viva = $this->roundUnderPanelReview();
        $this->actingAs($viva['ae'])->post(route('reviva.decide', $viva['round']->application_id), ['decision' => 'approve']);
        $this->assertSame('report_received', Application::find($viva['round']->application_id)->current_stage);

        Carbon::setTestNow('2027-11-15 08:00');
        $this->monitor();

        Notification::assertNotSentTo($viva['ae'], ReVivaUpdate::class, fn ($n) => $n->kind === 'panel_chase');
    }

    public function test_dry_run_sends_and_logs_nothing_and_the_command_is_scheduled(): void
    {
        $this->roundUnderPanelReview();

        Carbon::setTestNow('2027-11-15 08:00');
        $this->artisan('re-viva:monitor', ['--dry-run' => true])
            ->expectsOutputToContain('would send panel_chase')
            ->assertSuccessful();

        Notification::assertSentTimes(ReVivaUpdate::class, 0);
        $this->assertSame(0, ReVivaReminderLog::count());

        $commands = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');
        $this->assertStringContainsString('re-viva:monitor', $commands);
    }
}
