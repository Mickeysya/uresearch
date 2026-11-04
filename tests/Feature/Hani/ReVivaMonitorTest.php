<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ReVivaReminderLog;
use App\Modules\Hani\Models\VivaReminderLog;
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

        // After a real run, a dry run says what a real run would now do:
        // the chase is "already sent" (Prompt 4: it used to repeat
        // "would send" for reminders already logged).
        $this->artisan('re-viva:monitor')->assertSuccessful();
        $this->artisan('re-viva:monitor', ['--dry-run' => true])
            ->doesntExpectOutputToContain('would send panel_chase')
            ->expectsOutputToContain('already sent.')
            ->assertSuccessful();

        $commands = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');
        $this->assertStringContainsString('re-viva:monitor', $commands);
    }

    protected function kinds($user, string $kind): int
    {
        return Notification::sent($user, ReVivaUpdate::class)->filter(fn ($n) => $n->kind === $kind)->count();
    }

    public function test_the_student_is_reminded_before_the_category_four_window_closes_until_a_round_is_logged(): void
    {
        Carbon::setTestNow('2027-03-15 09:00');
        $waiting = $this->categoryFourViva('22001001');   // window closes 1 Mar 2028
        $logged = $this->categoryFourViva('22001002');
        Carbon::setTestNow('2027-10-01 09:00');
        $this->logReViva($logged['student']);

        Carbon::setTestNow('2028-01-31 08:00');   // 30 days left
        $this->monitor();
        $this->monitor();
        $this->assertSame(1, $this->kinds($waiting['student'], 'window_closing'));
        $this->assertSame(0, $this->kinds($logged['student'], 'window_closing'));
        $this->assertSame(1, VivaReminderLog::where('kind', 'reviva_window_30d')->count());

        Carbon::setTestNow('2028-02-23 08:00');   // 7 days left
        $this->monitor();
        $this->assertSame(2, $this->kinds($waiting['student'], 'window_closing'));
    }

    public function test_a_level_four_window_reminds_the_student_then_frees_the_examiners_once_it_closes(): void
    {
        $viva = $this->roundAwaitingOutcome([4, true], [4, true]);
        Carbon::setTestNow('2027-11-20 10:00');
        $this->recordOutcome($viva, 4)->assertSessionHasNoErrors();   // next round due by 20 May 2028
        $senior = $this->senior();

        Carbon::setTestNow('2028-04-20 08:00');
        $this->monitor();
        $this->assertSame(1, $this->kinds($viva['student'], 'window_closing'));

        Carbon::setTestNow('2028-05-21 08:00');
        $this->monitor();
        $this->monitor();
        $this->assertSame(1, $this->kinds($viva['ae'], 'next_round_window_closed'));
        $this->assertSame(1, $this->kinds($senior, 'next_round_window_closed'));

        $examiner = Examiner::find($viva['panel']->internal_main_id);
        $this->assertNull($examiner->assigned_until);
        $this->assertNull($examiner->last_examination_date, 'freed with no gap');

        $this->actingAs($senior)->get(route('reviva.cycles.index'))->assertSee('Window closed: examiners freed');
    }

    public function test_final_thesis_reminders_then_one_overdue_flag(): void
    {
        $viva = $this->roundAwaitingOutcome([1], [1]);
        Carbon::setTestNow('2027-11-20 10:00');
        $this->recordOutcome($viva, 1);   // final thesis due 20 Dec 2027
        $senior = $this->senior();

        Carbon::setTestNow('2027-11-21 08:00');
        $this->monitor();
        $this->monitor();
        $this->assertSame(1, $this->kinds($viva['student'], 'final_due'));

        Carbon::setTestNow('2027-12-13 08:00');
        $this->monitor();
        $this->assertSame(2, $this->kinds($viva['student'], 'final_due'));

        Carbon::setTestNow('2027-12-21 08:00');
        $this->monitor();
        $this->monitor();
        $this->assertSame(1, $this->kinds($viva['ae'], 'final_overdue'));
        $this->assertSame(1, $this->kinds($senior, 'final_overdue'));
        $this->assertSame(2, $this->kinds($viva['student'], 'final_due'));

        $this->actingAs($senior)->get(route('reviva.cycles.index'))
            ->assertSee('Final thesis due (1)')
            ->assertSee('Overdue');
    }

    public function test_a_submitted_final_thesis_stops_the_student_reminders(): void
    {
        $viva = $this->roundAwaitingOutcome([2], [2]);
        Carbon::setTestNow('2027-11-20 10:00');
        $this->recordOutcome($viva, 2);   // due 20 Feb 2028
        $this->actingAs($viva['student'])->post(route('reviva.final-thesis.store', $viva['round']), [
            'final_thesis' => \Illuminate\Http\UploadedFile::fake()->create('final.pdf', 100, 'application/pdf'),
        ]);

        Carbon::setTestNow('2028-02-13 08:00');
        $this->monitor();
        $this->assertSame(0, $this->kinds($viva['student'], 'final_due'));
    }
}
