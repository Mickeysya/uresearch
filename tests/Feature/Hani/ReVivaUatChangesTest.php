<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Notifications\ReVivaMeetingInvite;
use App\Modules\Hani\Notifications\ReVivaUpdate;
use App\Modules\Hani\Support\ReVivaDocuments;
use App\Modules\Hani\Support\ReVivaExaminerAccounts;
use App\Modules\Hani\Support\ReVivaLetters;
use App\Modules\Hani\Support\ReVivaRecipients;
use App\Modules\Hani\Support\WorkingDays;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\BuildsReVivas;
use Tests\TestCase;

/**
 * The re-viva changes from the AE + CGS UAT session (11 Nov 2026): the
 * final-thesis window's minimum, Master 20 / Doctoral 30 working days, the
 * three CGS recipients, and who is invited to the consolidation meeting.
 */
class ReVivaUatChangesTest extends TestCase
{
    use BuildsReVivas;
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

    protected function syahmi(): User
    {
        return User::firstOrCreate(['email' => 'syahmi.cgs@utp.edu.my'], ['name' => 'En Syahmi', 'password' => 'password', 'role' => Role::NON_EXEC_CGS]);
    }

    protected function passedAt(int $level): array
    {
        $viva = $this->roundAtReview($level, $level);
        $this->act($viva['round'], 'decide-outcome', $this->senior(), ['outcome_level' => $level])->assertSessionHasNoErrors();
        $viva['round']->refresh();

        return $viva;
    }

    protected function uploadFinal(array $viva)
    {
        return $this->actingAs($viva['student'])->post(route('reviva.final-thesis.store', $viva['round']), [
            'final_thesis' => UploadedFile::fake()->create('final.pdf', 50, 'application/pdf'),
        ]);
    }

    public function test_level_two_final_thesis_is_blocked_before_one_month_and_allowed_after(): void
    {
        $viva = $this->passedAt(2);
        $outcome = now()->startOfDay();
        $this->assertSame($outcome->copy()->addMonthsNoOverflow(3)->toDateString(), $viva['round']->correction_deadline->toDateString());
        $this->assertSame($outcome->copy()->addMonthsNoOverflow(1)->toDateString(), $viva['round']->finalOpensOn()->toDateString());

        $opens = $viva['round']->finalOpensOn()->format('j M Y');
        $this->actingAs($viva['student'])->get(route('reviva.mine'))->assertOk()
            ->assertSee("You may submit your final thesis from {$opens}.")
            ->assertSee('between '.$opens.' and '.$viva['round']->correction_deadline->format('j M Y'));
        $this->uploadFinal($viva)->assertRedirect(route('reviva.mine'))->assertSessionHas('error', "You may submit your final thesis from {$opens}.");
        $this->assertNull($viva['round']->refresh()->final_thesis_submitted_at);

        Carbon::setTestNow($outcome->copy()->addMonthsNoOverflow(1)->setTime(9, 0));
        $this->uploadFinal($viva)->assertSessionHasNoErrors();
        $this->assertNotNull($viva['round']->refresh()->final_thesis_submitted_at);
    }

    public function test_level_three_is_blocked_before_three_months_and_late_is_allowed_but_overdue(): void
    {
        $viva = $this->passedAt(3);
        $outcome = now()->startOfDay();

        Carbon::setTestNow($outcome->copy()->addMonthsNoOverflow(3)->subDay()->setTime(9, 0));
        $this->uploadFinal($viva)->assertSessionHas('error');

        Carbon::setTestNow($outcome->copy()->addMonthsNoOverflow(6)->addDays(2)->setTime(9, 0));
        $this->assertTrue($viva['round']->refresh()->finalThesisOverdue());
        $this->artisan('re-viva:monitor')->assertSuccessful();
        $this->artisan('re-viva:monitor')->assertSuccessful();
        $overdue = Notification::sent($this->senior(), ReVivaUpdate::class)->filter(fn ($n) => $n->kind === 'final_overdue');
        $this->assertCount(1, $overdue, 'CGS is told once');

        $this->uploadFinal($viva)->assertSessionHasNoErrors();
        $this->assertNotNull($viva['round']->refresh()->final_thesis_submitted_at);
    }

    public function test_the_result_email_gives_both_dates(): void
    {
        $viva = $this->passedAt(2);
        $round = $viva['round'];
        $text = 'between '.$round->finalOpensOn()->format('j F Y').' and '.$round->correction_deadline->format('j F Y');
        Notification::assertSentTo($viva['student'], ReVivaUpdate::class, fn ($n) => str_contains($n->text(), $text));
    }

