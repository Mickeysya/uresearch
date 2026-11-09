<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Notifications\CandidacyAppealNotice;
use App\Modules\Chloe\Support\CandidacyReminderEngine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Round 4 (F): the Dean may approve fewer or more months than requested (never more than the student's REMAINING allowance),
 * with a reason when the number differs. requested_months is never overwritten; the reason is for approvers and CGS only.
 * The fixture's candidacy expires 2027-01-31 (end of month, so a calendar-month overflow would show).
 */
class CandidacyDeanAdjustmentTest extends TestCase
{
    use BuildsCandidacyWorld;
    use MakesUsers;
    use RefreshDatabase;

    protected function approve(CandidacyAppeal $appeal, ?int $months = null, ?string $reason = null): CandidacyAppeal
    {
        return $this->service->decide($this->deanUser, $appeal, 'approve', approvedMonths: $months, adjustReason: $reason);
    }

    public function test_approving_as_requested_needs_no_reason_and_stores_none(): void
    {
        $appeal = $this->approve($this->toStage('dean', 6));

        $this->assertSame(6, $appeal->requested_months);
        $this->assertSame(6, $appeal->approved_months);
        $this->assertFalse($appeal->wasAdjustedByDean());
        $this->assertNull($appeal->dean_adjust_reason);
        $this->assertNull($appeal->dean_adjusted_at);
        $this->assertSame('2027-07-31', $appeal->new_expiry_date->toDateString());
        $this->assertSame(6, $this->candidacy->fresh()->extension_months_used);
    }

    public function test_approving_fewer_months_keeps_the_request_and_records_who_when_and_why(): void
    {
        $appeal = $this->approve($this->toStage('dean', 6), 4, 'Timeline supports four months.');

        $this->assertSame(6, $appeal->requested_months, 'the request is never overwritten');
        $this->assertSame(4, $appeal->approved_months);
        $this->assertTrue($appeal->wasAdjustedByDean());
        $this->assertSame('Timeline supports four months.', $appeal->dean_adjust_reason);
        $this->assertSame($this->deanUser->id, $appeal->dean_adjusted_by_id);
        $this->assertNotNull($appeal->dean_adjusted_at);
        $this->assertSame('2027-05-31', $appeal->new_expiry_date->toDateString(), '31 Jan + 4 months');
        $this->assertSame('2027-05-31', $this->candidacy->fresh()->candidacy_expiry_date->toDateString());
        $this->assertSame(4, $this->candidacy->fresh()->extension_months_used);
    }

    public function test_approving_more_months_than_requested_within_the_remaining_allowance(): void
    {
        $appeal = $this->approve($this->toStage('dean', 3), 8, 'The plan needs more time.');

        $this->assertSame(3, $appeal->requested_months);
        $this->assertSame(8, $appeal->approved_months);
        $this->assertSame('2027-09-30', $appeal->new_expiry_date->toDateString(), '31 Jan + 8 months clamps to 30 Sep');
        $this->assertSame(8, $this->candidacy->fresh()->extension_months_used);
    }

    public function test_a_different_number_needs_a_real_reason_and_nothing_is_written_without_it(): void
    {
        foreach ([[4, null], [4, ''], [4, '  ab  '], [9, 'x']] as [$months, $reason]) {
            $appeal = $this->toStage('dean', 6);

            try {
                $this->approve($appeal, $months, $reason);
                $this->fail('A different number of months must need a reason.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('adjust_reason', $e->errors());
            }

            $fresh = $appeal->fresh();
            $this->assertSame('with_dean', $fresh->status);
            $this->assertNull($fresh->approved_months);
            $this->assertSame(0, $this->candidacy->fresh()->extension_months_used);

            // reset for the next round of the loop
            $fresh->delete();
        }
    }

    public function test_more_than_the_remaining_allowance_is_refused(): void
    {
        $this->candidacy->update(['extension_months_used' => 6]);
        $appeal = $this->toStage('dean', 3);

        foreach ([7, 12, 0, -1] as $months) {
            try {
                $this->approve($appeal, $months, 'Reason given here.');
                $this->fail("{$months} months must be refused");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('approved_months', $e->errors());
            }
        }

        $this->assertSame(0, (int) $appeal->fresh()->approved_months);
        $this->assertSame(6, $this->candidacy->fresh()->extension_months_used);

        // exactly the remaining 6 is fine
        $done = $this->approve($appeal, 6, 'Final extension.');
        $this->assertSame(12, $this->candidacy->fresh()->extension_months_used);
        $this->assertSame(6, $done->approved_months);
    }

    public function test_second_and_third_appeals_follow_the_remaining_allowance(): void
    {
        $first = $this->approve($this->toStage('dean', 5), 5);
        $this->assertSame(5, $this->candidacy->fresh()->extension_months_used);
        $this->assertSame(7, $this->candidacy->fresh()->remainingAppealMonths());

        $second = $this->approve($this->toStage('dean', 4), 3, 'Three is enough.');
        $this->assertSame(8, $this->candidacy->fresh()->extension_months_used);
        $this->assertSame(4, $this->candidacy->fresh()->remainingAppealMonths());

        $third = $this->toStage('dean', 2);
        $this->expectException(ValidationException::class);
        try {
            $this->approve($third, 5, 'Too many.'); // only 4 remain
        } finally {
            $this->assertSame(8, $this->candidacy->fresh()->extension_months_used);
        }
    }

    public function test_the_reminder_cycle_restarts_from_the_new_expiry_date(): void
    {
        $this->approve($this->toStage('dean', 6), 4, 'Four months only.'); // new expiry 2027-05-31

        $rows = (new CandidacyReminderEngine)->run(CarbonImmutable::parse('2027-02-28', 'Asia/Kuala_Lumpur'), true); // 3 months before the NEW date
        $mine = collect($rows)->first(fn ($r) => ($r['student']['id'] ?? null) === $this->student->matric_no);

        $this->assertNotNull($mine);
        $this->assertSame('send', $mine['action']);
        $this->assertSame(3, $mine['milestone']);
        $this->assertSame('2027-05-31', $mine['student']['expiry']);
    }

    public function test_the_student_never_sees_the_reason_but_approvers_and_cgs_do(): void
    {
        $appeal = $this->approve($this->toStage('dean', 6), 4, 'Secret dean reasoning about the timeline.');
        $reason = 'Secret dean reasoning about the timeline.';

        $this->actingAs($this->student)->get(route('candidacy.appeals.show', $appeal))->assertOk()->assertDontSee($reason)->assertDontSee("Dean's reason");
        $this->actingAs($this->lecturer)->get(route('candidacy.appeals.show', $appeal))->assertOk()->assertSee($reason);
        $this->actingAs($this->chairUser)->get(route('candidacy.appeals.show', $appeal))->assertOk()->assertSee($reason);
        $this->actingAs($this->cgsUser)->get(route('candidacy.appeals.show', $appeal))->assertOk()->assertSee($reason);
        $this->actingAs($this->deanUser)->get(route('candidacy.appeals.show', $appeal))->assertOk()->assertSee($reason);

        // the memo template has no access to the reason, only to both numbers
        $memo = file_get_contents(app_path('Modules/Chloe/Resources/views/candidacy/memo/appeal_memo.blade.php'));
        $this->assertStringNotContainsString('dean_adjust_reason', $memo);
        $this->assertStringContainsString('Requested: {{ $appeal->requested_months }} month(s). Approved: {{ $appeal->approved_months }} month(s).', $memo);
    }

    public function test_the_student_e_mail_names_both_numbers_only_when_they_differ_and_never_the_reason(): void
    {
        $this->approve($this->toStage('dean', 6), 4, 'Reason that must not be mailed.');

        $sent = Notification::sent($this->student, CandidacyAppealNotice::class)->filter(fn ($n) => $n->template === 'final_student');
        $this->assertCount(1, $sent);
        $text = implode("\n", $sent->first()->toMail($this->student)->introLines);
        $this->assertStringContainsString('Requested: **6 month(s)**. Approved: **4 month(s)**.', $text);
        $this->assertStringNotContainsString('Reason that must not be mailed', $text);
        $this->assertStringNotContainsString('Approved extension:', $text);

        // equal: the current wording stays
        Notification::fake();
        $this->candidacy->update(['extension_months_used' => 0, 'candidacy_expiry_date' => '2027-01-31']);
        $this->approve($this->toStage('dean', 5), 5);
        $equal = Notification::sent($this->student, CandidacyAppealNotice::class)->filter(fn ($n) => $n->template === 'final_student')->first();
        $this->assertStringContainsString('Approved extension: **5 month(s)**', implode("\n", $equal->toMail($this->student)->introLines));
    }

    public function test_the_deans_dropdown_lists_one_to_the_remaining_allowance_and_preselects_the_request(): void
    {
        $this->candidacy->update(['extension_months_used' => 6]);
        $appeal = $this->toStage('dean', 3);

        $html = $this->actingAs($this->deanUser)->get(route('candidacy.appeals.show', $appeal))->assertOk()->getContent();
        preg_match('~<select id="wca-approved-months".*?</select>~s', $html, $m);

        preg_match_all('~<option value="(\d+)"( selected)?~', $m[0], $o, PREG_SET_ORDER);
        $this->assertSame(['1', '2', '3', '4', '5', '6'], array_column($o, 1), 'nothing above the 6 months that remain');
        $this->assertSame(['3'], array_column(array_filter($o, fn ($x) => ! empty($x[2])), 1), 'the requested months are preselected');
        $this->assertStringContainsString('id="wca-adjust-reason"', $html);
    }

    public function test_the_students_months_dropdown_lists_only_one_to_the_remaining_allowance_and_the_server_agrees(): void
    {
        $this->candidacy->update(['extension_months_used' => 6]);
        $this->actingAs($this->student)->get(route('candidacy.appeals.draft', 2));

        $html = $this->actingAs($this->student)->get(route('candidacy.appeals.draft', 2))->assertOk()->getContent();
        preg_match('~<select id="wca-months".*?</select>~s', $html, $m);
        preg_match_all('~<option value="(\d*)"~', $m[0], $o);
        $this->assertSame(['', '1', '2', '3', '4', '5', '6'], $o[1]);

        $this->actingAs($this->student)->post(route('candidacy.appeals.draft.save', 2), ['intent' => 'next', 'requested_months' => 7])
            ->assertSessionHasErrors('requested_months');
        $this->actingAs($this->student)->post(route('candidacy.appeals.draft.save', 2), ['intent' => 'next', 'requested_months' => 6])
            ->assertSessionDoesntHaveErrors('requested_months');
    }

    public function test_a_student_with_the_full_twelve_months_used_cannot_appeal(): void
    {
        $this->candidacy->update(['extension_months_used' => 12]);

        $this->actingAs($this->student)->get(route('candidacy.appeals.new'))->assertOk()->assertSee('You cannot submit an appeal now.');
        $this->assertContains('You have used the maximum of 12 months of extension.', $this->service->eligibilityProblems($this->candidacy->fresh()));
    }

    public function test_existing_data_is_untouched_by_the_new_columns(): void
    {
        $appeal = $this->toStage('dean', 6);

        $this->assertNull($appeal->fresh()->dean_adjust_reason);
        $this->assertNull($appeal->fresh()->dean_adjusted_by_id);
        $this->assertNull($appeal->fresh()->approved_months, 'nothing is approved until the Dean decides');
        $this->assertInstanceOf(StudyCandidacy::class, $this->candidacy->fresh());
    }
}
