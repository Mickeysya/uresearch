<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ReVivaEvaluation;
use App\Modules\Hani\Models\ReVivaLetterSend;
use App\Modules\Hani\Models\ReVivaStaffDocument;
use App\Modules\Hani\Notifications\ReVivaCorrectionLetter;
use App\Modules\Hani\Support\PanelSeats;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Hani\Concerns\BuildsReVivas;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * "Resend letter": while a round is Under Panel Review, the AE of the
 * student's department or the Senior Executive re-sends an examiner their
 * correction letter (the same stored letter) with a fresh link, logged,
 * with "last sent" shown per examiner.
 */
class ReVivaResendTest extends TestCase
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

    protected function letterTo(array $viva, string $seat = 'external_main_id'): ReVivaStaffDocument
    {
        return ReVivaStaffDocument::where('re_viva_detail_id', $viva['round']->id)->where('seat', $seat)->where('kind', ReVivaStaffDocument::LETTER)->sole();
    }

    /** @return array<int, array{0: ReVivaCorrectionLetter, 1: object}> resends, with their notifiables */
    protected function resends(): array
    {
        $found = [];
        Notification::assertSentOnDemand(ReVivaCorrectionLetter::class, function ($n, $channels, $notifiable) use (&$found) {
            if ($n->send?->kind === ReVivaLetterSend::RESEND) {
                $found[] = [$n, $notifiable];
            }

            return true;
        });

        return $found;
    }

    public function test_a_letter_already_emailed_keeps_working_after_its_record_is_merged(): void
    {
        $viva = $this->roundUnderPanelReview();
        $letter = $this->letterTo($viva);
        $old = Examiner::find($viva['panel']->external_main_id);
        $kept = Examiner::create(['name' => 'Kept Record', 'email' => 'kept.record@example.org', 'department' => 'External', 'type' => Examiner::TYPE_EXTERNAL, 'institution' => 'UM', 'is_active' => true]);
        $evaluation = ReVivaEvaluation::create(['re_viva_detail_id' => $viva['round']->id, 'seat' => 'external_main_id', 'examiner_id' => $old->id, 'category' => 1]);

        // The link as it went out, carrying the old record's id.
        $link = URL::temporarySignedRoute('reviva.panel.documents', now()->addDay(), ['detail' => $viva['round']->id, 'examiner' => $old->id]);
        $this->get($link)->assertOk();

        $this->actingAs(User::where('role', Role::SENIOR_EXEC_CGS)->first() ?? $this->seniorExec())
            ->post(route('conflict-detection.merge'), ['record_a_id' => $kept->id, 'record_b_id' => $old->id, 'keep_id' => $kept->id])
            ->assertSessionHas('status');

        $this->assertSame($kept->id, $viva['panel']->fresh()->external_main_id);
        $this->assertSame($kept->id, $letter->fresh()->examiner_id);
        $this->assertSame($kept->id, $evaluation->fresh()->examiner_id);

        auth()->logout();
        $this->get($link)->assertOk();
    }

    public function test_the_ae_resends_the_same_letter_with_a_fresh_link_after_the_first_expired(): void
    {
        $viva = $this->roundUnderPanelReview(); // sent Fri 1 Oct 2027: link until 5 Nov
        $letter = $this->letterTo($viva);
        $examiner = Examiner::find($viva['panel']->external_main_id);

        // The first send is logged too.
        $first = ReVivaLetterSend::where('re_viva_staff_document_id', $letter->id)->sole();
        $this->assertSame(ReVivaLetterSend::FIRST, $first->kind);
        $this->assertSame($viva['ae']->id, $first->sent_by_id);

        Carbon::setTestNow('2027-11-16 10:00');
        $this->actingAs($viva['ae'])->get(route('reviva.queue', ['stage' => 'under_panel_review']))
            ->assertOk()
            ->assertSee(route('reviva.letters.resend', $letter), false)
            ->assertSee('Resend letter');

        $this->actingAs($viva['ae'])->from(route('reviva.queue', ['stage' => 'under_panel_review']))
            ->post(route('reviva.letters.resend', $letter))
            ->assertRedirect(route('reviva.queue', ['stage' => 'under_panel_review']))
            ->assertSessionHas('status', "Correction letter resent to {$examiner->name}, with a new link that works until 21 December 2027.");

        // Same letter: no new file, no new letter row.
        $this->assertSame(2, ReVivaStaffDocument::where('kind', ReVivaStaffDocument::LETTER)->count());

        $resends = $this->resends();
        $this->assertCount(1, $resends);
        [$notification, $notifiable] = $resends[0];
        $this->assertArrayHasKey($examiner->email, $notifiable->routes['mail']);
        $this->assertTrue($notification->letter->is($letter));

        // Built as the queue worker builds it: a fresh link that opens logged out.
        $mail = $notification->toMail($notifiable);
        $this->assertStringStartsWith('Resent: Correction of thesis for re-evaluation', $mail->subject);
        $this->assertStringContainsString('The link works until 21 December 2027.', implode(' ', $mail->introLines));
        $this->assertCount(1, $mail->rawAttachments, 'the same letter PDF attached');
        auth()->logout();
        $this->get($mail->actionUrl)->assertOk();

        // Logged: who, when, the link date; and on the activity log.
        $resend = ReVivaLetterSend::where('re_viva_staff_document_id', $letter->id)->where('kind', ReVivaLetterSend::RESEND)->sole();
        $this->assertTrue($resend->emailed);
        $this->assertSame($viva['ae']->id, $resend->sent_by_id);
        $this->assertSame('2027-11-16 10:00', $resend->created_at->format('Y-m-d H:i'));
        $this->assertSame('2027-12-21', $resend->link_expires_at->toDateString());
        $this->assertDatabaseHas('activity_log', ['log_name' => 're_viva', 'description' => 'Re-viva correction letter resent', 'causer_id' => $viva['ae']->id]);

        // "Last sent" per examiner, on the AE's queue and the Senior Executive's Cycles.
        $this->actingAs($viva['ae'])->get(route('reviva.queue', ['stage' => 'under_panel_review']))
            ->assertSee("last sent 16 Nov 2027, 10:00 am by {$viva['ae']->name} (resent); link works until 21 Dec 2027", false);
        $this->actingAs($this->senior())->get(route('reviva.cycles.index'))
            ->assertSee('last sent 16 Nov 2027, 10:00 am', false)
            ->assertSee(route('reviva.letters.resend', $letter), false);
    }

    public function test_only_the_departments_ae_and_the_senior_executive_may_resend(): void
    {
        $viva = $this->roundUnderPanelReview();
        $letter = $this->letterTo($viva);
        $nonExec = $this->cgs();

        $this->actingAs($this->academicExecFor('Chemical Engineering'))->post(route('reviva.letters.resend', $letter))->assertForbidden();
        $this->actingAs($nonExec)->post(route('reviva.letters.resend', $letter))->assertForbidden();
        $this->actingAs($viva['student'])->post(route('reviva.letters.resend', $letter))->assertForbidden();
        $this->assertCount(0, $this->resends());

        // Non-Executive: read-only Cycles, with the last-sent date but no button.
        $this->actingAs($nonExec)->get(route('reviva.cycles.index'))
            ->assertSee('last sent')
            ->assertDontSee(route('reviva.letters.resend', $letter), false);

        $this->actingAs($this->senior())->post(route('reviva.letters.resend', $letter))->assertSessionHas('status');
        $this->assertCount(1, $this->resends());
    }

    public function test_a_flagged_email_is_not_resent_and_says_why(): void
    {
        $viva = $this->roundUnderPanelReview();
        $letter = $this->letterTo($viva);
        $examiner = Examiner::find($viva['panel']->external_main_id);
        $examiner->update(['email_status' => Examiner::EMAIL_INVALID, 'email_flagged_at' => now()]);

        $this->actingAs($viva['ae'])->post(route('reviva.letters.resend', $letter))
            ->assertSessionHas('warning', fn ($w) => str_contains($w, "{$examiner->name}'s email was reported not working")
                && str_contains($w, 'The letter was not resent; update the examiner\'s email first.'));

        $this->assertCount(0, $this->resends());
        $refused = ReVivaLetterSend::where('re_viva_staff_document_id', $letter->id)->where('kind', ReVivaLetterSend::RESEND)->sole();
        $this->assertFalse($refused->emailed);
        $this->assertNotNull($refused->skipped_reason);
        $this->assertNull($refused->link_expires_at);
    }

    public function test_only_under_panel_review_and_only_to_a_current_main(): void
    {
        // Stage 1: no letters yet, nothing to resend. After stage 2: refused.
        $viva = $this->roundUnderPanelReview();
        $letter = $this->letterTo($viva, 'internal_main_id');

        // An examiner who declined after the letters went out: refused.
        app(PanelSeats::class)->decline($viva['panel'], 'internal_main_id', 'Retired.', $this->senior());
        $this->actingAs($viva['ae'])->post(route('reviva.letters.resend', $letter))
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'no longer holds a main seat'));

        $external = $this->letterTo($viva);
        $this->advance($viva); // Under Panel Review -> Report Received
        $this->actingAs($viva['ae'])->post(route('reviva.letters.resend', $external))
            ->assertSessionHas('error', 'Letters can be resent only while the round is Under Panel Review.');
        $this->actingAs($viva['ae'])->get(route('reviva.queue', ['stage' => 'report_received']))
            ->assertDontSee(route('reviva.letters.resend', $external), false);

        $this->assertCount(0, $this->resends());
    }

    public function test_the_expired_link_page_says_to_ask_cgs_to_resend(): void
    {
        $viva = $this->roundUnderPanelReview();
        $link = \App\Modules\Hani\Support\ReVivaLetters::panelLink($viva['round'], Examiner::find($viva['panel']->external_main_id));

        Carbon::setTestNow('2027-11-16 10:00');
        $this->get($link)->assertStatus(410)->assertSee('ask the Centre for Graduate Studies to resend your letter');
    }

    public function test_a_letter_that_no_longer_exists_redirects_back_with_a_message(): void
    {
        $viva = $this->roundUnderPanelReview();
        $from = route('reviva.queue', ['stage' => 'under_panel_review']);

        $this->actingAs($viva['ae'])->from($from)->post(route('reviva.letters.resend', 999999))
            ->assertRedirect($from)
            ->assertSessionHas('warning', fn ($w) => str_contains($w, 'no longer exists, so nothing was changed'));
    }
}
