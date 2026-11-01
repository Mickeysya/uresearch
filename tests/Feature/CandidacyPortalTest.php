<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyDepartment;
use App\Modules\Chloe\Models\CandidacyStaffAssignment;
use App\Modules\Chloe\Models\CandidacyStaffProfile;
use App\Modules\Chloe\Notifications\CandidacyAppealNotice;
use App\Modules\Chloe\Support\CandidacyPortalQuery;
use App\Modules\Core\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** Stage 2: the Approval Portal -- queue, review, decisions, signatures, access. */
class CandidacyPortalTest extends TestCase
{
    use BuildsCandidacyWorld;
    use MakesUsers;
    use RefreshDatabase;

    protected function decide(\App\Modules\Core\Models\User $user, CandidacyAppeal $appeal, array $data)
    {
        return $this->actingAs($user)->post(route('candidacy.portal.decide', $appeal), $data);
    }

    // ---- queue and visibility -----------------------------------------------------------------------------

    public function test_the_queue_shows_only_appeals_waiting_for_the_named_person(): void
    {
        $appeal = $this->submitted();
        $other = $this->supervisor('o@test.my');

        $this->actingAs($this->lecturer)->get(route('candidacy.portal.queue'))->assertOk()->assertSee('SCA-22001001-01')->assertSee('Waiting for my action')->assertSee('Decided by me');
        $this->actingAs($other)->get(route('candidacy.portal.queue'))->assertOk()->assertDontSee('SCA-22001001-01');
        $this->actingAs($this->chairUser)->get(route('candidacy.portal.queue'))->assertOk()->assertDontSee('SCA-22001001-01');

        $this->service->decide($this->lecturer, $appeal, 'approve');
        $this->actingAs($this->chairUser)->get(route('candidacy.portal.queue'))->assertSee('SCA-22001001-01');
        $this->actingAs($this->lecturer)->get(route('candidacy.portal.queue'))->assertDontSee('Review');
        $this->actingAs($this->lecturer)->get(route('candidacy.portal.queue', ['tab' => 'decided']))->assertSee('SCA-22001001-01')->assertSee('Approved');
    }

    public function test_cgs_roles_see_the_cgs_stage_queue_and_other_roles_are_refused(): void
    {
        $appeal = $this->toStage('chair');
        $this->service->decide($this->chairUser, $appeal, 'approve');

        foreach (config('candidacy.cgs_roles') as $i => $role) {
            $this->actingAs($this->user($role, ['email' => "c{$i}@test.my"]))->get(route('candidacy.portal.queue'))->assertOk()->assertSee('SCA-22001001-01');
        }
        $this->actingAs($this->student)->get(route('candidacy.portal.queue'))->assertForbidden();
        $this->actingAs($this->user(Role::ACADEMIC_EXEC, ['email' => 'ae2@test.my']))->get(route('candidacy.portal.queue'))->assertForbidden();
    }

    public function test_review_page_is_403_for_other_lecturers_chairs_of_other_departments_and_students(): void
    {
        $appeal = $this->submitted();
        $dept2 = CandidacyDepartment::create(['code' => 'EEE', 'name' => 'Electrical']);
        $chair2 = $this->user(Role::CHAIR, ['email' => 'chair2@test.my']);
        $p = CandidacyStaffProfile::create(['user_id' => $chair2->id, 'full_name' => 'Chair Two', 'designation' => 'Programme Chair', 'department_id' => $dept2->id]);
        CandidacyStaffAssignment::create(['department_id' => $dept2->id, 'staff_profile_id' => $p->id, 'role' => 'chair']);

        foreach ([$this->supervisor('o@test.my'), $chair2, $this->student('22009999', 'x@test.my'), $this->user(Role::DEAN_PGR, ['email' => 'd2@test.my'])] as $user) {
            $this->actingAs($user)->get(route('candidacy.portal.review', $appeal))->assertForbidden();
            $this->actingAs($user)->get(route('candidacy.portal.review.region', $appeal))->assertForbidden();
            $this->decide($user, $appeal, ['action' => 'approve'])->assertForbidden();
        }
        $this->assertSame('with_supervisor', $appeal->fresh()->status);
    }

