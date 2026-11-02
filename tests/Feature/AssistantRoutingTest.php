<?php

namespace Tests\Feature;

use App\Modules\Chloe\Assistant\FakeModel;
use App\Modules\Chloe\Assistant\Pipeline;
use App\Modules\Chloe\Models\AssistantLog;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesFakeAssistant;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** Model first: the route written to the log, one retry, the daily reserve, the per-user limit, the routing cache and the fallback notice. Stubbed model, array cache. */
class AssistantRoutingTest extends TestCase
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
        config(['assistant.gemini.model' => 'test-primary', 'assistant.fallback_model' => 'test-fallback']);
        $this->candidacy->update(['candidacy_expiry_date' => '2027-03-01', 'original_expiry_date' => '2027-03-01']);
    }

    protected function pipeline(): Pipeline
    {
        return app(Pipeline::class)->setDomain('candidacy');
    }

    protected function lastRoute(): ?string
    {
        return AssistantLog::latest('id')->first()->route;
    }

    protected function dayKey(string $model): string
    {
        return "assistant:calls:day:{$model}:".now(config('assistant.day_timezone'))->format('Ymd');
    }

    public function test_every_kind_of_answer_logs_its_route_and_never_any_text(): void
    {
        $p = $this->pipeline();

        $p->ask($this->student, 'When does my candidacy expire?', []);
        $this->assertSame('static', $this->lastRoute());

        $p->ask($this->student, 'when is my rpd deadline', []);
        $this->assertSame('refused', $this->lastRoute());

        $p->ask($this->student, 'where is my locker key', []);
        $this->assertSame('refused', $this->lastRoute());

        FakeModel::queueRoute(['id' => 'cand_my_expiry']);
        $p->ask($this->student, 'my candidacy end date please', []);
        $this->assertSame('model', $this->lastRoute());

        FakeModel::forceUnavailable(true, 'stub');
        $p->ask($this->student, 'how many months extension have i left', []);
        $this->assertSame('fallback_error', $this->lastRoute());

        FakeModel::forceUnavailable(false);
        Cache::put($this->dayKey('test-primary'), 100000, 3600);
        Cache::put($this->dayKey('test-fallback'), 100000, 3600);
        $p->ask($this->student, 'what is the status of my appeal', []);
        $this->assertSame('fallback_budget', $this->lastRoute());

        $this->assertNotContains('text', \Schema::getColumnListing('assistant_logs'));
    }

    public function test_the_keyword_path_is_never_used_while_the_model_is_available(): void
    {
        foreach (['when does my candidacy end', 'how many months of extension are left', 'who has my appeal', 'can i apply for more time', 'what happens when my candidacy expires'] as $i => $q) {
            FakeModel::queueRoute(['id' => 'cand_my_expiry']);
            $before = FakeModel::routeCallCount();
            $r = $this->pipeline()->ask($this->student, $q, []);

            $this->assertSame($before + 1, FakeModel::routeCallCount(), "{$q} did not go to the model");
            $this->assertSame('model', $this->lastRoute());
            $this->assertArrayNotHasKey('notice', $r);
        }
    }

    public function test_one_retry_after_a_5xx_or_429_then_the_secondary_model_then_the_keyword_path(): void
    {
        FakeModel::queueRouteFailure('503');
        FakeModel::queueRoute(['id' => 'cand_my_expiry']);
        $this->pipeline()->ask($this->student, 'when does my candidacy end', []);
        $this->assertSame(['test-primary', 'test-primary'], array_column(FakeModel::routeCalls(), 'model'));

        FakeModel::reset();
        Cache::flush();
        FakeModel::queueRouteFailure('429');
        FakeModel::queueRouteFailure('429');
        FakeModel::queueRoute(['id' => 'cand_my_expiry']);
        $this->pipeline()->ask($this->student, 'when does my candidacy finish', []);
        $this->assertSame(['test-primary', 'test-primary', 'test-fallback'], array_column(FakeModel::routeCalls(), 'model'));

        FakeModel::reset();
        Cache::flush();
        foreach (range(1, 6) as $i) {
            FakeModel::queueRouteFailure('503');
        }
        $r = $this->pipeline()->ask($this->student, 'when does my candidacy end please', []);
        $this->assertSame('fallback_error', $this->lastRoute());
        $this->assertSame(Pipeline::FALLBACK_NOTICE, $r['notice']);
    }

    public function test_a_failed_request_that_never_reached_gemini_is_not_counted_and_a_retry_is_counted_once(): void
    {
        FakeModel::queueRouteFailure('no_connection');
        FakeModel::queueRouteFailure('no_connection');
        FakeModel::queueRouteFailure('no_connection');
        $this->pipeline()->ask($this->student, 'when does my candidacy end', []);
        $this->assertSame(0, (int) Cache::get($this->dayKey('test-primary'), 0));

        Cache::flush();
        FakeModel::reset();
        FakeModel::queueRouteFailure('503');
        FakeModel::queueRoute(['id' => 'cand_my_expiry']);
        $this->pipeline()->ask($this->student, 'when does my candidacy finish', []);
        $this->assertSame(2, (int) Cache::get($this->dayKey('test-primary'), 0), 'the first request and its retry are two requests, each counted once');
    }

    public function test_an_evaluation_run_leaves_the_daily_reserve_to_people(): void
    {
        config(['assistant.rate_limits' => ['test-primary' => ['requests_per_minute' => 1000, 'requests_per_day' => 100, 'tokens_per_minute' => 1000000], 'test-fallback' => ['requests_per_minute' => 1000, 'requests_per_day' => 100, 'tokens_per_minute' => 1000000], 'default' => ['requests_per_minute' => 1000, 'requests_per_day' => 100, 'tokens_per_minute' => 1000000]]]);
        Cache::put($this->dayKey('test-primary'), 45, 3600);
        Cache::put($this->dayKey('test-fallback'), 100, 3600);

        // reserve 60 of 100: an evaluation may use up to 40, people may use all 100
        FakeModel::queueRoute(['id' => 'cand_my_expiry']);
        $this->pipeline()->holdReserve(60)->ask($this->student, 'when does my candidacy end', []);
        $this->assertSame('fallback_budget', $this->lastRoute());

        FakeModel::queueRoute(['id' => 'cand_my_expiry']);
        $this->pipeline()->ask($this->student, 'when does my candidacy finish', []);
        $this->assertSame('model', $this->lastRoute());
    }

    public function test_a_user_cannot_drain_the_day_the_per_user_limit_applies(): void
    {
        $this->assertGreaterThan(0, (int) config('assistant.rate_limits.user_requests_per_minute'));
        $claim = ['domain' => 'candidacy', 'page' => 'candidacy.status', 'token' => \App\Modules\Chloe\Assistant\AssistantDomains::token('candidacy', $this->student->id, 'candidacy.status')];
        $limit = (int) config('assistant.rate_limits.user_requests_per_minute');

        for ($i = 0; $i < $limit; $i++) {
            $this->actingAs($this->student)->getJson(route('workstation.assistant.suggestions', $claim))->assertOk();
        }

        $this->actingAs($this->student)->getJson(route('workstation.assistant.suggestions', $claim))->assertStatus(429);
    }

    public function test_the_routing_is_cached_but_the_answer_is_built_fresh_and_a_hit_costs_nothing(): void
    {
        FakeModel::queueRoute(['id' => 'cand_my_expiry', 'confidence' => 0.9]);
        $first = $this->pipeline()->ask($this->student, 'my candidacy end date please 22001001', []);
        $this->assertSame('model', $this->lastRoute());
        $this->assertStringContainsString('1 Mar 2027', $first['answer']);
        $calls = FakeModel::routeCallCount();
        $used = (int) Cache::get($this->dayKey('test-primary'), 0);

        // the data changes; the same question is routed from the cache, answered from the new data
        $this->candidacy->update(['candidacy_expiry_date' => '2027-04-01']);
        $second = $this->pipeline()->ask($this->student, 'my candidacy end date please 22001001', []);

        $this->assertSame('cache', $this->lastRoute());
        $this->assertSame($calls, FakeModel::routeCallCount(), 'a cache hit must not call the model');
        $this->assertSame($used, (int) Cache::get($this->dayKey('test-primary'), 0), 'a cache hit must not count against the budget');
        $this->assertStringContainsString('1 Apr 2027', $second['answer']);

        // another role asking the same words is a different key
        $key = 'assistant:route:candidacy:student:'.sha1(\App\Modules\Chloe\Assistant\QuestionNormaliser::normalise(\App\Modules\Chloe\Assistant\PrivacyMasker::mask('my candidacy end date please 22001001')['masked']));
        $this->assertNotNull(Cache::get($key));
        $this->assertStringNotContainsString('22001001', json_encode(Cache::get($key)));
        $this->assertStringNotContainsString('22001001', $key);
    }

    public function test_the_fallback_notice_shows_only_for_keyword_answers(): void
    {
        FakeModel::queueRoute(['id' => 'cand_my_expiry']);
        $this->assertArrayNotHasKey('notice', $this->pipeline()->ask($this->student, 'when does my candidacy end', []));

        $this->assertArrayNotHasKey('notice', $this->pipeline()->ask($this->student, 'When does my candidacy expire?', []));
        $this->assertArrayNotHasKey('notice', $this->pipeline()->ask($this->student, 'when does my candidacy end', []));

        FakeModel::forceUnavailable(true, 'stub');
        $r = $this->pipeline()->ask($this->student, 'how many months extension have i got left', []);
        $this->assertSame(Pipeline::FALLBACK_NOTICE, $r['notice']);
        $this->assertStringContainsString('Smart understanding is temporarily unavailable', $r['notice']);
    }
}
