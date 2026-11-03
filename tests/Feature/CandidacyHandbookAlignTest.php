<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyAppealEvent;
use App\Modules\Chloe\Notifications\CandidacyAppealNotice;
use App\Modules\Chloe\Support\CandidacyAppealPresenter as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** Handbook alignment: Department Chair, Dean rejection reasons, the expiry day, the four Operations cards. */
class CandidacyHandbookAlignTest extends TestCase
{
    use BuildsCandidacyWorld;
    use MakesUsers;
    use RefreshDatabase;

    // ---- Department Chair (display only) ------------------------------------------------------------------------

    public function test_the_chair_is_called_department_chair_everywhere_it_is_shown_but_keys_are_unchanged(): void
    {
        $appeal = $this->toStage('chair');
        $this->assertSame('Department Chair', P::STAGES['chair']);
        $this->assertSame('With Department Chair', P::statusLabel($appeal));
        $this->assertSame('chair', $appeal->current_stage);      // stored key unchanged
        $this->assertSame('with_chair', $appeal->status);        // stored value unchanged

        $this->actingAs($this->student)->get(route('candidacy.appeals.show', $appeal))->assertSee('Department Chair')->assertDontSee('Programme Chair');
        $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.operations'))->assertSee('Department Chair')->assertDontSee('Programme Chair');
        $this->assertSame('Department Chair', app(\App\Modules\Chloe\Services\CandidacyAppealService::class)->stageLabel('chair'));
        $this->assertSame('Department Chair', (new \App\Modules\Chloe\Workflows\CandidacyAppealWorkflow)->stages()[1]->label);
    }

    public function test_the_memo_says_department_chair_and_the_codes_still_verify(): void
    {
        $appeal = $this->service->decide($this->deanUser, $this->toStage('dean', 2), 'approve');

        $this->assertStringNotContainsString('Programme Chair', file_get_contents(app_path('Modules/Chloe/Resources/views/candidacy/memo/appeal_memo.blade.php')));
        $this->artisan('candidacy:verify-memo', ['appeal_no' => $appeal->appeal_no])->assertSuccessful();
    }

    // ---- Dean rejection reasons ---------------------------------------------------------------------------------

    public function test_the_dean_reject_list_is_the_agreed_list(): void
    {
        $this->assertSame([
            'Research progress is insufficient to justify an extension', 'Completion plan is not realistic within the requested period',
            'Supporting documents are insufficient or invalid', 'Reason for the delay is not acceptable', 'Previous extension was not used as planned', 'Other (remark required)',
        ], array_values(config('candidacy.reasons.dean.reject')));
    }

    public function test_a_rejection_needs_a_reason_and_other_needs_a_remark(): void
    {
        $appeal = $this->toStage('dean');

        foreach ([[[], null], [['invented'], null], [['other'], null], [['other'], '  ']] as [$reasons, $remark]) {
            try {
                $this->service->decide($this->deanUser, $appeal, 'reject', $reasons, $remark);
                $this->fail('rejected with an invalid reason');
            } catch (ValidationException $e) {
                $this->assertTrue(isset($e->errors()['reasons']) || isset($e->errors()['remark']));
            }
        }
        $this->assertSame('with_dean', $appeal->fresh()->status);

        $done = $this->service->decide($this->deanUser, $appeal, 'reject', ['other'], 'Specific explanation.');
        $this->assertSame('rejected', $done->status);
    }

    public function test_the_modal_states_the_consequence_and_lists_the_reasons(): void
    {
        $appeal = $this->toStage('dean');
        $html = $this->actingAs($this->deanUser)->get(route('candidacy.appeals.show', $appeal))->getContent();

        $this->assertStringContainsString('No further appeals will be accepted for this candidacy. The expiry date stays unchanged.', $html);
        $this->assertStringContainsString('Completion plan is not realistic within the requested period', $html);
    }

