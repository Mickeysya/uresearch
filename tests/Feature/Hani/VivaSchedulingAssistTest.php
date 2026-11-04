<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaReminderLog;
use App\Modules\Hani\Notifications\VivaReminder;
use App\Modules\Hani\Notifications\VivaScheduled;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\BuildsVivas;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Set Date warns about clashes, weekends and holidays and suggests free
 * slots; viva:reminders sends the panel and softbound reminders once.
 */
class VivaSchedulingAssistTest extends TestCase
{
    use BuildsVivas;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
        config(['hani.scheduling.public_holidays' => ['2027-01-12' => 'Test Holiday']]);
        Carbon::setTestNow('2027-01-04 08:00');   // a Monday
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function schedule(array $viva, string $date, string $time = '10:00', array $overrides = [])
    {
        return $this->actingAs($viva['ae'])->post(route('viva.schedule.update', $viva['notice']), $overrides + [
            'viva_date' => $date, 'viva_time' => $time, 'venue' => 'Room 1', 'chair_name' => 'Prof Chair', 'chair_email' => 'chair@example.com',
        ]);
    }

    public function test_a_clash_on_the_same_examiner_chairman_supervisor_or_venue_is_warned_and_can_be_overridden(): void
    {
        $first = $this->viva('22001001');
        $second = $this->viva('22001002');
        $this->schedule($first, '2027-01-14')->assertSessionHasNoErrors()->assertSessionMissing('scheduleWarnings');

        // Same chairman and venue, an hour later.
        $this->schedule($second, '2027-01-14', '11:00')
            ->assertRedirect()
            ->assertSessionHas('scheduleWarnings', fn ($w) => collect($w)->contains(fn ($m) => str_contains($m, 'chairman (chair@example.com) is already chairing'))
                && collect($w)->contains(fn ($m) => str_contains($m, 'Room 1 is already booked')))
            ->assertSessionHas('slotSuggestions', fn ($s) => count($s) === 3);
        $this->assertNull(VivaDetail::where('application_id', $second['notice']->id)->value('viva_at'));
        Notification::assertNotSentTo($second['student'], VivaScheduled::class);

        // Same examiner (a shared external main) and supervisor, different chair and room.
        $second['panel']->update(['external_main_id' => $first['panel']->external_main_id]);
        $second['student']->update(['supervisor_id' => $first['student']->supervisor_id]);
        $this->schedule($second, '2027-01-14', '11:30', ['venue' => 'Room 9', 'chair_email' => 'other.chair@example.com'])
            ->assertSessionHas('scheduleWarnings', fn ($w) => collect($w)->contains(fn ($m) => str_starts_with($m, 'Examiner External main 22001001 is already at'))
                && collect($w)->contains(fn ($m) => str_contains($m, 'The supervisor is already at')));

        // Two hours later is not a clash.
        $this->schedule($second, '2027-01-14', '12:00', ['venue' => 'Room 9', 'chair_email' => 'other.chair@example.com'])
            ->assertSessionMissing('scheduleWarnings');

        // The AE can override.
        $this->schedule($second, '2027-01-14', '11:00', ['override_warnings' => 1])->assertSessionHasNoErrors()->assertSessionMissing('scheduleWarnings');
        $this->assertSame('2027-01-14 11:00', VivaDetail::where('application_id', $second['notice']->id)->value('viva_at')->format('Y-m-d H:i'));
    }

    public function test_weekends_and_public_holidays_are_warned_and_suggestions_skip_them(): void
    {
        $viva = $this->viva();

        $this->schedule($viva, '2027-01-09')   // Saturday
            ->assertSessionHas('scheduleWarnings', ['Saturday, 9 Jan 2027 is a weekend.']);
        $this->schedule($viva, '2027-01-12')
            ->assertSessionHas('scheduleWarnings', ['12 Jan 2027 is a public holiday (Test Holiday).']);

        // From the Monday 11th: 11th 09:00 and 14:00, then the 13th (12th is the holiday).
        $this->schedule($viva, '2027-01-11', '09:00', ['venue' => 'Room 1']);
        $other = $this->viva('22001009');
        $this->schedule($other, '2027-01-11', '09:30')
            ->assertSessionHas('slotSuggestions', fn ($s) => array_map(fn ($d) => $d->format('Y-m-d H:i'), $s) === ['2027-01-11 14:00', '2027-01-13 09:00', '2027-01-13 14:00']);

        $this->actingAs($viva['ae'])->get(route('viva.schedule.edit', $other['notice']))
            ->assertOk()
            ->assertSee('Next free weekday slots');
    }

