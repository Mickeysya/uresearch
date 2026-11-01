<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyAppealDocument;
use App\Modules\Chloe\Models\CandidacyDepartment;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Core\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesUsers;
use Tests\TestCase;
use ZipArchive;

/** Stage 1: My Study Candidacy, the four-step form, documents, the detail page, access and the page rules. */
class CandidacyStudentPagesTest extends TestCase
{
    use BuildsCandidacyWorld;
    use MakesUsers;
    use RefreshDatabase;

    protected function xlsx(string $name = 'form.xlsx', bool $valid = true): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'x').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString($valid ? 'xl/workbook.xml' : 'other.xml', '<workbook/>');
        $zip->close();

        return new UploadedFile($path, $name, null, null, true);
    }

    protected function step2(array $over = []): array
    {
        return $over + [
            'phase' => 'writing', 'percent_complete' => 60, 'rcs_status' => 'done', 'rcs_date' => '2026-06-01', 'rcs_category' => 'Cat A',
            'gsc_extension' => '0', 'vc_extension' => '0', 'publications' => [['title' => 'A paper', 'venue' => 'J', 'status' => 'published', 'year' => 2025]],
            'requested_months' => 6, 'reason' => 'I need more time to finish the experiments and write up.', 'declaration' => '1',
        ];
    }

    protected function fillDraft(): CandidacyAppeal
    {
        $dept = CandidacyDepartment::first();
        $this->actingAs($this->student)->post(route('candidacy.appeals.draft.save', 1), ['intent' => 'next', 'department_id' => $dept->id, 'supervisor_profile_id' => $this->profile($this->lecturer)->id])->assertRedirect();
        $this->actingAs($this->student)->post(route('candidacy.appeals.draft.save', 2), ['intent' => 'next'] + $this->step2())->assertRedirect();
        $draft = CandidacyAppeal::where('student_id', $this->student->id)->where('status', 'draft')->first();
        $this->actingAs($this->student)->post(route('candidacy.appeals.documents.store', $draft), ['kind' => 'official_form', 'file' => $this->xlsx()])->assertSessionHasNoErrors();

        return $draft->fresh();
    }

    // ---- My Study Candidacy ---------------------------------------------------------------------------------

    public function test_the_status_page_shows_the_cards_legend_and_the_my_appeals_columns(): void
    {
        $this->submitted();

        $html = $this->actingAs($this->student)->get(route('candidacy.status'))->assertOk()->getContent();

        foreach (['Candidacy', 'Extension allowance', 'What happens next', 'My Appeals', 'Appeal No.', 'Submitted', 'Requested (months)', 'Approved (months)', 'Current step (who)', 'Last updated', 'View full details'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringContainsString('SCA-22001001-01', $html);
        $this->assertStringContainsString('0 of 12 months used', $html);
        $this->assertStringContainsString('Waiting for a step', $html); // legend
        $this->assertStringContainsString('With Supervisor', $html);
    }

    public function test_an_eligible_student_gets_the_enabled_button_and_a_blocked_student_gets_the_reason(): void
    {
        $this->actingAs($this->student)->get(route('candidacy.status'))->assertSee('Submit an Appeal')->assertSee(route('candidacy.appeals.new'), false);

        $this->candidacy->update(['extension_months_used' => 12]);
        $html = $this->actingAs($this->student)->get(route('candidacy.status'))->getContent();
        $this->assertStringContainsString('maximum of 12 months', $html);
        $this->assertStringContainsString('aria-disabled="true"', $html);
        $this->assertStringNotContainsString(route('candidacy.appeals.new'), $html);

        $this->actingAs($this->student)->get(route('candidacy.appeals.new'))->assertRedirect(route('candidacy.status'));
    }

    public function test_a_draft_shows_a_continue_row(): void
    {
        $this->draft();

        $this->actingAs($this->student)->get(route('candidacy.status'))->assertSee('Draft')->assertSee('Continue');
    }

    public function test_the_old_student_urls_redirect_to_the_new_pages(): void
    {
        $this->actingAs($this->student)->get('/candidacy-appeal/new')->assertRedirect(route('candidacy.appeals.new'));
        $this->actingAs($this->student)->post('/candidacy-appeal')->assertRedirect(route('candidacy.status'));
    }

    // ---- the form -------------------------------------------------------------------------------------------

    public function test_step_one_shows_the_department_read_only_and_needs_a_supervisor_of_it(): void
    {
        $this->actingAs($this->student)->get(route('candidacy.appeals.draft', 1))->assertOk()->assertSee('Department and supervisor')
            ->assertSee('Computer & Information Sciences')->assertSee('Taken from your student record.')->assertDontSee('id="wca-department"', false);

        $this->actingAs($this->student)->post(route('candidacy.appeals.draft.save', 1), ['intent' => 'next'])
            ->assertSessionHasErrors(['supervisor_profile_id']);

        $other = CandidacyDepartment::create(['code' => 'EEE', 'name' => 'Electrical']);
        $this->actingAs($this->student)->post(route('candidacy.appeals.draft.save', 1), ['intent' => 'next', 'department_id' => $other->id, 'supervisor_profile_id' => $this->profile($this->lecturer)->id])
            ->assertSessionHasErrors('department_id');
    }

    public function test_save_draft_is_lenient_and_next_is_strict_on_step_two(): void
    {
        $this->actingAs($this->student)->get(route('candidacy.appeals.draft', 2));

        $this->actingAs($this->student)->post(route('candidacy.appeals.draft.save', 2), ['intent' => 'save', 'reason' => 'short'])->assertSessionHasNoErrors();
        $this->assertSame('short', CandidacyAppeal::where('student_id', $this->student->id)->first()->reason);

        $this->actingAs($this->student)->post(route('candidacy.appeals.draft.save', 2), ['intent' => 'next', 'reason' => 'short'])
            ->assertSessionHasErrors(['phase', 'percent_complete', 'rcs_status', 'requested_months', 'reason', 'declaration']);
    }

    public function test_step_two_rules(): void
    {
        $this->candidacy->update(['extension_months_used' => 9]); // 3 months left
        $this->actingAs($this->student)->get(route('candidacy.appeals.draft', 2));
        $post = fn (array $over) => $this->actingAs($this->student)->post(route('candidacy.appeals.draft.save', 2), ['intent' => 'next'] + $this->step2($over));

        $post(['requested_months' => 4])->assertSessionHasErrors('requested_months');
        $post(['percent_complete' => 101])->assertSessionHasErrors('percent_complete');
        $post(['rcs_status' => 'done', 'rcs_date' => ''])->assertSessionHasErrors('rcs_date');
        $post(['rcs_status' => 'pending', 'rcs_date' => '', 'rcs_expected_date' => ''])->assertSessionHasErrors('rcs_expected_date');
        $post(['declaration' => '0'])->assertSessionHasErrors('declaration');
        $post(['requested_months' => 3])->assertSessionHasNoErrors();
    }

    public function test_there_is_only_one_draft_per_student(): void
    {
        $this->actingAs($this->student)->get(route('candidacy.appeals.draft', 1));
        $this->actingAs($this->student)->get(route('candidacy.appeals.draft', 1));
        $this->actingAs($this->student)->get(route('candidacy.appeals.draft', 3));

        $this->assertSame(1, CandidacyAppeal::where('student_id', $this->student->id)->count());
    }

    // ---- documents ------------------------------------------------------------------------------------------

    public function test_documents_are_checked_by_extension_and_content_and_stored_under_safe_names(): void
    {
        $draft = $this->draft(withForm: false);
        $url = route('candidacy.appeals.documents.store', $draft);
        $as = fn (array $data) => $this->actingAs($this->student)->post($url, $data);

        $as(['kind' => 'official_form', 'file' => UploadedFile::fake()->create('form.pdf', 10, 'application/pdf')])->assertSessionHasErrors('official_form');
        $as(['kind' => 'official_form', 'file' => $this->xlsx('form.xlsx', valid: false)])->assertSessionHasErrors('official_form');
        $as(['kind' => 'official_form', 'file' => UploadedFile::fake()->createWithContent('form.xlsx', 'not a zip')])->assertSessionHasErrors('official_form');
        $as(['kind' => 'supporting', 'file' => UploadedFile::fake()->createWithContent('evil.pdf', 'MZ not a pdf')])->assertSessionHasErrors('supporting');
        $as(['kind' => 'supporting', 'file' => UploadedFile::fake()->create('big.pdf', 11 * 1024, 'application/pdf')])->assertSessionHasErrors('supporting');
        $as(['kind' => 'supporting', 'file' => UploadedFile::fake()->createWithContent('shell.php', '%PDF-1.4')])->assertSessionHasErrors('supporting');

        $as(['kind' => 'official_form', 'file' => $this->xlsx('../../my form.xlsx')])->assertSessionHasNoErrors();
        $doc = CandidacyAppealDocument::first();
        $this->assertMatchesRegularExpression('~^candidacy/appeals/\d+/[A-Za-z0-9]{32}\.xlsx$~', $doc->stored_path);
        Storage::disk('local')->assertExists($doc->stored_path);
        $this->assertStringNotContainsString('..', $doc->original_name);
    }

    public function test_at_most_four_supporting_documents_and_removal_before_submitting(): void
    {
        $draft = $this->draft(withForm: false);

        foreach (range(1, 4) as $i) {
            $this->actingAs($this->student)->post(route('candidacy.appeals.documents.store', $draft), ['kind' => 'supporting', 'file' => UploadedFile::fake()->createWithContent("s{$i}.pdf", '%PDF-1.4 x')])->assertSessionHasNoErrors();
        }
        $this->actingAs($this->student)->post(route('candidacy.appeals.documents.store', $draft), ['kind' => 'supporting', 'file' => UploadedFile::fake()->createWithContent('s5.pdf', '%PDF-1.4 x')])->assertSessionHasErrors('supporting');

        $doc = $draft->documents()->first();
        $this->actingAs($this->student)->delete(route('candidacy.appeals.documents.destroy', [$draft, $doc]))->assertSessionHasNoErrors();
        $this->assertSame(3, $draft->documents()->count());
        Storage::disk('local')->assertMissing($doc->stored_path);
    }

    public function test_step_three_needs_the_official_form_to_continue(): void
    {
        $this->draft(withForm: false);

        $this->actingAs($this->student)->post(route('candidacy.appeals.draft.save', 3), ['intent' => 'next'])->assertSessionHasErrors('official_form');
    }

    // ---- submit and the detail page --------------------------------------------------------------------------

    public function test_review_shows_the_no_withdraw_confirmation_and_submit_creates_the_numbered_appeal(): void
    {
        $this->fillDraft();

        $this->actingAs($this->student)->get(route('candidacy.appeals.draft', 4))->assertSee('you cannot withdraw this appeal');
        $this->actingAs($this->student)->post(route('candidacy.appeals.submit'))->assertSessionHasErrors('confirm');

        $response = $this->actingAs($this->student)->post(route('candidacy.appeals.submit'), ['confirm' => '1']);
        $appeal = CandidacyAppeal::where('appeal_no', 'SCA-22001001-01')->firstOrFail();
        $response->assertRedirect(route('candidacy.appeals.show', $appeal));

        $this->actingAs($this->student)->get(route('candidacy.appeals.show', $appeal))->assertOk()
            ->assertSee('SCA-22001001-01')->assertSee('Decision summary')->assertSee('Section A')->assertSee('Section D')->assertSee('History')
            ->assertSee('Remarks and reasons')->assertDontSee('Withdraw');
    }

    public function test_a_returned_appeal_shows_edit_and_resubmit_with_the_reasons_and_resubmits(): void
    {
        $appeal = $this->submitted();
        $this->service->decide($this->lecturer, $appeal, 'return', ['documents_missing'], 'Add the transcript.');

        $this->actingAs($this->student)->get(route('candidacy.appeals.show', $appeal))->assertSee('Edit and resubmit')->assertSee('Supporting documents missing')->assertSee('Add the transcript.');
        $this->actingAs($this->student)->get(route('candidacy.appeals.edit', $appeal))->assertOk()->assertSee('Why the appeal was returned')->assertSee('More time to finish the experiments.');

        $this->actingAs($this->student)->post(route('candidacy.appeals.resubmit', $appeal), $this->step2())->assertRedirect(route('candidacy.appeals.show', $appeal));
        $this->assertSame('with_supervisor', $appeal->fresh()->status);
        $this->assertSame(2, $appeal->fresh()->round);
    }

    // ---- access ---------------------------------------------------------------------------------------------

    public function test_access_rules_for_pages_documents_and_downloads(): void
    {
        $appeal = $this->submitted();
        $doc = $appeal->documents()->first();
        $stranger = $this->student('22009999', 'x@test.my');
        $otherSup = $this->supervisor('o@test.my');
        $urls = [
            route('candidacy.appeals.show', $appeal), route('candidacy.appeals.region', $appeal), route('candidacy.appeals.documents.download', [$appeal, $doc]),
            route('candidacy.live.version', ['appeal' => $appeal->id]),
        ];

        foreach ([$stranger, $otherSup, $this->user(Role::DEAN_PGR, ['email' => 'dean2@test.my'])] as $user) {
            foreach ($urls as $url) {
                $this->actingAs($user)->get($url)->assertForbidden();
            }
        }

        foreach ([$this->student, $this->lecturer, $this->chairUser, $this->cgsUser] as $user) {
            $this->actingAs($user)->get($urls[0])->assertOk();
        }

        $this->actingAs($this->lecturer)->get(route('candidacy.appeals.edit', $appeal))->assertForbidden();
        $this->actingAs($stranger)->get(route('candidacy.appeals.edit', $appeal))->assertForbidden();
        $this->actingAs($this->student)->get(route('candidacy.appeals.memo', $appeal))->assertForbidden(); // not approved
        $this->actingAs($this->lecturer)->get(route('candidacy.status'))->assertForbidden(); // student page
    }

    public function test_a_draft_is_visible_only_to_its_student(): void
    {
        $draft = $this->draft();

        $this->actingAs($this->cgsUser)->get(route('candidacy.appeals.show', $draft))->assertForbidden();
        $this->actingAs($this->student)->get(route('candidacy.appeals.show', $draft))->assertOk()->assertSee('Draft');
    }

    public function test_memo_download_works_for_the_student_after_approval(): void
    {
        $appeal = $this->service->decide($this->deanUser, $this->toStage('dean', 2), 'approve');

        $response = $this->actingAs($this->student)->get(route('candidacy.appeals.memo', $appeal))->assertOk();
        $this->assertStringContainsString('SCA-22001001-01_Ahmad Danial_22001001_Memo - Appeal for Extension.pdf', $response->headers->get('content-disposition'));
        $this->actingAs($this->student)->get(route('candidacy.appeals.show', $appeal))->assertSee('Download memo')->assertSee('New expiry date');
    }

    // ---- page rules -----------------------------------------------------------------------------------------

    public function test_every_candidacy_page_is_no_store_prefixed_wca_and_free_of_inline_styles_and_handlers(): void
    {
        $appeal = $this->submitted();
        $pages = [route('candidacy.status'), route('candidacy.status.region'), route('candidacy.appeals.draft', 1), route('candidacy.appeals.draft', 2), route('candidacy.appeals.draft', 3), route('candidacy.appeals.draft', 4), route('candidacy.appeals.show', $appeal)];

        foreach ($pages as $url) {
            $response = $this->actingAs($this->student)->get($url);
            if ($response->status() === 302) {
                continue;
            }
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), $url);
            $html = $response->getContent();
            $page = preg_match('~<div class="wca-page"[^>]*>(.*?)<div\s+id="wsa-root"~s', $html, $m) ? $m[1] : $html;
            $this->assertDoesNotMatchRegularExpression('~\sstyle\s*=~i', $page, "inline style on {$url}");
            $this->assertDoesNotMatchRegularExpression('~\son[a-z]+\s*=~i', $page, "inline handler on {$url}");

            preg_match_all('~class="([^"]*)"~', $page, $classes);
            foreach ($classes[1] as $list) {
                foreach (preg_split('/\s+/', trim($list)) as $class) {
                    if ($class !== '' && ! str_starts_with($class, 'wca-') && ! str_starts_with($class, 'is-') && ! in_array($class, ['page-header', 'page-header-text', 'page-header-back', 'page-header-actions'], true)) {
                        $this->fail("class '{$class}' on {$url} is not prefixed wca-");
                    }
                }
            }
        }
    }

    public function test_the_assistant_placeholder_is_static_and_makes_no_request(): void
    {
        $html = $this->actingAs($this->student)->get(route('candidacy.status'))->getContent();

        $this->assertStringContainsString('The Study Candidacy assistant is coming soon.', $html);
        $this->assertStringContainsString('id="wsa-root"', $html);
        $this->assertStringContainsString('data-static="1"', $html);
        $this->assertStringNotContainsString('assistant/ask', $html);
        $this->assertStringNotContainsString('assistant/suggestion', $html);
        $this->assertStringNotContainsString('data-ask-url="', $html);
        $this->assertStringNotContainsString('data-suggestions-url="', $html);
        $this->assertStringNotContainsString('localStorage', file_get_contents(public_path('js/chloe-candidacy.js')));
    }

    public function test_the_candidacy_icon_is_the_workstation_icon(): void
    {
        $this->actingAs($this->student);
        $ws = view('chloe::workstation._assistant-widget')->render();
        $ca = view('chloe::workstation._assistant-widget', ['wsaStatic' => 'The Study Candidacy assistant is coming soon.'])->render();
        $grab = fn (string $html, string $re) => preg_match($re, $html, $m) ? preg_replace('/\s+/', ' ', $m[0]) : null;

        $this->assertNotNull($grab($ws, '~<style>.*?</style>~s'));
        $this->assertSame($grab($ws, '~<style>.*?</style>~s'), $grab($ca, '~<style>.*?</style>~s'));
        $this->assertSame($grab($ws, '~<template id="wsa-robot-template">.*?</template>~s'), $grab($ca, '~<template id="wsa-robot-template">.*?</template>~s'));
        $this->assertSame($grab($ws, '~<span id="wsa-toggle-icon".*?</span>~s'), $grab($ca, '~<span id="wsa-toggle-icon".*?</span>~s'));
        $this->assertSame($grab($ws, '~<div id="wsa-greeting".*?</div>~s'), $grab($ca, '~<div id="wsa-greeting".*?</div>~s'));
        $this->assertSame(
            preg_replace('/(aria-label|title)="[^"]*"/', '', $grab($ws, '~<button type="button" id="wsa-toggle".*?>~s')),
            preg_replace('/(aria-label|title)="[^"]*"/', '', $grab($ca, '~<button type="button" id="wsa-toggle".*?>~s'))
        );
    }

    public function test_the_live_version_changes_when_the_appeal_changes_and_is_throttled_by_the_shared_limiter(): void
    {
        $appeal = $this->submitted();
        $before = $this->actingAs($this->student)->getJson(route('candidacy.live.version'))->assertOk()->json('version');
        $this->service->decide($this->lecturer, $appeal, 'approve');
        $after = $this->actingAs($this->student)->getJson(route('candidacy.live.version'))->json('version');

        $this->assertNotSame($before, $after);
        $this->assertContains('throttle:workstation-live', \Illuminate\Support\Facades\Route::getRoutes()->getByName('candidacy.live.version')->middleware());
    }

    public function test_the_appeal_number_is_the_same_on_every_page(): void
    {
        $appeal = $this->service->decide($this->deanUser, $this->toStage('dean', 1), 'approve');

        foreach ([$this->student, $this->lecturer, $this->cgsUser] as $user) {
            $this->actingAs($user)->get(route('candidacy.appeals.show', $appeal))->assertSee('SCA-22001001-01');
        }
        $this->actingAs($this->student)->get(route('candidacy.status'))->assertSee('SCA-22001001-01');
    }

    // ---- the CGS candidacy pages read the new source ---------------------------------------------------------

    public function test_cgs_pending_appeals_come_from_the_new_tables_and_link_to_the_detail_page(): void
    {
        $appeal = $this->submitted();

        $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.operations'))->assertOk()
            ->assertSee('SCA-22001001-01')->assertSee(route('candidacy.appeals.show', $appeal), false)
            ->assertSee('The Study Candidacy assistant is coming soon.')->assertDontSee('Students Near Candidacy Expiry');
        $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.dismissals'))->assertOk()->assertSee('The Study Candidacy assistant is coming soon.');
    }

    public function test_a_final_rejection_makes_the_dismissal_reason_appeal_rejected(): void
    {
        $this->service->decide($this->deanUser, $this->toStage('dean'), 'reject', ['progress_insufficient']);
        $this->candidacy->update(['candidacy_expiry_date' => '2026-12-01']);

        $this->assertSame(1, (new \App\Modules\Chloe\Services\DismissalGenerator)->generate());
        $this->assertSame(\App\Modules\Chloe\Models\CandidacyDismissal::REASON_APPEAL_REJECTED, \App\Modules\Chloe\Models\CandidacyDismissal::first()->reason);
    }
}