    public function test_reasons_are_stored_with_the_decision_and_shown_on_page_email_and_bell(): void
    {
        $appeal = $this->toStage('dean');
        Notification::fake();
        $this->service->decide($this->deanUser, $appeal, 'reject', ['plan_not_realistic', 'documents_insufficient'], 'See the plan.');

        $event = CandidacyAppealEvent::where('candidacy_appeal_id', $appeal->id)->where('action', 'rejected')->first();
        $this->assertSame(['plan_not_realistic', 'documents_insufficient'], $event->reason_codes);
        $this->assertSame('See the plan.', $appeal->approvals()->where('decision', 'rejected')->first()->remark);

        $html = $this->actingAs($this->student)->get(route('candidacy.appeals.show', $appeal))->getContent();
        $this->assertStringContainsString('Reasons for rejection', $html);
        $this->assertStringContainsString('Completion plan is not realistic within the requested period; Supporting documents are insufficient or invalid', $html);
        $this->assertStringContainsString('No further appeals are accepted', $html);

        foreach ([[$this->student, 'rejected_student'], [$this->lecturer, 'rejected'], [$this->chairUser, 'rejected'], [$this->cgsUser, 'rejected']] as [$user, $template]) {
            Notification::assertSentTo($user, CandidacyAppealNotice::class, function (CandidacyAppealNotice $n) use ($user, $template) {
                $mail = implode(' ', $n->toMail($user)->introLines);

                return $n->template === $template
                    && str_contains($mail, 'Completion plan is not realistic within the requested period')
                    && str_contains($mail, 'See the plan.')
                    && str_contains($n->toArray($user)['title'], 'Supporting documents are insufficient or invalid')
                    && str_contains($n->toArray($user)['reasons'], 'Completion plan')
                    && in_array('database', $n->via($user), true);
            });
        }
    }

    public function test_old_rejections_without_reasons_or_with_old_keys_show_without_error(): void
    {
        $appeal = $this->toStage('dean');
        $appeal = $this->service->decide($this->deanUser, $appeal, 'reject', ['plan_not_realistic']);
        CandidacyAppealEvent::where('candidacy_appeal_id', $appeal->id)->where('action', 'rejected')->get(); // history is never edited: build the old rows directly instead

        $bare = $this->student('22005555', 'bare@test.my');
        $old = CandidacyAppeal::create(['student_id' => $bare->id, 'appeal_no' => 'SCA-22005555-01', 'sequence_no' => 1, 'status' => 'rejected', 'final_outcome' => 'rejected_final', 'round' => 1, 'requested_months' => 3,
            'supervisor_profile_id' => $appeal->supervisor_profile_id, 'chair_profile_id' => $appeal->chair_profile_id, 'dean_profile_id' => $appeal->dean_profile_id, 'submitted_at' => now()]);
        CandidacyAppealEvent::record(['candidacy_appeal_id' => $old->id, 'actor_kind' => 'dean', 'actor_name' => 'Old Dean', 'action' => 'rejected', 'stage' => 'dean', 'round' => 1, 'to_status' => 'rejected']);
        $legacy = CandidacyAppeal::create(['student_id' => $this->student('22005556', 'leg@test.my')->id, 'appeal_no' => 'SCA-22005556-01', 'sequence_no' => 1, 'status' => 'rejected', 'final_outcome' => 'rejected_final', 'round' => 1, 'requested_months' => 3,
            'supervisor_profile_id' => $appeal->supervisor_profile_id, 'chair_profile_id' => $appeal->chair_profile_id, 'dean_profile_id' => $appeal->dean_profile_id, 'submitted_at' => now()]);
        CandidacyAppealEvent::record(['candidacy_appeal_id' => $legacy->id, 'actor_kind' => 'dean', 'actor_name' => 'Old Dean', 'action' => 'rejected', 'stage' => 'dean', 'round' => 1, 'reason_codes' => ['policy_not_met', 'unknown_key'], 'to_status' => 'rejected']);

        $this->actingAs($this->cgsUser)->get(route('candidacy.appeals.show', $old))->assertOk()->assertSee('Not recorded');
        $this->actingAs($this->cgsUser)->get(route('candidacy.appeals.show', $legacy))->assertOk()->assertSee('Policy requirement not met')->assertSee('unknown_key');
    }

    // ---- the expiry day is still a valid day -------------------------------------------------------------------

    protected function atKualaLumpur(string $dateTime): void
    {
        Carbon::setTestNow(Carbon::parse($dateTime, 'Asia/Kuala_Lumpur'));
    }

