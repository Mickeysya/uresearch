<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Support\CandidacyOperationsQuery;
use App\Modules\Chloe\Support\CandidacyPortalQuery;
use App\Modules\Chloe\Support\CandidacyReturnPath;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** Design round: one appeal page, three portal tabs, the Operations cards and lists, Back, live endpoints. */
class CandidacyUnifiedViewsTest extends TestCase
{
    use BuildsCandidacyWorld;
    use MakesUsers;
    use RefreshDatabase;

    protected int $n = 0;

    /** An appeal in a given state for a fresh student, with the cast's named people. */
    protected function appeal(string $status, ?string $stage = null, array $extra = []): CandidacyAppeal
    {
        $n = ++$this->n;
        $student = $this->student((string) (30000000 + $n), "s{$n}@test.my");

        return CandidacyAppeal::create($extra + [
            'student_id' => $student->id, 'study_candidacy_id' => null, 'department_id' => $this->profile($this->lecturer)->department_id,
            'appeal_no' => sprintf('SCA-%d-01', 30000000 + $n), 'sequence_no' => 1, 'status' => $status, 'current_stage' => $stage, 'round' => 1,
            'supervisor_profile_id' => $this->profile($this->lecturer)->id, 'chair_profile_id' => $this->profile($this->chairUser)->id, 'dean_profile_id' => $this->profile($this->deanUser)->id,
            'requested_months' => 3, 'submitted_at' => now()->subDays(5), 'stage_entered_at' => now()->subDay(),
            'final_outcome' => in_array($status, ['approved', 'rejected', 'closed_expired'], true) ? ['approved' => 'approved', 'rejected' => 'rejected_final', 'closed_expired' => 'closed_expired'][$status] : null,
        ]);
    }

    /** @return array<string, CandidacyAppeal> */
    protected function allStates(): array
    {
        return [
            'sup' => $this->appeal('with_supervisor', 'supervisor'), 'chair' => $this->appeal('with_chair', 'chair'), 'cgs' => $this->appeal('with_cgs', 'cgs'),
            'dean' => $this->appeal('with_dean', 'dean'), 'ret' => $this->appeal('returned', null, ['returned_by_stage' => 'chair']),
            'appr' => $this->appeal('approved'), 'rej' => $this->appeal('rejected'), 'exp' => $this->appeal('closed_expired'), 'draft' => $this->appeal('draft', null, ['appeal_no' => null, 'sequence_no' => null]),
        ];
    }

    // ---- portal tabs --------------------------------------------------------------------------------------------

