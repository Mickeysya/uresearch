<?php

namespace Tests\Feature;

use App\Modules\Chloe\Assistant\AssistantDomains as D;
use App\Modules\Chloe\Assistant\Catalogue;
use App\Modules\Chloe\Assistant\FakeModel;
use App\Modules\Chloe\Assistant\Pipeline;
use App\Modules\Chloe\Assistant\RouterFewShots;
use App\Modules\Chloe\Models\AssistantLog;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesFakeAssistant;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** Two domains on one engine: each has its own content and one never answers the other. */
class AssistantDomainsTest extends TestCase
{
    use BuildsCandidacyWorld {
        setUp as protected worldSetUp;
    }
    use MakesFakeAssistant;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->worldSetUp();
        $this->setUpFakeAssistant();
    }

    protected function ask(User $user, string $domain, string $question, ?string $routeTo = null): array
    {
        if ($routeTo !== null) {
            FakeModel::queueRoute(['id' => $routeTo]);
        }

        return app(Pipeline::class)->setDomain($domain)->ask($user, $question, []);
    }

    protected function claim(User $user, string $domain, string $page): array
    {
        return ['domain' => $domain, 'page' => $page, 'token' => D::token($domain, $user->id, $page)];
    }

    public function test_each_domains_catalogue_contains_nothing_of_the_other_for_every_role(): void
    {
        foreach (['student', 'approver', 'cgs', 'cgs_approver'] as $role) {
            $ws = collect(Catalogue::forRole($role, 'workstation'))->pluck('id')->all();
            $cand = collect(Catalogue::forRole($role, 'candidacy'))->pluck('id')->all();

            $this->assertSame(['out_of_scope'], array_values(array_intersect($ws, $cand)), "{$role}: only the shared out_of_scope is in both");
            $this->assertEmpty(array_filter($ws, fn ($id) => str_starts_with($id, 'cand_')), "{$role}: a candidacy id in the workstation catalogue");
            $this->assertEmpty(array_filter(Catalogue::forRole($role, 'candidacy'), fn ($e) => $e['domain'] === 'workstation'), "{$role}: a workstation entry in the candidacy catalogue");
        }

        $this->assertSame([], Catalogue::forRole('approver', 'workstation') === [] ? [] : array_diff(array_column(Catalogue::forRole('approver', 'workstation'), 'id'), ['out_of_scope']), 'an approver has no Workstation entries');
    }

    public function test_the_model_is_only_ever_given_the_current_domains_catalogue_and_examples(): void
    {
        foreach (['workstation' => $this->student, 'candidacy' => $this->student] as $domain => $user) {
            FakeModel::reset();
            $this->ask($user, $domain, 'tell me something');
            $call = FakeModel::routeCalls()[0];
            $ids = array_column($call['catalogue'], 'id');

            $this->assertSame(array_column(Catalogue::forRole('student', $domain), 'id'), $ids, $domain);
            $this->assertEmpty(array_filter($call['catalogue'], fn ($e) => ! in_array($e['domain'], [$domain, 'any'], true)), $domain);
            $this->assertEmpty(array_filter(RouterFewShots::all($domain), fn ($f) => ! in_array($f['id'], array_merge(array_column(Catalogue::forRole('cgs_approver', $domain), 'id'), array_column(Catalogue::forRole('student', $domain), 'id')), true)), "{$domain}: a few-shot names an id outside its domain's catalogue");
        }

        $text = \App\Modules\Chloe\Assistant\GeminiModel::routerInstruction(Catalogue::forRole('cgs', 'candidacy'), RouterFewShots::all('candidacy'), 'today', []);
        $this->assertStringContainsString('Study Candidacy assistant', $text);
        $this->assertStringNotContainsString('locker-key assistant', $text);
        $this->assertStringNotContainsString('release_forecast', $text);
        $ws = \App\Modules\Chloe\Assistant\GeminiModel::routerInstruction(Catalogue::forRole('cgs', 'workstation'), RouterFewShots::all('workstation'), 'today', []);
        $this->assertStringContainsString('workstation and locker-key assistant', $ws);
        $this->assertStringNotContainsString('cand_my_expiry', $ws);
    }

    public function test_an_entry_of_the_other_domain_is_never_answered_even_if_the_model_names_it(): void
    {
        $this->assertNotSame('my_workstation', $this->ask($this->student, 'candidacy', 'when does my loan end', 'my_workstation')['meta']['catalogue_id'] ?? null);
        $this->assertNotSame('cand_my_expiry', $this->ask($this->student, 'workstation', 'tell me something', 'cand_my_expiry')['meta']['catalogue_id'] ?? null);
        $this->assertNotSame('cand_cgs_expiring', $this->ask($this->cgsUser, 'workstation', 'tell me something', 'cand_cgs_expiring')['meta']['catalogue_id'] ?? null);
    }

    public function test_a_question_that_clearly_belongs_to_the_other_domain_is_redirected_without_a_model_call(): void
    {
        $cases = [
            ['workstation', 'student', 'how do i submit an appeal for more time', route('candidacy.status')],
            ['workstation', 'cgs', 'how many dismissal candidates are there', route('candidacy.cgs.operations')],
            ['candidacy', 'student', 'how do i request a locker key', route('workstation.select')],
            ['candidacy', 'cgs', 'which seat is free in the n2 block', route('workstation.cgs.operations')],
            ['candidacy', 'approver', 'how do i return my locker key', route('dashboard')],
        ];
        $users = ['student' => $this->student, 'cgs' => $this->cgsUser, 'approver' => $this->lecturer];

        foreach ($cases as [$domain, $role, $question, $link]) {
            $calls = FakeModel::routeCallCount();
            $result = $this->ask($users[$role], $domain, $question);

            $this->assertSame($calls, FakeModel::routeCallCount(), "{$domain}/{$role}: no model call");
            $this->assertSame(config("assistant.domains.{$domain}.redirect"), $result['answer']);
            $this->assertSame($link, $result['links'][0]['url'], "{$domain}/{$role}");
            $this->assertSame('refused', $result['outcome']);
            $this->assertNotEmpty($result['suggestions'], 'the CURRENT domain\'s chips are shown');
            $this->assertSame($domain, AssistantLog::latest('id')->first()->domain);
            $this->assertSame('wrong_domain', AssistantLog::latest('id')->first()->lookup);
        }

        // the chips are the current domain's, not the other's
        $ws = $this->ask($this->student, 'workstation', 'how do i submit an appeal');
        $this->assertContains('What are the workstation rules I must follow?', array_column($ws['suggestions'], 'text'));
        $cand = $this->ask($this->student, 'candidacy', 'how do i request a locker key');
        $this->assertContains('When does my candidacy expire?', array_column($cand['suggestions'], 'text'));
    }

    public function test_words_that_exist_in_both_worlds_never_redirect_by_themselves(): void
    {
        foreach (['workstation', 'candidacy'] as $domain) {
            foreach (['when does my expiry date end', 'what programme am i in', 'is the cgs office open', 'how many students are there'] as $question) {
                $calls = FakeModel::routeCallCount();
                $this->ask($this->student, $domain, $question);
                $this->assertSame($calls + 1, FakeModel::routeCallCount(), "{$domain}: '{$question}' must reach the model of its own domain");
            }
        }

        // a question with strong terms of the CURRENT domain is routed normally even if it also names the other's
        $calls = FakeModel::routeCallCount();
        $this->ask($this->cgsUser, 'workstation', 'how many seat holders have a candidacy expiring');
        $this->assertSame($calls + 1, FakeModel::routeCallCount());

        \Illuminate\Support\Facades\Cache::flush(); // the model budget (12 a minute) counts every call above

        // "candidacy expire" on a Workstation page goes to the Workstation catalogue; if it has no entry the redirect follows
        $calls = FakeModel::routeCallCount();
        $result = $this->ask($this->student, 'workstation', 'when does my candidacy expire', 'out_of_scope');
        $this->assertSame($calls + 1, FakeModel::routeCallCount());
        $this->assertSame(config('assistant.domains.workstation.redirect'), $result['answer']);
    }

    public function test_a_request_cannot_claim_a_domain_its_page_does_not_belong_to(): void
    {
        $ok = $this->claim($this->student, 'candidacy', 'candidacy.status');
        $this->actingAs($this->student)->postJson(route('workstation.assistant.ask'), ['message' => 'when does my candidacy expire'] + $ok)->assertOk();

        // a Workstation page's token cannot be used to talk to the candidacy domain, and the other way round
        $this->actingAs($this->student)->postJson(route('workstation.assistant.ask'), ['message' => 'x'] + ['domain' => 'candidacy', 'page' => 'workstation.select', 'token' => D::token('candidacy', $this->student->id, 'workstation.select')])->assertForbidden();
        $this->actingAs($this->student)->postJson(route('workstation.assistant.ask'), ['message' => 'x'] + ['domain' => 'workstation', 'page' => 'candidacy.status', 'token' => D::token('workstation', $this->student->id, 'candidacy.status')])->assertForbidden();
        // a token issued to another user, a forged token, no token, an unmapped page, an unknown domain
        $this->actingAs($this->student)->postJson(route('workstation.assistant.ask'), ['message' => 'x'] + $this->claim($this->lecturer, 'candidacy', 'candidacy.status'))->assertForbidden();
        $this->actingAs($this->student)->postJson(route('workstation.assistant.ask'), ['message' => 'x', 'domain' => 'candidacy', 'page' => 'candidacy.status', 'token' => 'forged'])->assertForbidden();
        $this->actingAs($this->student)->postJson(route('workstation.assistant.ask'), ['message' => 'x', 'domain' => 'candidacy', 'page' => 'candidacy.status'])->assertForbidden();
        $this->actingAs($this->student)->postJson(route('workstation.assistant.ask'), ['message' => 'x'] + $this->claim($this->student, 'candidacy', 'login'))->assertForbidden();
        $this->actingAs($this->student)->postJson(route('workstation.assistant.ask'), ['message' => 'x'] + $this->claim($this->student, 'finance', 'candidacy.status'))->assertForbidden();
        // an approver has no Workstation, even with a token for a Workstation page
        $this->actingAs($this->lecturer)->postJson(route('workstation.assistant.ask'), ['message' => 'x'] + $this->claim($this->lecturer, 'workstation', 'workstation.select'))->assertForbidden();
        $this->actingAs($this->lecturer)->getJson(route('workstation.assistant.suggestions', $this->claim($this->lecturer, 'candidacy', 'candidacy.portal.queue')))->assertOk();
        // the suggested-question endpoint and the suggestions endpoint check the same way
        $this->actingAs($this->student)->postJson(route('workstation.assistant.suggestion'), ['id' => 'cand_my_expiry', 'domain' => 'candidacy', 'page' => 'workstation.select', 'token' => 'x'])->assertForbidden();
        $this->actingAs($this->student)->getJson(route('workstation.assistant.suggestions', ['domain' => 'candidacy', 'page' => 'workstation.select', 'token' => 'x']))->assertForbidden();
    }

    public function test_a_chip_of_one_domain_cannot_be_clicked_in_the_other(): void
    {
        $ws = $this->claim($this->student, 'workstation', 'workstation.select');
        $this->actingAs($this->student)->postJson(route('workstation.assistant.suggestion'), ['id' => 'cand_my_expiry'] + $ws)->assertOk()->assertJsonPath('outcome', 'error');
        $cand = $this->claim($this->student, 'candidacy', 'candidacy.status');
        $this->actingAs($this->student)->postJson(route('workstation.assistant.suggestion'), ['id' => 'my_workstation'] + $cand)->assertOk()->assertJsonPath('outcome', 'error');
        $this->actingAs($this->student)->postJson(route('workstation.assistant.suggestion'), ['id' => 's1_rules'] + $cand)->assertOk()->assertJsonPath('outcome', 'error');
    }

    public function test_every_page_is_assigned_to_a_domain_in_one_place_and_the_widget_renders_its_own_title_and_placeholder(): void
    {
        $this->assertSame('workstation', D::forPage('workstation.cgs.operations'));
        $this->assertSame('workstation', D::forPage('workstation.select'));
        foreach (['candidacy.status', 'candidacy.portal.queue', 'candidacy.cgs.operations', 'candidacy.cgs.insights', 'candidacy.cgs.dismissals', 'candidacy.cgs.dismissal-upload', 'candidacy.signature.show', 'candidacy.appeals.new'] as $page) {
            $this->assertSame('candidacy', D::forPage($page), $page);
        }
        $this->assertNull(D::forPage('workstation.assistant.ask'), 'the assistant endpoints belong to no domain');
        $this->assertNull(D::forPage('login'));

        $ws = $this->actingAs($this->student)->get(route('workstation.select'))->getContent();
        $this->assertStringContainsString('data-domain="workstation"', $ws);
        $this->assertStringContainsString('Workstation Assistant', $ws);
        $this->assertStringContainsString('Ask about workstations or locker keys', $ws);
        $this->assertStringContainsString('data-token="'.D::token('workstation', $this->student->id, 'workstation.select').'"', $ws);

        $cand = $this->actingAs($this->student)->get(route('candidacy.status'))->getContent();
        $this->assertStringContainsString('data-domain="candidacy"', $cand);
        $this->assertStringContainsString('Study Candidacy Assistant', $cand);
        $this->assertStringContainsString('Ask about your candidacy or appeals', $cand);
        $this->assertStringContainsString("sessionStorage.removeItem('wsaGreetingShown:'", $cand);
    }
}