    public function test_master_gets_twenty_working_days_and_doctoral_thirty(): void
    {
        $master = $this->roundUnderPanelReview('22001001', 'MSc Full-Time')['round'];
        $this->assertSame(20, ReVivaLetters::panelWorkingDays($master));
        $this->assertSame(WorkingDays::add($master->sent_to_panel_at, 20)->toDateString(), $master->panel_deadline->toDateString());

        $phd = $this->roundUnderPanelReview('22001002', 'PhD Full-Time')['round'];
        $this->assertSame('doctoral', ReVivaLetters::level($phd));
        $this->assertSame(30, ReVivaLetters::panelWorkingDays($phd));
        $this->assertSame(WorkingDays::add($phd->sent_to_panel_at, 30)->toDateString(), $phd->panel_deadline->toDateString());
        $this->assertTrue(ReVivaLetters::panelLinkExpiry($phd)->gt($phd->panel_deadline), 'the link outlives the deadline');
    }

    public function test_the_letter_says_thirty_working_days_for_a_doctoral_thesis(): void
    {
        $round = $this->roundSubmitted('22001003', 'PhD Full-Time')['round'];
        $examiner = $round->currentMains()['internal_main_id'];
        $data = ReVivaDocuments::candidate($round->application->student, $round->vivaDetail(), $round);
        $days = ReVivaLetters::panelWorkingDays($round);
        $html = view('hani::re_viva.pdf.correction_letter', $data + [
            'reference' => 'X', 'date' => now(), 'examiner' => $examiner, 'examinerPlace' => [],
            'workingDays' => $days, 'workingDaysWords' => ReVivaLetters::words($days), 'letter' => config('hani.reviva.letter'),
            'contacts' => config('hani.reviva.return_contacts'), 'signatory' => ReVivaDocuments::signatory($round), 'round' => 1, 'draft' => false,
        ])->render();

        $this->assertStringContainsString('thirty (30) working days', $html);
    }

    public function test_doctoral_reminders_go_five_three_and_one_working_days_before_once_each(): void
    {
        $round = $this->roundUnderPanelReview('22001004', 'PhD Full-Time')['round'];
        $account = ReVivaExaminerAccounts::find($round->currentMains()['internal_main_id']);
        $at = function (int $left) use ($round) {
            $day = $round->panel_deadline->copy();
            while (WorkingDays::between($day, $round->panel_deadline) < $left) {
                $day->subDay();
            }
            Carbon::setTestNow($day->setTime(9, 0));
        };

        foreach ([10, 5, 3, 1] as $left) {
            $at($left);
            $this->artisan('re-viva:monitor')->assertSuccessful();
            $this->artisan('re-viva:monitor')->assertSuccessful();
        }

        $sent = Notification::sent($account, ReVivaUpdate::class)->filter(fn ($n) => $n->kind === 'examiner_reminder');
        $this->assertCount(3, $sent, 'one each at 5, 3 and 1; none at 10 for a Doctoral thesis');
    }

    public function test_cgs_bound_emails_go_only_to_waheda_syahmi_and_the_departments_ae(): void
    {
        $syahmi = $this->syahmi();
        $otherSenior = User::create(['name' => 'Another Senior Exec', 'email' => 'another.senior@test.my', 'password' => 'password', 'role' => Role::SENIOR_EXEC_CGS]);
        $manager = User::create(['name' => 'Manager CGS', 'email' => 'manager@test.my', 'password' => 'password', 'role' => Role::MANAGER_CGS]);
        $otherAe = $this->academicExecFor('Management');
        $this->senior();

        $viva = $this->roundSubmitted();
        $ae = $this->academicExecFor('Computing');

        $this->assertEqualsCanonicalizing(
            [$this->senior()->id, $syahmi->id, $ae->id],
            ReVivaRecipients::cgs($viva['round']->application)->pluck('id')->all(),
        );

        Notification::assertSentTo($this->senior(), ReVivaUpdate::class, fn ($n) => $n->kind === 'submitted');
        Notification::assertSentTo($syahmi, ReVivaUpdate::class, fn ($n) => $n->kind === 'submitted');
        Notification::assertSentTo($ae, ReVivaUpdate::class, fn ($n) => $n->kind === 'submitted');
        foreach ([$otherSenior, $manager, $otherAe] as $nobody) {
            Notification::assertNotSentTo($nobody, ReVivaUpdate::class);
        }

        // Only Waheda's copy has the action; Syahmi's points at Cycles; the AE's has none.
        $mail = fn (User $to) => Notification::sent($to, ReVivaUpdate::class)->first(fn ($n) => $n->kind === 'submitted')->toMail($to);
        $this->assertStringContainsString('/re-viva/queue', (string) $mail($this->senior())->actionUrl);
        $this->assertSame(route('reviva.cycles.index'), $mail($syahmi)->actionUrl);
        $this->assertNull($mail($ae)->actionUrl);
        $this->assertStringContainsString('no action is needed', implode(' ', $mail($ae)->introLines + $mail($ae)->outroLines));
    }

