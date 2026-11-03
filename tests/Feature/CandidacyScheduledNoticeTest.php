<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Notifications\CandidacyAppealNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** The 07:30 job closes a returned appeal after the expiry date; the student's e-mail and bell are queued for 08:00 (immediate when run after 08:00). */
class CandidacyScheduledNoticeTest extends TestCase
{
    use BuildsCandidacyWorld;
    use MakesUsers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function returnedAppealPastExpiry(): CandidacyAppeal
    {
        Carbon::setTestNow(Carbon::parse('2027-03-01 07:30:00', 'Asia/Kuala_Lumpur'));
        $this->candidacy->update(['candidacy_expiry_date' => '2029-01-01']);
        $appeal = $this->submitted(3);
        $appeal->forceFill(['status' => CandidacyAppeal::STATUS_RETURNED])->save();
        $this->candidacy->update(['candidacy_expiry_date' => '2027-02-20']);

        return $appeal;
    }

    public function test_closed_as_expired_notice_is_queued_for_08_00_after_a_07_30_close(): void
    {
        $appeal = $this->returnedAppealPastExpiry();
        Notification::fake();

        $this->artisan('candidacy:close-expired-appeals')->assertSuccessful();

        $this->assertSame(CandidacyAppeal::STATUS_CLOSED_EXPIRED, $appeal->fresh()->status);
        $eight = Carbon::parse('2027-03-01 08:00:00', 'Asia/Kuala_Lumpur');
        Notification::assertSentTo($this->student, CandidacyAppealNotice::class, fn ($n) => $n->delay !== null && $n->delay->equalTo($eight));
    }

    public function test_the_same_job_run_after_08_00_sends_at_once(): void
    {
        $appeal = $this->returnedAppealPastExpiry();
        Carbon::setTestNow(Carbon::parse('2027-03-01 09:15:00', 'Asia/Kuala_Lumpur'));
        Notification::fake();

        $this->artisan('candidacy:close-expired-appeals')->assertSuccessful();

        $this->assertSame(CandidacyAppeal::STATUS_CLOSED_EXPIRED, $appeal->fresh()->status);
        Notification::assertSentTo($this->student, CandidacyAppealNotice::class, fn ($n) => $n->delay === null);
    }
}
