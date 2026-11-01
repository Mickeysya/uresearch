<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Notifications\LockerKeyNotice;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The daily schedule (Asia/Kuala_Lumpur): 07:30 REFRESH changes data only; 08:00 REMINDERS sends. Every notice a scheduled job owes a student is
 * delivered at 08:00: the state change is made at 07:30 and the notice is queued with a delay until 08:00 the same day.
 */
class WorkstationDailyScheduleTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, string> command => cron expression, for what this module owns */
    protected function scheduled(): array
    {
        $out = [];

        foreach (app(Schedule::class)->events() as $event) {
            if (preg_match('/artisan[\'"]? ([a-z:-]+)/', (string) $event->command, $m)) {
                $out[$m[1]] = $event->expression.' '.$event->timezone;
            }
        }

        return $out;
    }

    public function test_the_module_schedules_exactly_the_four_daily_jobs_at_the_agreed_times(): void
    {
        $jobs = $this->scheduled();

        $this->assertSame('30 7 * * * Asia/Kuala_Lumpur', $jobs['candidacy:close-expired-appeals']);
        $this->assertSame('30 7 * * * Asia/Kuala_Lumpur', $jobs['workstation:refresh-daily']);
        $this->assertSame('0 8 * * * Asia/Kuala_Lumpur', $jobs['workstation:send-reminders']);
        $this->assertSame('0 8 * * * Asia/Kuala_Lumpur', $jobs['candidacy:send-reminders']);
    }

    public function test_no_old_time_is_left_for_the_candidacy_jobs_and_the_old_candidacy_reminder_stays_a_no_op(): void
    {
        $jobs = $this->scheduled();

        foreach (['candidacy:close-expired-appeals', 'candidacy:send-reminders'] as $command) {
            $this->assertStringNotContainsString('20 7', $jobs[$command]);
            $this->assertStringNotContainsString('25 7', $jobs[$command]);
        }

        $this->artisan('candidacy:remind')->assertSuccessful();
        Notification::assertNothingSent();
    }

    /** @return array{0: \App\Modules\Core\Models\User, 1: LockerKey} */
    protected function pendingKey(int $daysAgo): array
    {
        $student = $this->student('22005555', 'keys@test.my');
        $room = WorkstationLocation::firstOrCreate(['room_code' => 'N2-02-01'], ['block' => 'N2', 'gender' => 'male', 'name' => 'N2-02-01']);
        $seat = Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '5', 'position' => 1, 'status' => Workstation::STATUS_OCCUPIED]);
        $request = WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $student->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()->subDays(30)]);
        $key = LockerKey::create(['workstation_request_id' => $request->id, 'student_id' => $student->id, 'status' => LockerKey::STATUS_REQUESTED, 'requested_at' => now()->subDays($daysAgo)]);

        return [$student, $key];
    }

    protected function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse("2026-11-03 {$time}", 'Asia/Kuala_Lumpur'));
    }

    public function test_the_07_30_refresh_cancels_the_key_at_once_but_the_notice_is_queued_for_08_00(): void
    {
        $this->at('07:30:00');
        [$student, $key] = $this->pendingKey(8);

        $this->artisan('workstation:refresh-daily')->assertSuccessful();

        $this->assertSame(LockerKey::STATUS_CANCELLED, $key->fresh()->status);
        $eight = Carbon::parse('2026-11-03 08:00:00', 'Asia/Kuala_Lumpur');
        Notification::assertSentTo($student, LockerKeyNotice::class, fn ($n) => $n->delay !== null && $n->delay->equalTo($eight));
    }

    public function test_a_refresh_run_after_08_00_delivers_at_once(): void
    {
        $this->at('09:10:00');
        [$student] = $this->pendingKey(8);

        $this->artisan('workstation:refresh-daily')->assertSuccessful();

        Notification::assertSentTo($student, LockerKeyNotice::class, fn ($n) => $n->delay === null);
    }

    public function test_the_page_load_sweep_stays_immediate_at_07_30(): void
    {
        $this->at('07:30:00');
        [$student, $key] = $this->pendingKey(8);

        $key->load(['student', 'workstationRequest.workstation.location'])->processIfOverdue();

        Notification::assertSentTo($student, LockerKeyNotice::class, fn ($n) => $n->delay === null);
    }

    public function test_the_refresh_sends_no_reminder_and_the_08_00_job_sends_it_once(): void
    {
        $this->at('07:30:00');
        [$student, $key] = $this->pendingKey(4);

        $this->artisan('workstation:refresh-daily')->assertSuccessful();
        Notification::assertNothingSent();

        $this->at('08:00:00');
        $this->artisan('workstation:send-reminders')->assertSuccessful();
        $this->artisan('workstation:send-reminders')->assertSuccessful();
        $this->artisan('workstation:remind-locker-keys')->assertSuccessful(); // the old name, still in the shared schedule, must not repeat it

        Notification::assertSentToTimes($student, LockerKeyNotice::class, 1);
        $this->assertNotNull($key->fresh()->reminder_sent_at);
    }

    public function test_running_the_refresh_twice_cancels_and_notifies_once(): void
    {
        $this->at('07:30:00');
        [$student] = $this->pendingKey(8);

        $this->artisan('workstation:refresh-daily')->assertSuccessful();
        $this->artisan('workstation:refresh-daily')->assertSuccessful();
        $this->artisan('workstation:process-releases')->assertSuccessful();
        $this->artisan('workstation:remind-locker-keys')->assertSuccessful();

        Notification::assertSentToTimes($student, LockerKeyNotice::class, 1);
    }

    public function test_the_old_command_name_before_08_00_queues_its_reminder_for_08_00_not_07_30(): void
    {
        $this->at('07:30:00');
        [$student] = $this->pendingKey(4);

        $this->artisan('workstation:remind-locker-keys')->assertSuccessful();

        $eight = Carbon::parse('2026-11-03 08:00:00', 'Asia/Kuala_Lumpur');
        Notification::assertSentTo($student, LockerKeyNotice::class, fn ($n) => $n->delay !== null && $n->delay->equalTo($eight));
    }

    public function test_the_delivery_helper_only_delays_inside_a_refresh_before_08_00(): void
    {
        $notice = fn () => new \App\Modules\Chloe\Notifications\CandidacyAppealNotice('x', [], true);
        $eight = Carbon::parse('2026-11-03 08:00:00', 'Asia/Kuala_Lumpur');

        $this->at('07:30:00');
        $this->assertNull(\App\Modules\Chloe\Support\ScheduledDelivery::apply($notice())->delay, 'outside a refresh job: immediate');
        $this->assertTrue(\App\Modules\Chloe\Support\ScheduledDelivery::refreshing(fn () => \App\Modules\Chloe\Support\ScheduledDelivery::apply($notice())->delay->equalTo($eight)));

        $this->at('08:00:00');
        $this->assertNull(\App\Modules\Chloe\Support\ScheduledDelivery::refreshing(fn () => \App\Modules\Chloe\Support\ScheduledDelivery::apply($notice())->delay), 'from 08:00 on: immediate');
    }
}
