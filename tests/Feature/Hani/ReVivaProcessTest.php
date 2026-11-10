<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Models\ReVivaStaffDocument;
use App\Modules\Hani\Notifications\ReVivaCorrectionLetter;
use App\Modules\Hani\Notifications\ReVivaMeetingInvite;
use App\Modules\Hani\Notifications\ReVivaUpdate;
use App\Modules\Hani\Support\ReVivaDocuments;
use App\Modules\Hani\Support\ReVivaExaminerAccounts;
use App\Modules\Hani\Support\ReVivaLetters;
use App\Modules\Hani\Support\ReVivaSettings;
use App\Modules\Hani\Support\WorkingDays;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\BuildsReVivas;
use Tests\TestCase;

class ReVivaProcessTest extends TestCase
{
    use BuildsReVivas, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        $this->senior();
        $this->deanUser();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function stage(ReVivaDetail $round): string
    {
        return $round->application->refresh()->current_stage;
    }

    public function test_academic_executive_has_no_re_viva_access(): void
    {
        $viva = $this->roundSubmitted();
        $ae = $viva['ae'];

        foreach (['reviva.queue', 'reviva.cycles.index', 'reviva.outcomes.index', 'reviva.settings', 'reviva.mine'] as $route) {
            $this->actingAs($ae)->get(route($route))->assertForbidden();
        }
        $this->act($viva['round'], 'accept', $ae)->assertForbidden();
        $this->assertSame([], app(\App\Modules\Hani\Workflows\ReVivaWorkflow::class)->links($ae));
        $this->assertEmpty(collect(app(\App\Modules\Core\Services\ModuleRegistry::class)->queuesForRole(Role::ACADEMIC_EXEC))->filter(fn ($q) => $q['module']->key() === 're_viva'));
    }

    public function test_non_executive_reads_cycles_only(): void
    {
        $viva = $this->roundSubmitted();
        $non = User::create(['name' => 'Non Exec', 'email' => 'non@test.my', 'password' => 'password', 'role' => Role::NON_EXEC_CGS]);

        $this->actingAs($non)->get(route('reviva.cycles.index'))->assertOk();
        $this->actingAs($non)->get(route('reviva.queue'))->assertForbidden();
        $this->act($viva['round'], 'accept', $non)->assertForbidden();
    }

    public function test_forms_blank_names_panel_example_is_fictional_and_pages_hide_examiners(): void
    {
        $viva = $this->roundSubmitted();
        $student = $viva['student'];
        $this->actingAs($student)->get(route('reviva.forms', ['confirmation', 'blank']))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($student)->get(route('reviva.forms', ['summary', 'example']))->assertOk();

        $mains = $viva['round']->currentMains();
        $blank = app(ReVivaDocuments::class);
        $this->assertNotEmpty($blank->form('confirmation', 'blank', $student));

        $page = $this->actingAs($student)->get(route('reviva.mine'))->assertOk();
        foreach ($mains as $examiner) {
            $page->assertDontSee($examiner->name);
        }
    }