    public function test_queue_search_and_filters(): void
    {
        $appeal = $this->submitted(5);
        $q = fn (array $p) => $this->actingAs($this->lecturer)->get(route('candidacy.portal.queue', $p));

        $q(['q' => 'SCA-22001001'])->assertSee('SCA-22001001-01');
        $q(['q' => 'Ahmad'])->assertSee('SCA-22001001-01');
        $q(['q' => '22001001'])->assertSee('SCA-22001001-01');
        $q(['q' => 'nobody'])->assertDontSee('SCA-22001001-01');
        $q(['months' => 5])->assertSee('SCA-22001001-01');
        $q(['months' => 3])->assertDontSee('SCA-22001001-01');
        $q(['department' => $appeal->department_id])->assertSee('SCA-22001001-01');
        $q(['department' => 999])->assertDontSee('SCA-22001001-01');
        $q(['round' => 2])->assertDontSee('SCA-22001001-01');
        $q(['waiting' => 3])->assertDontSee('SCA-22001001-01');
    }

    public function test_waiting_markers_use_the_configured_days(): void
    {
        $this->assertNull(CandidacyPortalQuery::marker(4));
        $this->assertSame('gold', CandidacyPortalQuery::marker(5));
        $this->assertSame('gold', CandidacyPortalQuery::marker(9));
        $this->assertSame('red', CandidacyPortalQuery::marker(10));

        $appeal = $this->submitted();
        $appeal->update(['stage_entered_at' => now()->subDays(11)]);
        $this->actingAs($this->lecturer)->get(route('candidacy.portal.queue'))->assertSee('is-red', false)->assertSee('11 day(s) at this step');
    }

    public function test_the_recheck_tag_shows_after_a_dean_return(): void
    {
        $appeal = $this->toStage('dean');
        $appeal = $this->service->decide($this->deanUser, $appeal, 'return', ['clarification_needed']);
        $this->service->resubmit($this->student, $appeal);

        $this->actingAs($this->cgsUser)->get(route('candidacy.portal.queue'))->assertSee('Re-check after Dean return');
    }

    // ---- action sets and decisions --------------------------------------------------------------------------

    public function test_the_action_panel_has_no_reject_at_supervisor_chair_and_cgs_but_does_at_the_dean(): void
    {
        $appeal = $this->submitted();
        $this->actingAs($this->lecturer)->get(route('candidacy.portal.review', $appeal))->assertSee('Approve')->assertSee('Return to student')->assertDontSee('Reject (final)')->assertDontSee('wca-modal-reject', false)->assertDontSee('value="reject"', false);

        $appeal = $this->service->decide($this->lecturer, $appeal, 'approve');
        $this->actingAs($this->chairUser)->get(route('candidacy.portal.review', $appeal))->assertSee('Approve')->assertDontSee('Reject (final)')->assertDontSee('value="reject"', false);

        $appeal = $this->service->decide($this->chairUser, $appeal, 'approve');
        $this->actingAs($this->cgsUser)->get(route('candidacy.portal.review', $appeal))->assertSee('Verify')->assertSee('Documents complete')->assertDontSee('Reject (final)')->assertDontSee('value="reject"', false);

        $appeal = $this->service->decide($this->cgsUser, $appeal, 'verify', documentsComplete: true);
        $this->actingAs($this->deanUser)->get(route('candidacy.portal.review', $appeal))->assertSee('Reject (final)')->assertSee('New expiry date preview')->assertSee('Allowance after approval')->assertSee('31 Jul 2027');
    }