    public function test_panel_reminders_go_7_days_and_1_day_before_once_each_with_the_calendar(): void
    {
        $viva = $this->viva();
        $this->schedule($viva, '2027-01-14', '10:00', ['override_warnings' => 1]);   // Thursday, 10 days out

        $this->artisan('viva:reminders')->assertSuccessful();
        $this->assertSame(0, VivaReminderLog::count(), 'nothing 10 days out');

        Carbon::setTestNow('2027-01-07 07:45');   // 7 days before
        $this->artisan('viva:reminders')->assertSuccessful();
        $this->artisan('viva:reminders')->assertSuccessful();
        Notification::assertSentToTimes($viva['student'], VivaReminder::class, 1);
        Notification::assertSentOnDemand(VivaReminder::class, fn ($n, $c, AnonymousNotifiable $to) => $n->kind === 'panel_7d'
            && array_key_exists('chair@example.com', (array) $to->routes['mail'])
            && $n->toMail($to)->rawAttachments[0]['name'] === 'viva-voce.ics');

        Carbon::setTestNow('2027-01-13 07:45');   // the day before
        $this->artisan('viva:reminders')->assertSuccessful();
        $this->artisan('viva:reminders')->assertSuccessful();
        Notification::assertSentToTimes($viva['student'], VivaReminder::class, 2);
        $this->assertSame(['panel_1d', 'panel_7d'], VivaReminderLog::orderBy('kind')->pluck('kind')->all());

        // Rescheduled: the new date gets its own reminders.
        $this->schedule($viva, '2027-01-20', '10:00', ['override_warnings' => 1]);
        Carbon::setTestNow('2027-01-19 07:45');
        $this->artisan('viva:reminders')->assertSuccessful();
        $this->assertSame(3, VivaReminderLog::count());
    }

    public function test_a_viva_set_less_than_7_days_ahead_gets_only_the_1_day_reminder(): void
    {
        // Later today: panel_1d at the next run, never a late panel_7d.
        $today = $this->viva('22001011');
        $this->schedule($today, '2027-01-04', '15:00', ['override_warnings' => 1]);

        // Tomorrow.
        $tomorrow = $this->viva('22001012');
        $this->schedule($tomorrow, '2027-01-05', '10:00', ['venue' => 'Room 2', 'chair_email' => 'chair2@example.com', 'override_warnings' => 1]);

        // Three days out: nothing now (the invitation is fresh), panel_1d the day before.
        $soon = $this->viva('22001013');
        $this->schedule($soon, '2027-01-07', '10:00', ['venue' => 'Room 3', 'chair_email' => 'chair3@example.com', 'override_warnings' => 1]);

        $this->artisan('viva:reminders')->assertSuccessful();
        $this->artisan('viva:reminders')->assertSuccessful();

        $kinds = fn (array $viva) => VivaReminderLog::where('viva_application_id', $viva['notice']->id)->orderBy('kind')->pluck('kind')->all();
        $this->assertSame(['panel_1d'], $kinds($today));
        $this->assertSame(['panel_1d'], $kinds($tomorrow));
        $this->assertSame([], $kinds($soon));
        Notification::assertSentTo($today['student'], VivaReminder::class, fn ($n) => $n->kind === 'panel_1d' && str_starts_with($n->toMail($today['student'])->subject, 'Viva today: '));
        Notification::assertNotSentTo($today['student'], VivaReminder::class, fn ($n) => $n->kind === 'panel_7d');

        Carbon::setTestNow('2027-01-06 07:45');
        $this->artisan('viva:reminders')->assertSuccessful();
        $this->assertSame(['panel_1d'], $kinds($soon));
        $this->assertSame(3, VivaReminderLog::count());
    }

    public function test_softbound_nudge_and_overdue_notice_are_sent_once(): void
    {
        $viva = $this->viva(softbound: false, panel: false);
        $viva['detail']->update(['expected_softbound_date' => '2027-01-30']);

        $this->artisan('viva:reminders');
        Notification::assertNotSentTo($viva['student'], VivaReminder::class);

        Carbon::setTestNow('2027-01-17 07:45');   // 13 days before
        $this->artisan('viva:reminders');
        $this->artisan('viva:reminders');
        Notification::assertSentToTimes($viva['student'], VivaReminder::class, 1);

        Carbon::setTestNow('2027-02-01 07:45');   // passed
        $this->artisan('viva:reminders');
        $this->artisan('viva:reminders');
        Notification::assertSentToTimes($viva['ae'], VivaReminder::class, 1);
        Notification::assertSentTo($viva['ae'], VivaReminder::class, fn ($n) => $n->kind === 'softbound_overdue');

        // Once filed, nobody is chased.
        $other = $this->viva('22001003', softbound: false, panel: false);
        $other['detail']->update(['expected_softbound_date' => '2027-01-31']);
        Application::create(['student_id' => $other['student']->id, 'module_type' => 'viva_softbound', 'status' => Application::STATUS_PENDING, 'current_stage' => 'softbound_check']);
        $this->artisan('viva:reminders');
        $this->assertSame(0, VivaReminderLog::where('viva_application_id', $other['notice']->id)->count());
        Notification::assertNotSentTo($other['student'], VivaReminder::class);
    }
}