    public function test_three_pdfs_are_required(): void
    {
        Carbon::setTestNow('2027-03-15 09:00');
        $viva = $this->categoryFourViva();
        Carbon::setTestNow('2027-10-01 09:00');

        $this->actingAs($viva['student'])->post(route('reviva.submit'), ['thesis' => UploadedFile::fake()->create('t.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors(['confirmation', 'summary']);
        $this->actingAs($viva['student'])->post(route('reviva.submit'), ['confirmation' => UploadedFile::fake()->create('c.txt', 10, 'text/plain')] + $this->studentFiles())
            ->assertSessionHasErrors('confirmation');
        $this->actingAs($viva['student'])->post(route('reviva.submit'), $this->studentFiles())->assertSessionHasNoErrors();
        $this->actingAs($viva['student'])->post(route('reviva.submit'), $this->studentFiles())->assertForbidden();
    }

    public function test_student_return_loop_keeps_history(): void
    {
        $viva = $this->roundSubmitted();
        $round = $viva['round'];

        $this->act($round, 'return', $this->senior(), ['documents' => ['Summary of Corrections'], 'reason' => 'Unsigned'])->assertSessionHasNoErrors();
        $this->assertSame(Application::STATUS_RETURNED, $round->application->refresh()->status);

        $this->actingAs($viva['student'])->post(route('reviva.resubmit', $round), ['summary' => UploadedFile::fake()->create('s2.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
        $this->assertSame(Application::STATUS_PENDING, $round->application->refresh()->status);
        $this->assertSame('student_submitted', $this->stage($round));
        $this->assertNotNull($round->documentReturns()->first()->resubmitted_at);
        $this->assertSame('s2.pdf', $round->studentDocuments()['Summary of Corrections']->original_name);
    }

    public function test_dean_return_loop_and_approval(): void
    {
        $viva = $this->roundSubmitted();
        $round = $viva['round'];
        $this->act($round, 'accept', $this->senior())->assertSessionHasNoErrors();
        $this->assertSame(4, $round->staffDocuments()->count());

        $this->act($round, 'send-to-dean', $this->senior());
        $this->act($round, 'dean-return', $this->senior())->assertSessionHasErrors();
        $this->act($round, 'dean-return', $this->deanUser(), ['reason' => 'Wrong date'])->assertSessionHasNoErrors();
        $this->assertSame('pack_preparation', $this->stage($round));

        $this->act($round, 'send-to-dean', $this->senior());
        $this->actingAs($this->deanUser())->get(route('reviva.queue', ['stage' => 'dean_approval']))->assertOk()->assertSee('returned by the Dean of PGR')->assertSee('Wrong date');
        $this->act($round, 'dean-return', $this->deanUser(), ['reason' => ''])->assertSessionHasErrors('reason');

        $this->act($round, 'dean-approve', $this->deanUser())->assertSessionHasErrors('signature');
        $this->assertSame('dean_approval', $this->stage($round));
        $this->actingAs($this->deanUser())->get(route('reviva.queue', ['stage' => 'dean_approval']))->assertSee('Open My Signatures');

        $this->deanSigns();
        $this->act($round, 'dean-approve', $this->deanUser())->assertSessionHasNoErrors();
        $this->assertSame('ready_to_send', $this->stage($round));
        $this->assertNotNull($round->refresh()->dean_approved_at);
    }

    public function test_letter_view_has_the_required_content(): void
    {
        $viva = $this->roundSubmitted();
        $round = $viva['round'];
        $examiner = $round->currentMains()['internal_main_id'];
        $data = ReVivaDocuments::candidate($round->application->student, $round->vivaDetail(), $round);
        $render = fn (bool $draft) => view('hani::re_viva.pdf.correction_letter', $data + [
            'reference' => 'UTP/PGS/AD/22001001', 'date' => now(), 'examiner' => $examiner, 'examinerPlace' => [],
            'workingDays' => 20, 'workingDaysWords' => 'twenty', 'letter' => config('hani.reviva.letter'),
            'contacts' => config('hani.reviva.return_contacts'), 'signatory' => config('hani.reviva.signatory'), 'round' => 1, 'draft' => $draft,
        ])->render();

        $draft = $render(true);
        $this->assertStringContainsString('DRAFT', $draft);
        $this->assertStringNotContainsString('M Fadzil Hassan', $draft);
        $final = $render(false);
        $this->assertStringContainsString('M Fadzil Hassan', $final);
        foreach (['UTP/PGS/AD/22001001', $examiner->name, $round->application->student->name, 'twenty (20) working days', 'waheda.cgs@utp.edu.my', 'syahmi.cgs@utp.edu.my', $data['degreeName']] as $text) {
            $this->assertStringContainsString(e($text), $final);
        }
        $this->assertStringNotContainsString('Full-Time', $final);
    }

    public function test_send_to_panel_makes_accounts_and_sets_the_working_day_deadline(): void
    {
        $viva = $this->roundUnderPanelReview();
        $round = $viva['round'];

        $this->assertSame('under_panel_review', $this->stage($round));
        $this->assertSame(WorkingDays::add(now(), 20)->toDateString(), $round->panel_deadline->toDateString());
        foreach ($round->currentMains() as $examiner) {
            $this->assertNotNull(ReVivaExaminerAccounts::find($examiner));
        }
        Notification::assertSentOnDemandTimes(ReVivaCorrectionLetter::class, 2);
    }

    public function test_pack_attaches_five_items_and_a_calendar_or_a_link_over_the_limit(): void
    {
        $viva = $this->roundUnderPanelReview();
        $letter = $viva['round']->staffDocuments()->where('kind', ReVivaStaffDocument::LETTER)->first();
        $mail = (new ReVivaCorrectionLetter($letter))->toMail(new \Illuminate\Notifications\AnonymousNotifiable);
        $this->assertCount(6, $mail->rawAttachments); // 5 items + .ics

        config(['hani.reviva.attachment_limit_mb' => 0]);
        $mail = (new ReVivaCorrectionLetter($letter))->toMail(new \Illuminate\Notifications\AnonymousNotifiable);
        $this->assertCount(3, $mail->rawAttachments); // letter, form, .ics
        $this->assertNotNull($mail->viewData['panelLink']);
        $this->assertStringContainsString('Open the corrected thesis', (string) $mail->render());

        $this->get($mail->viewData['panelLink'].'x')->assertForbidden();
    }

    public function test_the_examiner_email_links_my_re_evaluations_and_keeps_urls_out_of_the_sentences(): void
    {
        $viva = $this->roundUnderPanelReview();
        $letter = $viva['round']->staffDocuments()->where('kind', ReVivaStaffDocument::LETTER)->first();
        $mail = (new ReVivaCorrectionLetter($letter))->toMail(new \Illuminate\Notifications\AnonymousNotifiable);
        $url = route('reviva.examiner.index');
        $setup = $mail->viewData['setupUrl'];
        $this->assertNotNull($setup); // a new examiner account, password not set yet

        $html = (string) $mail->render();
        // The mail theme inlines CSS, so the anchors carry a style attribute.
        $this->assertMatchesRegularExpression('/through <a href="'.preg_quote($url, '/').'"[^>]*>My Re-evaluations<\/a> in UResearch or by email to the contacts in the letter\./', $html);
        $this->assertStringContainsString('Open My Re-evaluations', $html);
        $this->assertStringContainsString('>Set your password</a>', $html);
        $this->assertStringNotContainsString('('.$url.')', $html);
        $this->assertStringNotContainsString('here: http', $html);

        $text = $mail->view['text']()->toHtml();
        $this->assertStringContainsString('through My Re-evaluations in UResearch or by email to the contacts in the letter.', $text);
        $this->assertMatchesRegularExpression('/^Open My Re-evaluations: '.preg_quote($url, '/').'$/m', $text);
        $this->assertMatchesRegularExpression('/^Set your password: '.preg_quote($setup, '/').'$/m', $text);
        $this->assertStringNotContainsString('](', $text);
    }

    public function test_an_external_examiner_signs_in_and_returns_their_forms_only_for_their_case(): void
    {
        $viva = $this->roundUnderPanelReview();
        $round = $viva['round'];
        $account = ReVivaExaminerAccounts::find($round->currentMains()['external_main_id']);

        $this->get(ReVivaExaminerAccounts::setupLink($account))->assertOk();
        $link = ReVivaExaminerAccounts::setupLink($account);
        $this->post($link, ['password' => 'a-new-password', 'password_confirmation' => 'a-new-password'])->assertRedirect(route('login'));
        $this->get($link)->assertForbidden();

        $this->actingAs($account->refresh())->get(route('reviva.examiner.index'))->assertOk()->assertSee($viva['student']->name);
        $this->actingAs($account)->post(route('reviva.examiner.upload', $round), [
            'category' => 2,
            'report' => UploadedFile::fake()->create('r.pdf', 10, 'application/pdf'),
            'signed_letter' => UploadedFile::fake()->create('s.pdf', 10, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, $round->refresh()->currentEvaluations()->get('external_main_id')->category);

        $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger@test.my', 'password' => 'password', 'role' => Role::FACULTY]);
        $this->actingAs($stranger)->get(route('reviva.examiner.index'))->assertForbidden();
        $this->actingAs($stranger)->post(route('reviva.examiner.upload', $round), ['category' => 1])->assertForbidden();
    }

    public function test_reminders_use_the_system_setting_and_send_once(): void
    {
        $viva = $this->roundUnderPanelReview();
        ReVivaSettings::saveReminderDays([15, 4], $this->senior()->id);
        $this->assertSame([15, 4], ReVivaSettings::reminderDays());

        $at = function (int $left) use ($viva) {
            $day = $viva['round']->panel_deadline->copy();
            while (WorkingDays::between($day, $viva['round']->panel_deadline) < $left) {
                $day->subDay();
            }
            Carbon::setTestNow($day->setTime(9, 0));
        };

        $at(4);
        $this->artisan('re-viva:monitor')->assertSuccessful();
        $this->artisan('re-viva:monitor')->assertSuccessful();
        $sent = Notification::sent(ReVivaExaminerAccounts::find($viva['round']->currentMains()['internal_main_id']), ReVivaUpdate::class)
            ->filter(fn ($n) => $n->kind === 'examiner_reminder');
        $this->assertCount(1, $sent);

        Carbon::setTestNow($viva['round']->panel_deadline->copy()->addDays(3));
        $this->artisan('re-viva:monitor')->assertSuccessful();
        Notification::assertSentTo($this->senior(), ReVivaUpdate::class, fn ($n) => $n->kind === 'panel_overdue');
    }

    public function test_settings_page_saves_reminders(): void
    {
        $this->actingAs($this->senior())->post(route('reviva.settings.save'), ['days' => [7, 2, '']])->assertSessionHasNoErrors();
        $this->assertSame([7, 2], ReVivaSettings::reminderDays());
    }

    public function test_start_review_needs_both_reports_then_agreeing_pass_records_the_deadline(): void
    {
        $viva = $this->roundUnderPanelReview();
        $this->act($viva['round'], 'start-review', $this->senior())->assertSessionHasErrors('review');
        $this->recordReturn($viva['round'], 'internal_main_id', 2)->assertSessionHasNoErrors();
        $this->recordReturn($viva['round'], 'external_main_id', 2)->assertSessionHasNoErrors();
        $this->act($viva['round'], 'start-review', $this->senior())->assertSessionHasNoErrors();
        $this->assertSame('cgs_review', $this->stage($viva['round']));

        $this->act($viva['round'], 'decide-outcome', $this->senior(), ['outcome_level' => 2])->assertSessionHasNoErrors();
        $round = $viva['round']->refresh();
        $this->assertSame(Application::STATUS_APPROVED, $round->application->refresh()->status);
        $this->assertSame(2, $round->outcome_level);
        $this->assertSame(now()->addMonthsNoOverflow(3)->toDateString(), $round->correction_deadline->toDateString());
    }

    public function test_diverging_reports_need_remarks_and_dismissal_needs_remarks(): void
    {
        $viva = $this->roundAtReview(2, 4, null, 0);
        $this->act($viva['round'], 'decide-outcome', $this->senior(), ['outcome_level' => 5])->assertSessionHasErrors('outcome_remarks');
        $this->act($viva['round'], 'decide-outcome', $this->senior(), ['outcome_level' => 5, 'outcome_remarks' => 'Final'])->assertSessionHasNoErrors();
        $this->assertSame(5, $viva['round']->refresh()->outcome_level);
    }

    public function test_meeting_invites_then_level_four_opens_a_new_round(): void
    {
        $viva = $this->roundAtReview(3, 4, null, 1);
        $this->act($viva['round'], 'schedule-meeting', $this->senior(), ['meeting_kind' => 're_viva', 'meeting_at' => now()->addDays(5)->format('Y-m-d H:i'), 'meeting_venue' => 'Room 2'])->assertSessionHasNoErrors();
        $this->assertSame('meeting_scheduled', $this->stage($viva['round']));
        Notification::assertSentTo($viva['student'], ReVivaMeetingInvite::class);

        $this->act($viva['round'], 'meeting-result', $this->senior(), ['outcome_level' => 4, 'outcome_remarks' => 'Agreed'])->assertSessionHasNoErrors();
        $this->actingAs($viva['student'])->post(route('reviva.submit'), $this->studentFiles())->assertSessionHasNoErrors();
        $this->assertSame(2, $this->latestRound($viva['student'])->cycle_number);
    }

    public function test_tampered_panel_link_is_refused_and_the_link_shows_only_sign_in(): void
    {
        $viva = $this->roundUnderPanelReview();
        $examiner = $viva['round']->currentMains()['external_main_id'];
        $link = ReVivaLetters::panelLink($viva['round'], $examiner);

        $this->get($link)->assertOk()->assertSee('Examiner sign-in')->assertDontSee($viva['student']->name);
        $this->get($link.'tampered')->assertForbidden();
    }

    public function test_the_dean_has_a_required_re_viva_signature_kind(): void
    {
        $kind = app(\App\Modules\Hani\Workflows\ReVivaWorkflow::class)->signatureFor($this->deanUser());
        $this->assertSame('Re-viva Correction Letters', $kind['title']);
        $this->assertTrue($kind['required']);
        $this->assertNull(app(\App\Modules\Hani\Workflows\ReVivaWorkflow::class)->signatureFor($this->senior()));

        $this->actingAs($this->deanUser())->post(route('reviva.signature.store'), ['signature' => UploadedFile::fake()->image('sig.png'), 'return' => '/signatures'])
            ->assertRedirect('/signatures');
        $this->assertNotNull(\App\Modules\Hani\Models\ReVivaSignature::forUser($this->deanUser()->id));
    }

    public function test_a_failed_examiner_email_keeps_the_round_at_ready_to_send_and_a_retry_sends_only_the_rest(): void
    {
        $viva = $this->roundReadyToSend();
        $external = $viva['round']->currentMains()['external_main_id'];
        $email = $external->email;
        $external->update(['email' => null]);

        $this->act($viva['round'], 'send-to-panel', $this->senior())->assertSessionHas('error');
        $this->assertSame('ready_to_send', $this->stage($viva['round']));
        Notification::assertSentOnDemandTimes(ReVivaCorrectionLetter::class, 1);

        $external->update(['email' => $email]);
        $this->act($viva['round'], 'send-to-panel', $this->senior())->assertSessionHas('status');
        $this->assertSame('under_panel_review', $this->stage($viva['round']));
        Notification::assertSentOnDemandTimes(ReVivaCorrectionLetter::class, 2);
        Notification::assertSentTo($viva['student'], ReVivaUpdate::class, fn ($n) => $n->kind === 'sent_to_examiners');
    }

    public function test_the_student_page_names_the_round_once_and_fits_the_ten_step_tracker(): void
    {
        $viva = $this->roundSubmitted();
        $html = $this->actingAs($viva['student'])->get(route('reviva.mine'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'Re-viva round 1'));
        $this->assertStringContainsString('stepper stepper-dense', $html);
    }

    public function test_the_engine_stage_emails_to_a_re_viva_student_are_cancelled(): void
    {
        $viva = $this->roundSubmitted();
        $application = $viva['round']->application;
        $stage = app(\App\Modules\Core\Services\WorkflowEngine::class)->currentStage($application);
        $send = fn ($to) => \Illuminate\Support\Facades\Event::until(new \Illuminate\Notifications\Events\NotificationSending($to, new \App\Modules\Core\Notifications\ApplicationDecided($application, $stage, true), 'mail'));

        $this->assertFalse($send($viva['student']));
        $this->assertNotFalse($send($this->senior()));
        Notification::assertSentTo($viva['student'], ReVivaUpdate::class, fn ($n) => $n->kind === 'student_submitted');
    }

    public function test_forms_use_times_the_logo_and_border_and_the_blank_status_leaves_names_for_handwriting(): void
    {
        $viva = $this->roundSubmitted();
        $student = $viva['student'];
        $data = (fn () => $this->blankData($student))->call(app(ReVivaDocuments::class));

        $status = view('hani::re_viva.pdf.forms.status', $data)->render();
        $this->assertStringContainsString('Times New Roman', $status);
        $this->assertStringContainsString('class="frame"', $status);
        $this->assertStringContainsString('data:image/png;base64', $status);
        $this->assertStringNotContainsString(mb_strtoupper($student->name), $status);
        if ($student->supervisor) {
            $this->assertStringNotContainsString($student->supervisor->name, $status);
        }

        $confirmation = view('hani::re_viva.pdf.forms.confirmation', $data)->render();
        $this->assertStringContainsString(e($student->name), $confirmation);
        foreach ($viva['round']->currentMains() as $examiner) {
            $this->assertStringContainsString(e($examiner->name), $confirmation);
        }
    }
}