    public function test_eligibility_boundary_day_before_expiry_day_and_day_after(): void
    {
        $this->candidacy->update(['candidacy_expiry_date' => '2027-01-31']);

        foreach (['2027-01-30 12:00:00' => true, '2027-01-31 00:00:00' => true, '2027-01-31 23:59:00' => true, '2027-02-01 00:00:00' => false, '2027-02-01 09:00:00' => false] as $now => $may) {
            $this->atKualaLumpur($now);
            $problems = $this->service->eligibilityProblems($this->candidacy->fresh());
            $this->assertSame($may, $problems === [], "{$now} KL: ".implode(' ', $problems));
        }
    }

    public function test_the_boundary_is_the_kuala_lumpur_date_not_the_utc_date(): void
    {
        $this->candidacy->update(['candidacy_expiry_date' => '2027-01-31']);

        Carbon::setTestNow(Carbon::parse('2027-01-31 15:59:00', 'UTC')); // 23:59 on 31 Jan in Kuala Lumpur
        $this->assertSame([], $this->service->eligibilityProblems($this->candidacy->fresh()));

        Carbon::setTestNow(Carbon::parse('2027-01-31 16:00:00', 'UTC')); // 00:00 on 1 Feb in Kuala Lumpur
        $this->assertNotSame([], $this->service->eligibilityProblems($this->candidacy->fresh()));

        Carbon::setTestNow(Carbon::parse('2027-01-30 16:00:00', 'UTC')); // 00:00 on 31 Jan in Kuala Lumpur
        $this->assertSame([], $this->service->eligibilityProblems($this->candidacy->fresh()));
    }

    public function test_a_submit_on_the_expiry_day_works_and_the_day_after_is_refused(): void
    {
        $this->candidacy->update(['candidacy_expiry_date' => '2027-01-31']);
        $draft = $this->draft();

        $this->atKualaLumpur('2027-02-01 00:00:00');
        $this->expectException(ValidationException::class);
        $this->service->submit($this->student, $draft);
    }

    public function test_submitting_on_the_expiry_day_itself_is_accepted(): void
    {
        $this->candidacy->update(['candidacy_expiry_date' => '2027-01-31']);
        $draft = $this->draft();

        $this->atKualaLumpur('2027-01-31 23:59:00');
        $appeal = $this->service->submit($this->student, $draft);

        $this->assertSame('with_supervisor', $appeal->status);
    }

    public function test_a_returned_appeal_can_be_resubmitted_on_the_expiry_day_but_not_after(): void
    {
        $this->candidacy->update(['candidacy_expiry_date' => '2027-01-31']);
        $appeal = $this->service->decide($this->lecturer, $this->submitted(), 'return', ['documents_missing']);

        $this->atKualaLumpur('2027-02-01 00:00:00');
        try {
            $this->service->resubmit($this->student, $appeal->fresh());
            $this->fail('resubmitted the day after the expiry');
        } catch (ValidationException) {
            $this->assertSame('returned', $appeal->fresh()->status);
        }

        $this->atKualaLumpur('2027-01-31 23:59:00');
        $this->assertSame('with_supervisor', $this->service->resubmit($this->student, $appeal->fresh())->status);
    }

    public function test_the_close_job_closes_only_when_today_is_after_the_expiry_date(): void
    {
        $this->candidacy->update(['candidacy_expiry_date' => '2027-01-31']);
        $appeal = $this->service->decide($this->lecturer, $this->submitted(), 'return', ['documents_missing']);

        foreach (['2027-01-30 10:00:00', '2027-01-31 00:00:00', '2027-01-31 23:59:00'] as $now) {
            $this->atKualaLumpur($now);
            $this->assertSame(0, $this->service->closeExpired()->count(), $now);
            $this->assertSame('returned', $appeal->fresh()->status);
        }

        $this->atKualaLumpur('2027-02-01 00:00:00');
        $this->assertSame(1, $this->service->closeExpired(dryRun: true)->count());
        $this->assertSame(1, $this->service->closeExpired()->count());
        $this->assertSame('closed_expired', $appeal->fresh()->status);
    }
}
