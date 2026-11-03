<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppealEvent;
use App\Modules\Chloe\Notifications\CandidacyAppealNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** The Dean's rejection needs a second, final confirmation; nothing is saved before it. (The dialog behaviour itself is checked in the browser.) */
class CandidacyRejectionConfirmTest extends TestCase
{
    use BuildsCandidacyWorld;
    use MakesUsers;
    use RefreshDatabase;

    public function test_the_reject_modal_only_opens_the_second_dialog_and_the_second_dialog_carries_the_consequences(): void
    {
        $appeal = $this->toStage('dean');
        $html = $this->actingAs($this->deanUser)->get(route('candidacy.appeals.show', $appeal))->assertOk()->getContent();

        // the first modal's button does not submit
        preg_match('~<form[^>]*id="wca-reject-form".*?</form>~s', $html, $form);
        $this->assertStringContainsString('data-confirm-open="wca-confirm-reject"', $form[0]);
        $this->assertDoesNotMatchRegularExpression('~type="submit"~', $form[0], 'nothing in the first modal posts the decision');

        // the second dialog
        preg_match('~<div class="wca-modal wca-confirm" id="wca-confirm-reject".*?</div>\s*</div>\s*</div>~s', $html, $dlg);
        $text = html_entity_decode(strip_tags($dlg[0]));
        $this->assertStringContainsString('Confirm rejection of '.$appeal->appeal_no, $text);
        $this->assertStringContainsString('You are about to reject this appeal. This cannot be undone.', $text);
        $this->assertStringContainsString('The appeal is closed as Rejected (final).', $text);
        $this->assertStringContainsString('No further appeal can be submitted for this candidacy.', $text);
        $this->assertStringContainsString('The expiry date stays at 31 Jan 2027.', $text);
        $this->assertStringContainsString('The student can still submit the Softbound thesis before 31 Jan 2027. If no Softbound is submitted by then, the student becomes a dismissal candidate for CGS to review.', $text);
        $this->assertStringContainsString('The student, the Supervisor / Programme Manager, the Department Chair and CGS are notified by e-mail and bell.', $text);
        $this->assertStringContainsString('data-confirm-close', $dlg[0]);
        $this->assertStringContainsString('is-danger', $dlg[0]);
        $this->assertLessThan(strpos($dlg[0], 'Confirm rejection</button>'), strpos($dlg[0], 'Discard</button>'), 'Discard comes first');
        $this->assertStringContainsString('role="alertdialog"', $dlg[0]);
    }

    public function test_the_dialog_script_closes_only_the_second_dialog_on_esc_outside_click_and_discard_and_blocks_a_double_click(): void
    {
        $js = file_get_contents(public_path('js/chloe-candidacy.js'));

        $this->assertStringContainsString("if (e.key === 'Escape') { if (openConfirms().length) { closeConfirms(); }", $js);
        $this->assertStringContainsString("classList.contains('wca-confirm')) { closeConfirms()", $js);
        $this->assertStringContainsString("data-confirm-close", $js);
        $this->assertStringContainsString("submit.getAttribute('data-sent')", $js);
        $this->assertStringContainsString('submit.disabled = true', $js);
        $this->assertStringContainsString("discard.focus()", $js);
    }

    public function test_nothing_happens_until_the_decision_is_posted(): void
    {
        $appeal = $this->toStage('dean');
        Notification::fake();
        $events = CandidacyAppealEvent::count();

        $this->actingAs($this->deanUser)->get(route('candidacy.appeals.show', $appeal))->assertOk(); // open modal, read dialog, Discard / Esc: no request is made

        $this->assertSame('with_dean', $appeal->fresh()->status);
        $this->assertSame($events, CandidacyAppealEvent::count());
        Notification::assertNothingSent();
    }

    public function test_confirming_rejects_once_and_a_second_post_changes_nothing(): void
    {
        $appeal = $this->toStage('dean');
        Notification::fake();
        $post = fn () => $this->actingAs($this->deanUser)->post(route('candidacy.portal.decide', $appeal), ['action' => 'reject', 'reasons' => ['plan_not_realistic']]);

        $post()->assertSessionHasNoErrors();
        $events = CandidacyAppealEvent::where('candidacy_appeal_id', $appeal->id)->where('action', 'rejected')->count();
        $sent = Notification::sent($this->student, CandidacyAppealNotice::class)->count();

        $post()->assertForbidden(); // the double click's second request: the appeal is no longer pending the Dean

        $this->assertSame('rejected', $appeal->fresh()->status);
        $this->assertSame(1, $events);
        $this->assertSame(1, CandidacyAppealEvent::where('candidacy_appeal_id', $appeal->id)->where('action', 'rejected')->count());
        $this->assertSame($sent, Notification::sent($this->student, CandidacyAppealNotice::class)->count());
    }

    public function test_the_students_rejection_e_mail_and_the_detail_page_mention_the_softbound_thesis(): void
    {
        $appeal = $this->toStage('dean');
        Notification::fake();
        $this->service->decide($this->deanUser, $appeal, 'reject', ['plan_not_realistic']);

        Notification::assertSentTo($this->student, CandidacyAppealNotice::class, function (CandidacyAppealNotice $n) {
            $mail = implode(' ', $n->toMail($this->student)->introLines);

            return $n->template === 'rejected_student'
                && str_contains($mail, 'You can still submit your Softbound thesis before 31 Jan 2027')
                && str_contains($mail, 'dismissal candidate');
        });
        $this->actingAs($this->student)->get(route('candidacy.appeals.show', $appeal))->assertSee('The student may still submit the Softbound thesis before the expiry date.');
    }
}
