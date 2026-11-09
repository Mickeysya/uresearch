<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyAppealDocument;
use App\Modules\Chloe\Models\CandidacyAppealPublication;
use App\Modules\Chloe\Models\StudyCandidacy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The sidebar item "Study Candidacy Appeal" (My Application) opens the appeal FORM: editable when the student may appeal,
 * the same page read-only with the real reasons when not, and opening it never writes a row.
 */
class CandidacyAppealFormPageTest extends TestCase
{
    use BuildsCandidacyWorld {
        setUp as protected buildWorld;
    }
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->buildWorld();
        // the real rule: the window opens 3 months before the expiry date (the shared world widens it)
        config(['candidacy.appeal_window_months' => 3]);
        $this->candidacy->update(['candidacy_expiry_date' => '2026-12-15', 'original_expiry_date' => '2026-12-15']);
    }

    protected function rows(): array
    {
        return [CandidacyAppeal::count(), CandidacyAppealDocument::count(), CandidacyAppealPublication::count()];
    }

    /** The form area of the page: from the wca-page div to the end of the read-only fieldset (or the page). */
    protected function formArea(string $html): string
    {
        $start = strpos($html, 'class="wca-page"');
        $end = strpos($html, '</fieldset>', $start) ?: strpos($html, '</form>', $start);

        return substr($html, $start, ($end ?: strlen($html)) - $start);
    }

    protected function xlsx(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($path, 'PK');

        return new UploadedFile($path, 'form.xlsx', null, null, true);
    }

    // ---- can appeal: the editable form, and nothing written by opening it ---------------------------------

    public function test_a_student_who_can_appeal_gets_the_editable_form_and_opening_it_writes_nothing(): void
    {
        $before = $this->rows();

        $this->actingAs($this->student)->get(route('candidacy.appeals.new'))->assertRedirect(route('candidacy.appeals.draft', ['step' => 1]));
        $html = $this->actingAs($this->student)->get(route('candidacy.appeals.draft', ['step' => 1]))->assertOk()->getContent();

        $this->assertStringContainsString('action="'.route('candidacy.appeals.draft.save', ['step' => 1]).'"', $html);
        $this->assertStringContainsString('name="intent" value="save"', $html);
        $this->assertStringNotContainsString('wca-readonly', $html);
        $this->assertStringNotContainsString('You cannot submit an appeal now.', $html);
        $this->assertSame($before, $this->rows(), 'opening the form must not create a draft');

        $step2 = $this->actingAs($this->student)->get(route('candidacy.appeals.draft', ['step' => 2]))->assertOk()->getContent();
        $this->assertStringContainsString('name="reason"', $step2);
        $this->assertStringNotContainsString('disabled', $this->formArea($step2));
        $this->assertSame($before, $this->rows());

        // steps 3 and 4 need a saved draft: without one they go back to step 1, still writing nothing
        $this->actingAs($this->student)->get(route('candidacy.appeals.draft', ['step' => 3]))->assertRedirect(route('candidacy.appeals.draft', ['step' => 1]));
        $this->actingAs($this->student)->get(route('candidacy.appeals.draft', ['step' => 4]))->assertRedirect(route('candidacy.appeals.draft', ['step' => 1]));
        $this->assertSame($before, $this->rows());

        // the first Save draft is what creates it
        $this->actingAs($this->student)->post(route('candidacy.appeals.draft.save', ['step' => 1]), ['intent' => 'save'])->assertRedirect();
        $this->assertSame(1, CandidacyAppeal::where('student_id', $this->student->id)->where('status', 'draft')->count());
    }

    // ---- cannot appeal: the same page, read-only, with the real reason ----------------------------------------

    public static function blockedCases(): array
    {
        return [
            'window not open yet' => ['notOpen', 'You can submit an appeal from 15 Sep 2027, 3 months before your expiry date.'],
            'already expired' => ['expired', 'Your study candidacy has already expired.'],
            'not active' => ['inactive', 'Your study candidacy is not active.'],
            'maximum months used' => ['maxMonths', 'You have used the maximum of 12 months of extension.'],
            'rejected by the Dean' => ['rejected', 'An earlier appeal was rejected by the Dean. That decision is final.'],
        ];
    }

    protected function block(string $case): void
    {
        match ($case) {
            'notOpen' => $this->candidacy->update(['candidacy_expiry_date' => '2027-12-15']),
            'expired' => $this->candidacy->update(['candidacy_expiry_date' => '2026-10-20']),
            'inactive' => $this->candidacy->update(['status' => 'inactive']),
            'maxMonths' => $this->candidacy->update(['extension_months_used' => 12]),
            'rejected' => CandidacyAppeal::create([
                'student_id' => $this->student->id, 'study_candidacy_id' => $this->candidacy->id, 'status' => CandidacyAppeal::STATUS_REJECTED,
                'final_outcome' => CandidacyAppeal::OUTCOME_REJECTED_FINAL, 'round' => 1, 'appeal_no' => 'SCA-TEST-01', 'requested_months' => 3,
            ]),
        };
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockedCases')]
    public function test_a_student_who_cannot_appeal_sees_the_read_only_form_with_the_reason(string $case, string $reason): void
    {
        $this->block($case);
        $before = $this->rows();

        $html = $this->actingAs($this->student)->get(route('candidacy.appeals.new'))->assertOk()->getContent();
        $area = $this->formArea($html);

        $this->assertStringContainsString('You cannot submit an appeal now.', $html);
        $this->assertStringContainsString($reason, $html);
        $this->assertStringContainsString('Back to My Study Candidacy', $html);
        $this->assertStringContainsString('<fieldset class="wca-readonly" disabled>', $html);
        // no form, no action: nothing on this page can post anything
        $this->assertStringNotContainsString('<form', $area);
        // the stepper does not link to any step
        $this->assertSame(0, preg_match('/<ol class="wca-stepper[^"]*"[^>]*>.*?<a /s', $html));
        // it is the form, not a copy of My Study Candidacy
        foreach (['Extension allowance', 'What happens next', 'My Appeals', 'Original expiry date'] as $status) {
            $this->assertStringNotContainsString($status, $html);
        }
        $this->assertStringContainsString('name="reason"', $area);
        $this->assertStringContainsString('name="requested_months"', $area);
        $this->assertSame('', trim(strip_tags(preg_match('/<textarea[^>]*>(.*?)<\/textarea>/s', $area, $m) ? $m[1] : '')), 'no value looks saved');
        $this->assertSame($before, $this->rows(), 'opening the read-only form must not write anything');
    }

    public function test_the_read_only_form_has_every_button_disabled_and_no_checked_or_filled_value(): void
    {
        $this->block('notOpen');
        $html = $this->actingAs($this->student)->get(route('candidacy.appeals.new'))->assertOk()->getContent();
        $area = $this->formArea($html);

        // every button inside the fieldset is a disabled type=button (Save draft, Next, Submit, Add a publication, ...)
        preg_match_all('/<button\b[^>]*>/', $area, $buttons);
        $this->assertNotEmpty($buttons[0]);
        foreach ($buttons[0] as $button) {
            $this->assertStringContainsString('type="button"', $button);
        }
        $this->assertStringNotContainsString(' checked', $area);
        $this->assertStringNotContainsString(' selected', preg_replace('/<option value="" selected>/', '', $area));
        preg_match_all('/<input\b[^>]*name="(reason|percent_complete|rcs_date|rcs_category)"[^>]*>/', $area, $inputs);
        foreach ($inputs[0] as $input) {
            $this->assertDoesNotMatchRegularExpression('/value="[^"]+"/', $input);
        }
    }

    public function test_an_appeal_in_progress_shows_the_read_only_form_naming_the_approver(): void
    {
        $appeal = $this->submitted();
        $before = $this->rows();

        $html = $this->actingAs($this->student)->get(route('candidacy.appeals.new'))->assertOk()->getContent();

        $this->assertStringContainsString("Your appeal {$appeal->appeal_no} is with Supervisor / Programme Manager ({$this->lecturer->name}).", $html);
        $this->assertStringContainsString('Back to My Study Candidacy', $html);
        $this->assertStringContainsString('<fieldset class="wca-readonly" disabled>', $html);
        $this->assertStringNotContainsString('<form', $this->formArea($html));
        $this->assertSame($before, $this->rows());

        // the draft URL does not create one either
        $this->actingAs($this->student)->get(route('candidacy.appeals.draft', ['step' => 1]))->assertRedirect(route('candidacy.appeals.new'));
        $this->assertSame($before, $this->rows());
    }

    // ---- the server refuses every write, by any route ----------------------------------------------------------

    public function test_a_blocked_student_cannot_create_a_draft_save_submit_or_upload_by_any_route(): void
    {
        $this->block('notOpen');
        $before = $this->rows();

        // start and draft: nothing created
        $this->actingAs($this->student)->get(route('candidacy.appeals.new'))->assertOk();
        $this->actingAs($this->student)->get(route('candidacy.appeals.draft', ['step' => 1]))->assertRedirect(route('candidacy.appeals.new'));
        $this->actingAs($this->student)->get(route('candidacy.appeals.draft', ['step' => 3]))->assertRedirect(route('candidacy.appeals.new'));

        // saveStep, every step, every intent: refused with the reason, nothing created
        foreach ([1, 2, 3, 4] as $step) {
            foreach (['save', 'next', 'back'] as $intent) {
                $this->actingAs($this->student)
                    ->post(route('candidacy.appeals.draft.save', ['step' => $step]), ['intent' => $intent, 'reason' => 'x', 'requested_months' => 3])
                    ->assertRedirect(route('candidacy.status'))
                    ->assertSessionHasErrors('appeal');
            }
        }

        // submit: there is no draft to submit
        $this->actingAs($this->student)->post(route('candidacy.appeals.submit'), ['confirm' => 1])->assertNotFound();

        // upload to an appeal that does not exist, and to somebody else's draft
        $this->actingAs($this->student)->post(route('candidacy.appeals.documents.store', 999999), ['kind' => 'official_form', 'file' => $this->xlsx()])->assertNotFound();
        $this->assertSame($before, $this->rows());

        $other = $this->user(\App\Modules\Core\Support\Role::STUDENT, ['email' => 'other.student@test.my', 'name' => 'Other Student', 'student_id' => '29999999']);
        $theirs = CandidacyAppeal::create(['student_id' => $other->id, 'study_candidacy_id' => $this->candidacy->id, 'status' => 'draft', 'round' => 1]);
        $rowsWithTheirs = $this->rows();
        $this->actingAs($this->student)->post(route('candidacy.appeals.documents.store', $theirs), ['kind' => 'official_form', 'file' => $this->xlsx()])->assertForbidden();
        $this->assertSame($rowsWithTheirs, $this->rows(), 'nothing written by the refused upload');
    }

    public function test_a_student_whose_window_closed_by_expiry_cannot_start_or_save(): void
    {
        $this->block('expired');
        $before = $this->rows();

        $this->actingAs($this->student)->post(route('candidacy.appeals.draft.save', ['step' => 1]), ['intent' => 'next'])->assertSessionHasErrors('appeal');
        $this->assertSame($before, $this->rows());
    }

    // ---- the cases that stay as they were -------------------------------------------------------------------------

    public function test_a_returned_appeal_opens_the_editable_resubmit_form(): void
    {
        $appeal = $this->submitted();
        $this->service->decide($this->lecturer, $appeal, 'return', ['documents_missing'], 'Add the transcript.');

        $this->actingAs($this->student)->get(route('candidacy.appeals.new'))->assertRedirect(route('candidacy.appeals.edit', $appeal));
        $html = $this->actingAs($this->student)->get(route('candidacy.appeals.edit', $appeal))->assertOk()->getContent();
        $this->assertStringContainsString('action="'.route('candidacy.appeals.resubmit', $appeal).'"', $html);
        $this->assertStringNotContainsString('wca-readonly', $html);
    }

    public function test_an_unfinished_draft_continues_even_when_the_window_is_not_open(): void
    {
        $draft = $this->draft();
        $this->block('notOpen');

        $this->actingAs($this->student)->get(route('candidacy.appeals.new'))->assertRedirect(route('candidacy.appeals.draft', ['step' => 1]));
        $html = $this->actingAs($this->student)->get(route('candidacy.appeals.draft', ['step' => 2]))->assertOk()->getContent();
        $this->assertStringContainsString('More time to finish the experiments.', $html);
        $this->assertStringNotContainsString('wca-readonly', $html);
        $this->assertSame(1, CandidacyAppeal::where('id', $draft->id)->count());
    }

    // ---- sidebar: one highlighted item at a time ------------------------------------------------------------

    protected function active(string $html, string $title): bool
    {
        // the whole <a ...> tag whose title is exactly $title, in whatever attribute order
        preg_match('/<a\b[^>]*\btitle="'.preg_quote($title, '/').'"[^>]*>/', $html, $m);
        $this->assertNotEmpty($m, "no sidebar link titled {$title}");

        return (bool) preg_match('/class="[^"]*\bactive\b/', $m[0]);
    }

    public function test_the_sidebar_highlights_the_appeal_item_on_form_pages_and_my_study_candidacy_elsewhere(): void
    {
        $appeal = $this->draft();
        $this->service->submit($this->student, $appeal);
        $own = $this->actingAs($this->student)->get(route('candidacy.appeals.show', $appeal))->getContent();
        $this->assertTrue($this->active($own, 'My Study Candidacy'));
        $this->assertFalse($this->active($own, 'Study Candidacy Appeal'));

        $status = $this->actingAs($this->student)->get(route('candidacy.status'))->getContent();
        $this->assertTrue($this->active($status, 'My Study Candidacy'));
        $this->assertFalse($this->active($status, 'Study Candidacy Appeal'));

        // read-only form (an appeal is in progress)
        $readOnly = $this->actingAs($this->student)->get(route('candidacy.appeals.new'))->getContent();
        $this->assertTrue($this->active($readOnly, 'Study Candidacy Appeal'));
        $this->assertFalse($this->active($readOnly, 'My Study Candidacy'));
        $this->assertMatchesRegularExpression('/aria-expanded="\s*true\s*"/', substr($readOnly, strpos($readOnly, 'title="My Application"'), 200));
    }

    public function test_the_sidebar_highlights_the_appeal_item_on_the_editable_form(): void
    {
        $html = $this->actingAs($this->student)->get(route('candidacy.appeals.draft', ['step' => 1]))->getContent();
        $this->assertTrue($this->active($html, 'Study Candidacy Appeal'));
        $this->assertFalse($this->active($html, 'My Study Candidacy'));
        $this->assertStringContainsString('href="'.route('candidacy.appeals.new').'"', $html);
    }
}
