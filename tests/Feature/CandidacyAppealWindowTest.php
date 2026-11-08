<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Services\CandidacyAppealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The appeal window (CGS, 2026-11-08): an appeal may be submitted from 3 calendar months before the expiry date until the end of the expiry
 * date (Asia/Kuala_Lumpur dates). Resubmitting a returned appeal, appeals already submitted and every approver action are not affected.
 */
class CandidacyAppealWindowTest extends TestCase
{
    use BuildsCandidacyWorld {
        setUp as protected worldSetUp;
    }
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->worldSetUp();
        config(['candidacy.appeal_window_months' => 3]); // the world opens it wide for the other tests; here the real rule applies
    }

    protected function expiry(string $date): void
    {
        $this->candidacy->update(['candidacy_expiry_date' => $date, 'original_expiry_date' => $date]);
    }

    protected function at(string $kualaLumpurTime): void
    {
        Carbon::setTestNow(Carbon::parse($kualaLumpurTime, 'Asia/Kuala_Lumpur'));
    }

    protected function problems(): array
    {
        return $this->service->eligibilityProblems($this->candidacy->fresh());
    }

    public function test_the_default_is_three_months(): void
    {
        $this->assertSame(3, (int) require_once_config());
    }

    public function test_the_day_before_the_window_is_refused_with_the_date(): void
    {
        $this->expiry('2027-06-15'); // opens 15 Mar 2027
        $this->at('2027-03-14 23:59:00');

        $this->assertSame(['You can submit an appeal from 15 Mar 2027, 3 months before your expiry date.'], $this->problems());
    }

    public function test_the_first_day_of_the_window_is_allowed(): void
    {
        $this->expiry('2027-06-15');
        $this->at('2027-03-15 00:00:00');

        $this->assertSame([], $this->problems());
    }

    public function test_the_expiry_day_at_23_59_is_allowed_and_the_day_after_is_refused(): void
    {
        $this->expiry('2027-06-15');

        $this->at('2027-06-15 23:59:00');
        $this->assertSame([], $this->problems());

        $this->at('2027-06-16 00:00:00');
        $this->assertSame(['Your study candidacy has already expired.'], $this->problems());
    }

    public function test_month_end_expiries_use_the_same_arithmetic_as_the_reminders(): void
    {
        foreach (['2027-05-31' => '2027-02-28', '2027-03-31' => '2026-12-31', '2027-04-30' => '2027-01-30', '2028-05-31' => '2028-02-29'] as $expiry => $opens) {
            $this->assertSame($opens, CandidacyAppealService::windowOpens(Carbon::parse($expiry))->toDateString(), "expiry {$expiry}");
            $this->assertSame(
                $opens,
                \App\Modules\Chloe\Support\CandidacyReminderEngine::dates(\Carbon\CarbonImmutable::parse($expiry))[3]->toDateString(),
                "the 3-month reminder of {$expiry} falls on the same day"
            );
        }

        $this->expiry('2027-05-31');
        $this->at('2027-02-27 12:00:00');
        $this->assertSame(['You can submit an appeal from 28 Feb 2027, 3 months before your expiry date.'], $this->problems());
        $this->at('2027-02-28 12:00:00');
        $this->assertSame([], $this->problems());
    }

    public function test_the_window_length_comes_from_the_config(): void
    {
        config(['candidacy.appeal_window_months' => 2]);
        $this->expiry('2027-06-15');
        $this->at('2027-04-14 12:00:00');
        $this->assertSame(['You can submit an appeal from 15 Apr 2027, 2 months before your expiry date.'], $this->problems());
    }

    public function test_a_draft_cannot_be_submitted_before_the_window(): void
    {
        $this->expiry('2027-06-15');
        $this->at('2027-03-15 09:00:00');
        $draft = $this->draft();

        $this->at('2027-03-14 09:00:00');
        $this->expectException(ValidationException::class);
        $this->service->submit($this->student, $draft);
    }

    public function test_a_returned_appeal_may_be_resubmitted_before_the_window_up_to_the_expiry_date(): void
    {
        $this->expiry('2027-06-15');
        $this->at('2027-03-20 09:00:00');
        $appeal = $this->submitted();
        $appeal = $this->service->decide($this->lecturer, $appeal, 'return', ['other'], 'Please fix the form.');
        $this->assertSame(CandidacyAppeal::STATUS_RETURNED, $appeal->status);

        // the expiry date moved later than the window (e.g. fixed by CGS): resubmitting is still allowed, it is not a new submission
        $this->at('2027-03-01 09:00:00');
        $appeal = $this->service->resubmit($this->student, $appeal->fresh(), ['reason' => 'Fixed.']);

        $this->assertSame(2, (int) $appeal->round);
    }

    public function test_an_appeal_already_submitted_is_untouched_when_the_expiry_is_far_off(): void
    {
        $this->expiry('2027-06-15');
        $this->at('2027-03-20 09:00:00');
        $appeal = $this->submitted();

        $this->at('2027-03-01 09:00:00'); // before the window as seen from here
        $appeal = $this->service->decide($this->lecturer, $appeal->fresh(), 'approve');

        $this->assertSame('chair', $appeal->current_stage);
    }

    public function test_after_a_dean_approval_the_window_follows_the_new_expiry_date(): void
    {
        $this->expiry('2027-06-15');
        $this->at('2027-03-20 09:00:00');
        $appeal = $this->toStage('dean', 3);
        $this->service->decide($this->deanUser, $appeal, 'approve');

        $this->assertSame('2027-09-15', $this->candidacy->fresh()->candidacy_expiry_date->toDateString());
        $this->at('2027-06-15 12:00:00'); // opens 15 Jun 2027 for a 15 Sep expiry
        $this->assertSame([], $this->problems_ignoring_allowance());
        $this->at('2027-06-14 12:00:00');
        $this->assertStringContainsString('You can submit an appeal from 15 Jun 2027', implode(' ', $this->problems_ignoring_allowance()));
    }

    protected function problems_ignoring_allowance(): array
    {
        return array_values(array_filter($this->problems(), fn ($p) => str_contains($p, 'submit an appeal from') || str_contains($p, 'expired')));
    }

    // ---- the server, not just the button ------------------------------------------------------------------------

    public function test_the_new_appeal_page_and_a_direct_draft_request_are_refused_before_the_window(): void
    {
        $this->expiry('2027-06-15');
        $this->at('2027-03-14 10:00:00');
        $message = 'You can submit an appeal from 15 Mar 2027, 3 months before your expiry date.';

        $this->actingAs($this->student)->get(route('candidacy.appeals.new'))->assertRedirect(route('candidacy.status'))->assertSessionHasErrors(['appeal' => $message]);
        $this->actingAs($this->student)->get(route('candidacy.appeals.draft', ['step' => 1]))->assertRedirect(route('candidacy.status'))->assertSessionHasErrors(['appeal' => $message]);
        $this->actingAs($this->student)->post(route('candidacy.appeals.draft.save', ['step' => 1]), ['intent' => 'save'])->assertRedirect(route('candidacy.status'))->assertSessionHasErrors(['appeal' => $message]);

        $this->assertSame(0, CandidacyAppeal::where('student_id', $this->student->id)->count(), 'no draft was created');
    }

    public function test_the_status_page_disables_new_appeal_and_says_when_it_opens(): void
    {
        $this->expiry('2027-06-15');
        $this->at('2027-03-14 10:00:00');

        $this->actingAs($this->student)->get(route('candidacy.status'))->assertOk()
            ->assertSee('You can submit an appeal from 15 Mar 2027, 3 months before your expiry date.');
    }

    public function test_inside_the_window_a_draft_can_be_started(): void
    {
        $this->expiry('2027-06-15');
        $this->at('2027-03-15 10:00:00');

        $this->actingAs($this->student)->get(route('candidacy.appeals.new'))->assertRedirect(route('candidacy.appeals.draft', ['step' => 1]));
        $this->actingAs($this->student)->get(route('candidacy.appeals.draft', ['step' => 1]))->assertOk();
        $this->assertSame(1, CandidacyAppeal::where('student_id', $this->student->id)->count());
    }
}

function require_once_config(): int
{
    return (int) (include base_path('config/candidacy.php'))['appeal_window_months'];
}