    public function test_dean_return_reaches_the_three_recipients_and_not_the_engine_list(): void
    {
        $syahmi = $this->syahmi();
        $otherSenior = User::create(['name' => 'Another Senior Exec', 'email' => 'another.senior@test.my', 'password' => 'password', 'role' => Role::SENIOR_EXEC_CGS]);
        $this->senior();
        $viva = $this->roundSubmitted();
        $this->act($viva['round'], 'accept', $this->senior());
        $this->act($viva['round'], 'send-to-dean', $this->senior());
        $this->act($viva['round'], 'dean-return', $this->deanUser(), ['reason' => 'Wrong date'])->assertSessionHasNoErrors();

        Notification::assertSentTo($this->senior(), ReVivaUpdate::class, fn ($n) => $n->kind === 'dean_returned' && str_contains($n->text(), 'Wrong date'));
        Notification::assertSentTo($syahmi, ReVivaUpdate::class, fn ($n) => $n->kind === 'dean_returned');
        Notification::assertNotSentTo($otherSenior, ReVivaUpdate::class);
        // The engine's own return email (which Notification::fake() records
        // before any listener runs) is cancelled when it is really sent.
        $application = $viva['round']->application->refresh();
        $stage = app(\App\Modules\Core\Services\WorkflowEngine::class)->currentStage($application);
        $returned = new \App\Modules\Core\Notifications\ApplicationReturned($application, $stage, $stage, 'Wrong date');
        $this->assertFalse(\Illuminate\Support\Facades\Event::until(new \Illuminate\Notifications\Events\NotificationSending($otherSenior, $returned, 'mail')));
    }

    public function test_consolidation_invites_go_to_waheda_the_mains_and_the_chair_and_the_student_and_supervisor_are_only_told(): void
    {
        $viva = $this->roundAtReview(3, 4, null, 1);
        $this->act($viva['round'], 'schedule-meeting', $this->senior(), [
            'meeting_kind' => 'consolidation', 'meeting_at' => now()->addDays(5)->format('Y-m-d H:i'), 'meeting_venue' => 'CGS Meeting Room',
        ])->assertSessionHasNoErrors();

        $round = $viva['round']->refresh();
        Notification::assertSentTo($this->senior(), ReVivaMeetingInvite::class);
        foreach (array_merge(array_map(fn ($e) => $e->usableEmail(), $round->currentMains()), ['chair22001001@example.com']) as $email) {
            Notification::assertSentOnDemand(ReVivaMeetingInvite::class, fn ($n, $channels, $notifiable) => array_key_exists($email, $notifiable->routes['mail']));
        }

        foreach ([$viva['student'], $viva['supervisor']] as $told) {
            Notification::assertNotSentTo($told, ReVivaMeetingInvite::class);
            Notification::assertSentTo($told, ReVivaUpdate::class, function ($n) use ($round) {
                $text = $n->text();

                return $n->kind === 'meeting' && str_contains($text, 'A consolidation meeting will be held on '.$round->meeting_at->format('j F Y, g:i a'))
                    && collect($round->currentMains())->every(fn ($e) => ! str_contains($text, $e->name));
            });
        }
    }

    public function test_a_missing_chairman_is_warned_about_and_the_typed_one_is_invited(): void
    {
        $viva = $this->roundAtReview(3, 4, null, 1);
        $viva['detail']->update(['chair_name' => null, 'chair_email' => null]);

        $this->actingAs($this->senior())->get(route('reviva.queue', ['stage' => 'cgs_review']))->assertOk()
            ->assertSee('The viva chairman is not on record');

        $this->act($viva['round'], 'schedule-meeting', $this->senior(), [
            'meeting_kind' => 'consolidation', 'meeting_at' => now()->addDays(5)->format('Y-m-d H:i'), 'meeting_venue' => 'Room 3',
            'meeting_chair_name' => 'Prof Typed Chair',
        ])->assertSessionHasErrors('meeting_chair_email');

        $this->act($viva['round'], 'schedule-meeting', $this->senior(), [
            'meeting_kind' => 'consolidation', 'meeting_at' => now()->addDays(5)->format('Y-m-d H:i'), 'meeting_venue' => 'Room 3',
            'meeting_chair_name' => 'Prof Typed Chair', 'meeting_chair_email' => 'typed.chair@example.com',
        ])->assertSessionHasNoErrors();

        Notification::assertSentOnDemand(ReVivaMeetingInvite::class, fn ($n, $channels, $notifiable) => array_key_exists('typed.chair@example.com', $notifiable->routes['mail']));
    }
}