    public function test_decisions_are_modal_only_and_never_use_a_browser_confirm(): void
    {
        $appeal = $this->submitted();
        $html = $this->actingAs($this->lecturer)->get(route('candidacy.portal.review', $appeal))->getContent();

        $this->assertStringContainsString('data-modal-open="wca-modal-approve"', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringNotContainsString('confirm(', $html);
        $this->assertStringNotContainsString('onsubmit', $html);
        $this->assertStringNotContainsString('confirm(', file_get_contents(public_path('js/chloe-candidacy.js')));
    }

    public function test_posting_a_decision_through_the_portal_moves_the_appeal_and_shows_a_banner(): void
    {
        $appeal = $this->submitted();

        $this->decide($this->lecturer, $appeal, ['action' => 'approve'])->assertRedirect(route('candidacy.portal.queue'))->assertSessionHas('wca_status', 'Appeal SCA-22001001-01 was approved.');
        $this->assertSame('with_chair', $appeal->fresh()->status);
        $this->followingRedirects()->actingAs($this->lecturer)->get(route('candidacy.portal.queue'))->assertSee('Appeal SCA-22001001-01 was approved.');
    }

    public function test_return_needs_reasons_and_other_needs_a_remark_through_the_portal(): void
    {
        $appeal = $this->submitted();

        $this->decide($this->lecturer, $appeal, ['action' => 'return'])->assertSessionHasErrors('reasons');
        $this->decide($this->lecturer, $appeal, ['action' => 'return', 'reasons' => ['other']])->assertSessionHasErrors('remark');
        $this->decide($this->lecturer, $appeal, ['action' => 'return', 'reasons' => ['documents_missing']])->assertSessionHasNoErrors();
        $this->assertSame('returned', $appeal->fresh()->status);
    }

    public function test_reject_is_refused_below_the_dean_even_when_posted_directly(): void
    {
        $appeal = $this->submitted();

        $this->decide($this->lecturer, $appeal, ['action' => 'reject', 'reasons' => ['other'], 'remark' => 'x'])->assertSessionHasErrors('action');
        $this->assertSame('with_supervisor', $appeal->fresh()->status);
    }

    public function test_dean_reject_is_final_and_dean_return_goes_to_cgs_then_back(): void
    {
        $appeal = $this->toStage('dean');
        $appeal = $this->service->decide($this->deanUser, $appeal, 'return', ['clarification_needed']);
        $this->service->resubmit($this->student, $appeal);
        $this->decide($this->cgsUser, $appeal->fresh(), ['action' => 'verify', 'documents_complete' => 'yes']);
        $this->decide($this->deanUser, $appeal->fresh(), ['action' => 'reject', 'reasons' => ['policy_not_met']])->assertSessionHasNoErrors();

        $this->assertSame('rejected', $appeal->fresh()->status);
        $this->assertNotSame([], $this->service->eligibilityProblems($this->candidacy->fresh()));
    }

    public function test_cgs_verification_needs_documents_complete_yes(): void
    {
        $appeal = $this->toStage('chair');
        $appeal = $this->service->decide($this->chairUser, $appeal, 'approve');

        $this->decide($this->cgsUser, $appeal, ['action' => 'verify', 'documents_complete' => 'no'])->assertSessionHasErrors('documents_complete');
        $this->decide($this->cgsUser, $appeal, ['action' => 'verify', 'documents_complete' => 'yes'])->assertSessionHasNoErrors();
        $this->assertSame('with_dean', $appeal->fresh()->status);
    }

    // ---- signatures -----------------------------------------------------------------------------------------

    public function test_without_a_signature_the_panel_shows_the_notice_and_the_server_refuses(): void
    {
        $appeal = $this->submitted();
        $this->service_signatures()->remove($this->profile($this->lecturer));

        $this->actingAs($this->lecturer)->get(route('candidacy.portal.review', $appeal))->assertSee('Upload your signature')->assertSee(route('candidacy.signature.show'), false)->assertDontSee('data-modal-open="wca-modal-approve"', false);
        $this->decide($this->lecturer, $appeal, ['action' => 'approve'])->assertSessionHasErrors('signature');
        $this->assertSame('with_supervisor', $appeal->fresh()->status);
    }

    public function test_my_signature_page_upload_replace_remove_and_owner_only(): void
    {
        $profile = $this->profile($this->lecturer);

        $this->actingAs($this->lecturer)->get(route('candidacy.signature.show'))->assertOk()->assertSee('Your signature on file');
        $this->actingAs($this->lecturer)->post(route('candidacy.signature.store'), ['signature' => UploadedFile::fake()->image('s.png', 800, 300)])->assertRedirect(route('candidacy.signature.show'));
        $first = $profile->fresh()->signature_path;
        $this->actingAs($this->lecturer)->get(route('candidacy.signature.image'))->assertOk()->assertHeader('content-type', 'image/png');

        $this->actingAs($this->lecturer)->post(route('candidacy.signature.store'), ['signature' => UploadedFile::fake()->image('t.jpg', 400, 150)]);
        $this->assertNotSame($first, $profile->fresh()->signature_path);
        Storage::disk('local')->assertMissing($first);

        $this->actingAs($this->lecturer)->post(route('candidacy.signature.store'), ['signature' => UploadedFile::fake()->image('tiny.png', 50, 20)])->assertSessionHasErrors('signature');
        $this->actingAs($this->lecturer)->post(route('candidacy.signature.store'), ['signature' => UploadedFile::fake()->createWithContent('x.png', 'not an image')])->assertSessionHasErrors('signature');

        $this->actingAs($this->chairUser)->get(route('candidacy.signature.image'))->assertOk(); // the chair's OWN image, never the lecturer's
        $this->assertNotSame(
            $this->actingAs($this->lecturer)->get(route('candidacy.signature.image'))->streamedContent() ?: $this->actingAs($this->lecturer)->get(route('candidacy.signature.image'))->getContent(),
            ''
        );
        $this->actingAs($this->student)->get(route('candidacy.signature.show'))->assertForbidden();
        $this->actingAs($this->student)->get(route('candidacy.signature.image'))->assertForbidden();

        $this->actingAs($this->lecturer)->delete(route('candidacy.signature.destroy'))->assertRedirect();
        $this->assertFalse($profile->fresh()->hasSignature());
    }

    public function test_a_cgs_user_without_a_profile_can_upload_an_optional_signature(): void
    {
        $this->actingAs($this->cgsUser)->get(route('candidacy.signature.show'))->assertSee('optional');
        $this->actingAs($this->cgsUser)->post(route('candidacy.signature.store'), ['signature' => UploadedFile::fake()->image('s.png', 400, 150)])->assertRedirect();

        $this->assertTrue(CandidacyStaffProfile::where('user_id', $this->cgsUser->id)->first()->hasSignature());
    }

    // ---- page rules -----------------------------------------------------------------------------------------

    public function test_portal_pages_are_no_store_wca_prefixed_and_free_of_inline_styles_and_handlers(): void
    {
        $appeal = $this->submitted();
        $pages = [route('candidacy.portal.queue'), route('candidacy.portal.queue', ['tab' => 'decided']), route('candidacy.portal.region'), route('candidacy.portal.review', $appeal), route('candidacy.portal.review.region', $appeal), route('candidacy.signature.show'), route('candidacy.portal.live.version')];

        foreach ($pages as $url) {
            $response = $this->actingAs($this->lecturer)->get($url)->assertOk();
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), $url);
            $html = $response->getContent();
            $page = preg_match('~<div class="wca-page">(.*?)<div\s+id="wsa-root"~s', $html, $m) ? $m[1] : $html;
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

    public function test_the_queue_version_changes_when_something_arrives_and_is_throttled(): void
    {
        $before = $this->actingAs($this->chairUser)->getJson(route('candidacy.portal.live.version'))->json('version');
        $appeal = $this->submitted();
        $this->service->decide($this->lecturer, $appeal, 'approve');
        $after = $this->actingAs($this->chairUser)->getJson(route('candidacy.portal.live.version'))->json('version');

        $this->assertNotSame($before, $after);
        $this->assertContains('throttle:workstation-live', \Illuminate\Support\Facades\Route::getRoutes()->getByName('candidacy.portal.live.version')->middleware());
    }

    public function test_the_workflow_points_the_sidebar_at_the_portal_and_offers_my_signature(): void
    {
        $workflow = new \App\Modules\Chloe\Workflows\CandidacyAppealWorkflow;

        $this->assertSame('candidacy.portal.queue', $workflow->queueRoute());
        $this->assertContains('candidacy.signature.show', array_column($workflow->links($this->lecturer), 'route'));
        $this->assertSame([], $workflow->links($this->student));
        $this->assertContains('candidacy.portal.queue', array_column($workflow->links($this->user(Role::MANAGER_CGS, ['email' => 'm9@test.my'])), 'route'));
    }

    public function test_old_approver_urls_redirect_to_the_portal_and_decide_nothing(): void
    {
        $this->actingAs($this->lecturer)->get('/candidacy-appeal/queue')->assertRedirect(route('candidacy.portal.queue'));
        $this->actingAs($this->lecturer)->post('/candidacy-appeal/1/decide')->assertRedirect(route('candidacy.portal.queue'));
    }

    public function test_new_appeals_never_appear_in_the_shared_engine(): void
    {
        $this->submitted();

        $this->assertSame(0, \App\Modules\Core\Models\Application::where('module_type', 'candidacy_appeal')->count());
    }

    public function test_notifications_reach_only_the_matrix_people(): void
    {
        $appeal = $this->submitted();
        \Illuminate\Support\Facades\Notification::assertSentTo($this->lecturer, CandidacyAppealNotice::class);
        \Illuminate\Support\Facades\Notification::assertNotSentTo($this->chairUser, CandidacyAppealNotice::class);
    }
}