    public function test_each_appeal_is_in_exactly_one_tab_for_lecturer_chair_and_dean(): void
    {
        $a = $this->allStates();
        $named = collect($a)->except('draft')->map->id->values()->sort()->values()->all();

        foreach ([$this->lecturer, $this->chairUser, $this->deanUser] as $user) {
            $tabs = collect(CandidacyPortalQuery::tabs($user))->mapWithKeys(fn ($t) => [$t => CandidacyPortalQuery::forTab($user, $t)->pluck('id')->all()]);
            $this->assertSame(['pending', 'ongoing', 'history'], $tabs->keys()->all(), $user->role);
            $all = $tabs->flatten()->sort()->values()->all();
            $this->assertSame($named, $all, "{$user->role}: the three tabs together are every named appeal, each once");
            $this->assertCount(count($all), array_unique($all), "{$user->role}: no overlap");
        }

        $this->assertSame([$a['sup']->id], CandidacyPortalQuery::pending($this->lecturer)->pluck('id')->all());
        $this->assertSame([$a['chair']->id], CandidacyPortalQuery::pending($this->chairUser)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$a['chair']->id, $a['cgs']->id, $a['dean']->id, $a['ret']->id], CandidacyPortalQuery::ongoing($this->lecturer)->pluck('id')->all());
    }

    public function test_history_holds_only_final_results_and_a_not_yet_reached_appeal_is_ongoing_for_the_dean(): void
    {
        $a = $this->allStates();

        $this->assertEqualsCanonicalizing([$a['appr']->id, $a['rej']->id, $a['exp']->id], CandidacyPortalQuery::history($this->deanUser)->pluck('id')->all());
        $this->assertContains($a['sup']->id, CandidacyPortalQuery::ongoing($this->deanUser)->pluck('id')->all());
        $this->assertNotContains($a['draft']->id, CandidacyPortalQuery::named($this->lecturer)->pluck('id')->all());
    }

    public function test_the_portal_pages_show_the_right_tabs_columns_and_no_decided_by_me_for_approvers(): void
    {
        $this->allStates();
        $page = fn ($tab) => $this->actingAs($this->chairUser)->get(route('candidacy.portal.queue', ['tab' => $tab]))->assertOk();

        $page('pending')->assertSee('Pending my action')->assertSee('Ongoing')->assertSee('History')->assertDontSee('Decided by me')->assertDontSee('View all appeals')->assertSee('>Review<', false);
        $page('ongoing')->assertSee('Current step')->assertSee('View details')->assertDontSee('>Review<', false);
        $page('history')->assertSee('My decision')->assertSee('Final outcome')->assertSee('Closed expired')->assertSee('Not reached');
    }

    public function test_cgs_has_two_tabs_and_the_view_all_link_and_nobody_else_does(): void
    {
        $this->allStates();

        $this->actingAs($this->cgsUser)->get(route('candidacy.portal.queue'))->assertOk()->assertSee('Pending my action')->assertSee('Decided by me')->assertDontSee('Ongoing')
            ->assertSee('View all appeals')->assertSee(route('candidacy.cgs.operations'), false);
        foreach ([$this->lecturer, $this->chairUser, $this->deanUser] as $user) {
            $this->actingAs($user)->get(route('candidacy.portal.queue'))->assertDontSee('View all appeals');
        }
    }

    public function test_my_decision_labels(): void
    {
        $a = $this->appeal('with_supervisor', 'supervisor');
        $this->service->decide($this->lecturer, $a->fresh(), 'approve');
        $a = $a->fresh()->load('approvals');

        $this->assertSame('Approved', CandidacyPortalQuery::myDecision($this->lecturer, $a));
        $this->assertSame('Not reached', CandidacyPortalQuery::myDecision($this->deanUser, $a));
    }

    // ---- the one appeal page ------------------------------------------------------------------------------------

    public function test_your_action_exists_only_for_the_person_it_is_pending_with(): void
    {
        $a = $this->appeal('with_supervisor', 'supervisor');

        $this->actingAs($this->lecturer)->get(route('candidacy.appeals.show', $a))->assertSee('Your action')->assertSee('data-modal-open="wca-modal-approve"', false);
        foreach ([$this->chairUser, $this->deanUser, $this->cgsUser, \App\Modules\Core\Models\User::find($a->student_id)] as $user) {
            $this->actingAs($user)->get(route('candidacy.appeals.show', $a))->assertOk()->assertDontSee('Your action')->assertDontSee('data-modal-open', false);
        }
    }

    public function test_the_page_title_is_only_the_appeal_number_and_there_is_one_url(): void
    {
        $a = $this->appeal('with_supervisor', 'supervisor');
        $html = $this->actingAs($this->lecturer)->get(route('candidacy.appeals.show', $a))->getContent();

        $this->assertMatchesRegularExpression('~<h2>\s*SCA-30000001-01\s*</h2>~', $html);
        $this->assertStringNotContainsString('Review SCA', $html);
        $this->actingAs($this->lecturer)->get(route('candidacy.portal.review', $a))->assertRedirect(route('candidacy.appeals.show', $a));

        // the Review button and the CGS "View details" button open that same URL (plus the return path)
        $portal = $this->actingAs($this->lecturer)->get(route('candidacy.portal.queue'))->getContent();
        $this->assertStringContainsString('/candidacy/appeals/'.$a->id.'?return=', html_entity_decode($portal));
        $ops = $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.operations'))->getContent();
        $this->assertStringContainsString('/candidacy/appeals/'.$a->id.'?return=', html_entity_decode($ops));
    }

    public function test_only_named_people_and_cgs_can_open_an_appeal_and_it_is_in_no_other_list(): void
    {
        $a = $this->appeal('with_supervisor', 'supervisor');
        $other = $this->supervisor('o@test.my');
        $student2 = $this->student('29999999', 'zz@test.my');

        foreach ([$other, $student2, $this->user(Role::DEAN_PGR, ['email' => 'd2@test.my'])] as $user) {
            $this->actingAs($user)->get(route('candidacy.appeals.show', $a))->assertForbidden();
            $this->actingAs($user)->get(route('candidacy.appeals.region', $a))->assertForbidden();
        }
        foreach ([$this->lecturer, $this->chairUser, $this->deanUser, $this->cgsUser] as $user) {
            $this->actingAs($user)->get(route('candidacy.appeals.show', $a))->assertOk();
        }
        $this->actingAs($other)->get(route('candidacy.portal.queue', ['tab' => 'ongoing']))->assertDontSee($a->appeal_no);
        $this->actingAs($other)->get(route('candidacy.portal.queue', ['tab' => 'history']))->assertDontSee($a->appeal_no);
    }

    // ---- Back and the return path -------------------------------------------------------------------------------

    public function test_return_path_accepts_only_internal_candidacy_paths(): void
    {
        foreach (['/candidacy/portal?tab=history&q=Ahmad&department=3', '/cgs/candidacy?group=approved&page=2', '/candidacy'] as $ok) {
            $this->assertSame($ok, CandidacyReturnPath::validate($ok), $ok);
        }
        foreach (['https://evil.example/candidacy', '//evil.example/candidacy', '/\\evil.example', 'javascript:alert(1)', '/admin', '/candidacyevil', '/candidacy/../admin', "/candidacy\r\nX: y", '', null, 'candidacy/portal', 'http://localhost/candidacy'] as $bad) {
            $this->assertNull(CandidacyReturnPath::validate($bad), (string) $bad);
        }
    }

    public function test_back_goes_to_the_origin_with_its_filters_or_to_a_safe_default(): void
    {
        $a = $this->appeal('with_supervisor', 'supervisor');
        $origin = '/candidacy/portal?tab=ongoing&q=Ahmad&department=1';
        $back = fn (User $u, ?string $return) => (preg_match('~<a href="([^"]*)" class="page-header-back~', html_entity_decode($this->actingAs($u)->get(route('candidacy.appeals.show', ['appeal' => $a->id, 'return' => $return]))->getContent()), $m) ? $m[1] : null);

        $this->assertSame($origin, $back($this->lecturer, $origin));
        $this->assertStringEndsWith('/candidacy/portal', (string) $back($this->lecturer, 'https://evil.example/'));
        $this->assertStringEndsWith('/candidacy/portal', (string) $back($this->lecturer, null));
        $this->assertStringEndsWith('/cgs/candidacy', (string) $back($this->cgsUser, null));
        $this->assertStringEndsWith('/candidacy', (string) $back(User::find($a->student_id), null));
    }

    public function test_the_return_path_survives_a_decision_and_the_live_region(): void
    {
        $a = $this->appeal('with_supervisor', 'supervisor');
        $origin = '/candidacy/portal?tab=pending&q=SCA';

        $this->actingAs($this->lecturer)->post(route('candidacy.portal.decide', $a), ['action' => 'approve', 'return' => $origin])->assertRedirect($origin);
        $this->actingAs($this->lecturer)->post(route('candidacy.portal.decide', $this->appeal('with_supervisor', 'supervisor')), ['action' => 'approve', 'return' => 'https://evil.example/'])->assertRedirect(route('candidacy.portal.queue', [], false));
    }

    // ---- Operations ---------------------------------------------------------------------------------------------

    public function test_card_numbers_equal_list_counts_and_closed_expired_is_only_in_all(): void
    {
        $this->allStates();
        $this->appeal('with_chair', 'chair');
        $f = CandidacyOperationsQuery::filters([]);
        $counts = CandidacyOperationsQuery::counts($f);

        $this->assertSame(['in_progress' => 5, 'returned' => 1, 'approved' => 1, 'rejected' => 1, 'all' => 9], $counts);
        foreach (array_keys(CandidacyOperationsQuery::GROUPS) as $g) {
            $this->assertSame($counts[$g], CandidacyOperationsQuery::forGroup($f, $g)->count(), $g);
        }
        $this->assertSame(['closed_expired'], CandidacyOperationsQuery::forGroup($f, null)->whereNotIn('status', array_merge(...array_values(CandidacyOperationsQuery::GROUPS)))->pluck('status')->all());
        $this->assertNotContains('returned', CandidacyOperationsQuery::GROUPS['in_progress']);
        $this->assertSame(0, CandidacyOperationsQuery::base($f)->where('status', 'draft')->count());
    }

    public function test_clicking_a_card_filters_exactly_those_appeals_and_the_state_is_in_the_url(): void
    {
        $this->allStates();

        $page = $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.operations', ['group' => 'approved']))->assertOk();
        $html = $page->getContent();
        $this->assertSame(1, substr_count($html, 'View details'));
        $this->assertStringContainsString('is-active', $html);
        $this->assertStringContainsString('aria-pressed="true"', $html);
        // clicking the active card again clears it (the link goes to the list without a group)
        $this->assertMatchesRegularExpression('~wca-statcard is-active"\s+href="[^"?]*(\?[^"]*)?"~', $html);
        $this->assertDoesNotMatchRegularExpression('~wca-statcard is-active"\s+href="[^"]*group=approved~', html_entity_decode($html));

        $all = $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.operations'))->getContent();
        $this->assertSame(8, substr_count($all, 'View details')); // all, incl. the closed one
        $this->assertSame(4, substr_count($this->actingAs($this->cgsUser)->get(route('candidacy.cgs.operations', ['group' => 'in_progress']))->getContent(), 'View details'));
        $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.operations', ['group' => 'nonsense']))->assertOk(); // an unknown group is ignored
    }

    public function test_operations_filters_and_the_cards_use_the_same_query(): void
    {
        $this->allStates();
        $f = CandidacyOperationsQuery::filters(['months' => 3, 'step' => 'chair']);

        $this->assertSame(['in_progress' => 1, 'returned' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 1], CandidacyOperationsQuery::counts($f));
        $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.operations', ['step' => 'chair']))->assertSee('data-live-stat="in_progress">1<', false);
    }

    public function test_operations_table_shape_and_wording(): void
    {
        $a = $this->appeal('with_supervisor', 'supervisor');
        $html = $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.operations'))->assertOk()->getContent();

        foreach (['Appeal No.', 'Student', 'Programme', 'Department', 'Requested (mo)', 'Approved (mo)', 'Status', 'Current step', 'Submitted', 'Last updated', 'Waiting days', 'View details', 'In progress', 'Returned', 'Approved', 'Rejected', 'All'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('Pending Appeals', $html);
        $this->assertStringNotContainsString('Export', $html);
        $this->assertStringNotContainsString('Students Near Candidacy Expiry', $html); // only the four agreed cards
        $this->assertSame(4, preg_match_all('~class="wca-statcard( is-active)?"~', $html));
        $this->assertStringNotContainsString('>'.$a->appeal_no.'</a>', $html); // the number is plain text, not a link
        $this->assertStringContainsString($a->appeal_no, $html);
    }

    public function test_operations_pages_the_list(): void
    {
        foreach (range(1, 27) as $i) {
            $this->appeal('with_chair', 'chair');
        }

        $p1 = $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.operations'))->getContent();
        $p2 = $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.operations', ['page' => 2]))->getContent();

        $this->assertSame(25, substr_count($p1, 'View details'));
        $this->assertSame(2, substr_count($p2, 'View details'));
        $this->assertStringContainsString('Page 2 of 2', $p2);
    }

    // ---- access, headers and live endpoints ---------------------------------------------------------------------

    public function test_operations_and_its_live_endpoints_are_cgs_only_and_never_cached(): void
    {
        $urls = [route('candidacy.cgs.operations'), route('candidacy.cgs.operations.region'), route('candidacy.cgs.live.version'), route('candidacy.cgs.live.stats')];

        foreach ($urls as $url) {
            foreach ([$this->lecturer, $this->chairUser, $this->deanUser, $this->student] as $user) {
                $this->actingAs($user)->get($url)->assertForbidden();
            }
            $response = $this->actingAs($this->cgsUser)->get($url)->assertOk();
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), $url);
        }
        foreach (['candidacy.cgs.live.version', 'candidacy.cgs.live.stats'] as $name) {
            $this->assertContains('throttle:workstation-live', \Illuminate\Support\Facades\Route::getRoutes()->getByName($name)->middleware());
        }
    }

    public function test_the_stats_endpoint_matches_the_cards(): void
    {
        $this->allStates();

        $stats = $this->actingAs($this->cgsUser)->getJson(route('candidacy.cgs.live.stats'))->json('stats');
        $this->assertSame(['in_progress' => 4, 'returned' => 1, 'approved' => 1, 'rejected' => 1, 'all' => 8], $stats);
    }

    public function test_the_portal_live_endpoint_returns_only_the_callers_own_data(): void
    {
        $this->appeal('with_supervisor', 'supervisor');
        $this->appeal('with_chair', 'chair');

        $lecturer = $this->actingAs($this->lecturer)->getJson(route('candidacy.portal.live.version'))->assertOk()->json();
        $chair = $this->actingAs($this->chairUser)->getJson(route('candidacy.portal.live.version'))->json();
        $other = $this->actingAs($this->supervisor('o@test.my'))->getJson(route('candidacy.portal.live.version'))->json();

        $this->assertSame(['pending' => 1, 'ongoing' => 1, 'history' => 0], $lecturer['counts']);
        $this->assertSame(['pending' => 1, 'ongoing' => 1, 'history' => 0], $chair['counts']);
        $this->assertSame(['pending' => 0, 'ongoing' => 0, 'history' => 0], $other['counts']);
        $this->assertNotSame($lecturer['version'], $other['version']);
        $this->assertStringNotContainsString('SCA-', json_encode($lecturer));
    }

    public function test_the_live_markup_has_the_pause_control_and_poll_interval(): void
    {
        $html = $this->actingAs($this->cgsUser)->get(route('candidacy.cgs.operations'))->getContent();

        $this->assertStringContainsString('Pause updates', $html);
        $this->assertStringContainsString('data-interval="15000"', $html);
        $this->assertStringContainsString('data-stats-url', $html);
        $this->assertStringContainsString('120px', file_get_contents(public_path('css/chloe-candidacy.css'))); // free space under every page for the assistant icon
    }

    // ---- one wording --------------------------------------------------------------------------------------------

    public function test_the_old_wordings_are_gone_from_the_module_and_its_docs(): void
    {
        $banned = ['Needs my action', 'Waiting for my action', 'Pending Appeals', 'waiting for your action', 'is waiting for you', 'Programme Chair', 'Chair of Department'];
        $files = collect([
            ...glob(base_path('app/Modules/Chloe/Resources/views/candidacy/**/*.php')) ?: [], base_path('config/candidacy.php'), base_path('docs/candidacy-appeal-inventory.md'), base_path('app/Modules/Chloe/README.md'),
        ]);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app/Modules/Chloe'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (preg_match('/\.(php|md)$/', (string) $file)) {
                $files->push((string) $file);
            }
        }

        foreach ($files->unique() as $file) {
            $text = file_get_contents($file);
            foreach ($banned as $phrase) {
                $this->assertStringNotContainsString($phrase, $text, "{$phrase} in {$file}");
            }
        }
    }
}
