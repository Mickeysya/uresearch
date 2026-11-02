<?php

namespace Tests\Feature;

use App\Modules\Chloe\Assistant\FakeModel;
use App\Modules\Chloe\Assistant\Pipeline;
use App\Modules\Chloe\Models\AssistantLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\MakesEligibleWorkstationStudents;
use Tests\Support\MakesFakeAssistant;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Round 3 PART A/F.1: retry, fallback-model, circuit-breaker and
 * per-model-budget logic. Since the semantic router, the call these
 * guard first is the ROUTER call (Pipeline::callRouterWithReliability()),
 * driven here through FakeModel's queueRouteFailure()/queueRoute() queue
 * exactly like a real 503/429/timeout would hit it; the guidance compose
 * call reuses the same sequence (callModelWithReliability()). No real
 * network call anywhere.
 */
class WorkstationAssistantReliabilityTest extends TestCase
{
    use MakesEligibleWorkstationStudents;
    use MakesFakeAssistant;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFakeAssistant();

        // ASSISTANT_GEMINI_MODEL is deliberately unset in the test
        // environment (.env.testing) as a safety guard, so it has to be
        // set explicitly here, same as GeminiKeySafetyTest does -- this
        // never reaches the real provider either way, since
        // ASSISTANT_PROVIDER stays 'fake' throughout.
        config(['assistant.gemini.model' => 'gemini-3.5-flash-lite', 'assistant.fallback_model' => 'gemini-3.1-flash-lite']);
    }

    protected function tearDown(): void
    {
        // The LA-midnight test freezes Carbon's "now" -- never allowed to leak into a later test.
        \Illuminate\Support\Carbon::setTestNow();

        parent::tearDown();
    }

    protected function pipeline(): Pipeline
    {
        return app(Pipeline::class);
    }

    /**
     * Model names like "gemini-3.5-flash-lite" contain dots of their own,
     * so config(['assistant.rate_limits.gemini-3.5-flash-lite.x' => ...])
     * does NOT set the real per-model entry -- Laravel's dot notation
     * splits on every dot in the whole string, landing on a phantom
     * nested path instead (found live writing these very tests). Reading
     * the whole map, editing the one field with plain array access, then
     * writing the whole map back avoids the bug the same way Pipeline's
     * own modelLimits() now does.
     */
    protected function setModelRateLimit(string $model, string $field, int $value): void
    {
        $limits = config('assistant.rate_limits', []);
        $limits[$model][$field] = $value;
        config(['assistant.rate_limits' => $limits]);
    }

    protected function routeModels(): array
    {
        return array_column(FakeModel::routeCalls(), 'model');
    }

    public function test_503_then_success_on_the_primary_model_never_touches_the_fallback(): void
    {
        $cgs = $this->cgs();

        FakeModel::queueRouteFailure('503');
        FakeModel::queueRoute(['id' => 'out_of_scope']);

        $result = $this->pipeline()->ask($cgs, 'a totally unrelated question zzz', []);

        $this->assertSame('refused', $result['outcome']);
        $this->assertSame(['gemini-3.5-flash-lite', 'gemini-3.5-flash-lite'], $this->routeModels());

        $log = AssistantLog::latest('id')->first();
        $this->assertSame('gemini-3.5-flash-lite', $log->model_used);
        $this->assertSame(AssistantLog::ANSWERED_BY_MODEL_ROUTER, $log->answered_by);
        $this->assertNull($log->error_code);
    }

    /** config('assistant.retry_waits_seconds') is [1.5] -- 1 initial + 1 retry = 2 PRIMARY attempts before the fallback model is ever tried. */
    public function test_two_503s_on_the_primary_then_the_fallback_succeeds(): void
    {
        $cgs = $this->cgs();

        FakeModel::queueRouteFailure('503');
        FakeModel::queueRouteFailure('503');
        FakeModel::queueRoute(['id' => 'out_of_scope']);

        $result = $this->pipeline()->ask($cgs, 'a totally unrelated question zzz', []);

        $this->assertSame('refused', $result['outcome']);
        $this->assertSame(['gemini-3.5-flash-lite', 'gemini-3.5-flash-lite', 'gemini-3.1-flash-lite'], $this->routeModels());
        $this->assertSame('gemini-3.1-flash-lite', AssistantLog::latest('id')->first()->model_used);
    }

    /** Both models down: the deterministic fallback answers; with nothing it recognises, that is the busy message plus the suggested questions. */
    public function test_503_on_primary_and_fallback_returns_the_busy_message(): void
    {
        $cgs = $this->cgs();

        foreach (range(1, 4) as $i) {
            FakeModel::queueRouteFailure('503');
        }

        $result = $this->pipeline()->ask($cgs, 'a totally unrelated question zzz', []);

        $this->assertSame('unavailable', $result['outcome']);
        $this->assertSame(Pipeline::UNAVAILABLE_MESSAGE, $result['answer']);
        $this->assertNotEmpty($result['suggestions']);

        $log = AssistantLog::latest('id')->first();
        $this->assertNull($log->model_used);
        $this->assertSame('503', $log->error_code);
        $this->assertSame(AssistantLog::ANSWERED_BY_RULE_FALLBACK, $log->answered_by);
    }

    /** A 429 is retried once on the same model after a short backoff, then the fallback model. */
    public function test_a_429_is_retried_once_then_goes_to_the_fallback(): void
    {
        $cgs = $this->cgs();

        FakeModel::queueRouteFailure('429');
        FakeModel::queueRouteFailure('429');
        FakeModel::queueRoute(['id' => 'out_of_scope']);

        $result = $this->pipeline()->ask($cgs, 'a totally unrelated question zzz', []);

        $this->assertSame('refused', $result['outcome']);
        $this->assertSame(['gemini-3.5-flash-lite', 'gemini-3.5-flash-lite', 'gemini-3.1-flash-lite'], $this->routeModels(), 'A 429 is retried once on the primary, then the fallback.');
    }

    /** assistant:evaluate/assistant:check pass $allowFallback=false -- the primary's failure is never covered by a silent switch. */
    public function test_allow_fallback_false_never_tries_the_fallback_model(): void
    {
        $cgs = $this->cgs();

        foreach (range(1, 2) as $i) {
            FakeModel::queueRouteFailure('503');
        }

        $result = $this->pipeline()->ask($cgs, 'a totally unrelated question zzz', [], false, false);

        $this->assertSame('unavailable', $result['outcome']);
        $this->assertSame(['gemini-3.5-flash-lite'], array_values(array_unique($this->routeModels())));
        $this->assertNull(AssistantLog::latest('id')->first()->model_used);
    }

    /** Three failed primary attempts open the breaker; the next request skips the primary and makes one fallback attempt. */
    public function test_three_consecutive_primary_failures_open_the_circuit_breaker(): void
    {
        $cgs = $this->cgs();

        foreach (range(1, 8) as $i) {
            FakeModel::queueRouteFailure('503');
        }
        $this->pipeline()->ask($cgs, 'a totally unrelated question zzz', []);
        $this->pipeline()->ask($cgs, 'another unrelated question zzz', []);

        FakeModel::queueRoute(['id' => 'out_of_scope']);
        $before = FakeModel::routeCallCount();

        $this->pipeline()->ask($cgs, 'one more unrelated question zzz', []);

        $this->assertSame(['gemini-3.1-flash-lite'], array_slice($this->routeModels(), $before), 'The open circuit should skip the primary and go straight to one fallback attempt.');
    }

    public function test_a_primary_success_resets_the_consecutive_failure_count(): void
    {
        $cgs = $this->cgs();

        FakeModel::queueRouteFailure('503');
        FakeModel::queueRoute(['id' => 'out_of_scope']);
        $this->pipeline()->ask($cgs, 'a totally unrelated question zzz', []);

        FakeModel::queueRoute(['id' => 'out_of_scope']);
        $before = FakeModel::routeCallCount();
        $this->pipeline()->ask($cgs, 'one more unrelated question zzz', []);

        $this->assertSame('gemini-3.5-flash-lite', array_slice($this->routeModels(), $before)[0], 'The success on the 3rd attempt reset the failure count.');
    }

    /** PART A.2: quotas are per model -- exhausting the primary's minute budget must not block the fallback. */
    public function test_per_model_budget_is_independent(): void
    {
        $cgs = $this->cgs();
        $this->setModelRateLimit('gemini-3.5-flash-lite', 'requests_per_minute', 1);

        FakeModel::queueRoute(['id' => 'out_of_scope']);
        $this->pipeline()->ask($cgs, 'first question to use up the primary budget xyz', []);

        FakeModel::queueRoute(['id' => 'out_of_scope']);
        $result = $this->pipeline()->ask($cgs, 'second question xyz', []);

        $this->assertSame('refused', $result['outcome']);
        $this->assertSame('gemini-3.1-flash-lite', AssistantLog::latest('id')->first()->model_used, 'The fallback has its own budget, independent of the primary.');
    }

    /** Round 4 PART A.3: the app's own real numbers; an unlisted model gets the conservative 'default' bucket, never unlimited. */
    public function test_the_real_per_model_rate_limits_differ_from_the_shared_default(): void
    {
        $limits = config('assistant.rate_limits', []);

        $this->assertSame(12, (int) ($limits['gemini-3.5-flash-lite']['requests_per_minute'] ?? null));
        $this->assertSame(400, (int) ($limits['gemini-3.5-flash-lite']['requests_per_day'] ?? null));
        $this->assertSame(4, (int) ($limits['gemini-3.8-flash']['requests_per_minute'] ?? null));
        $this->assertSame(15, (int) ($limits['gemini-3.8-flash']['requests_per_day'] ?? null));
        $this->assertSame(5, (int) ($limits['default']['requests_per_minute'] ?? null));
        $this->assertSame(100, (int) ($limits['default']['requests_per_day'] ?? null));
    }

    /** Round 4 PART A.4: the DAY cache key is built in America/Los_Angeles, not server-local time. */
    public function test_the_daily_budget_resets_at_los_angeles_midnight_not_server_midnight(): void
    {
        $cgs = $this->cgs();
        $this->setModelRateLimit('gemini-3.5-flash-lite', 'requests_per_day', 1);

        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-01-02 03:00:00', 'UTC'));
        FakeModel::queueRoute(['id' => 'out_of_scope']);
        $this->pipeline()->ask($cgs, 'first question before la midnight xyz', []);

        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-01-02 05:00:00', 'UTC'));
        FakeModel::queueRoute(['id' => 'out_of_scope']);
        $this->pipeline()->ask($cgs, 'second question still before la midnight xyz', []);

        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-01-02 09:00:00', 'UTC'));
        FakeModel::queueRoute(['id' => 'out_of_scope']);
        $this->pipeline()->ask($cgs, 'third question after la midnight xyz', []);

        $primary = array_filter($this->routeModels(), fn ($m) => $m === 'gemini-3.5-flash-lite');
        $this->assertCount(2, $primary, 'The primary answers the 1st and 3rd questions -- the 2nd falls to the fallback model.');
    }

    /** Round 4 PART A.3: a request that would not fit the per-minute token budget is never sent. */
    public function test_a_tiny_token_per_minute_budget_blocks_the_primary_before_any_real_call(): void
    {
        $cgs = $this->cgs();
        $this->setModelRateLimit('gemini-3.5-flash-lite', 'tokens_per_minute', 1);

        FakeModel::queueRoute(['id' => 'out_of_scope']);
        $this->pipeline()->ask($cgs, 'a normal sized question that still exceeds a 1 token budget xyz', []);

        $this->assertNotContains('gemini-3.5-flash-lite', $this->routeModels());
    }

    /** At most 1 router call per typed question, plus at most 1 compose call. */
    public function test_one_router_call_and_at_most_one_compose_call_per_question(): void
    {
        $student = $this->eligibleStudent();

        FakeModel::queueRoute(['id' => 'key_lost']);
        FakeModel::queueAnswer(['kind' => 'guidance', 'answer' => 'Report it to CGS immediately.']);

        $this->pipeline()->ask($student, 'i lost my locker key, what do I do?', []);

        $this->assertSame(1, FakeModel::routeCallCount());
        $this->assertCount(1, FakeModel::answerCalls());
        $this->assertSame(0, FakeModel::aggregateCallCount());
    }

    // -------------------------------------------------------------
    // The guidance compose call: only the chosen topic, topic ids only
    // -------------------------------------------------------------

    public function test_the_compose_call_is_given_only_the_routed_topic(): void
    {
        $student = $this->eligibleStudent();

        FakeModel::queueRoute(['id' => 'key_lost']);
        FakeModel::queueAnswer(['kind' => 'guidance', 'answer' => 'Report it to CGS immediately.']);

        $result = $this->pipeline()->ask($student, 'I lost my locker key, what do I do?', []);

        $call = FakeModel::answerCalls()[0];
        $this->assertSame(['key_lost'], $call['topicHints']);
        $this->assertSame([], $call['lookupCatalogue']);
        $this->assertStringStartsWith('### key_lost', $call['knowledgeText']);
        $this->assertStringNotContainsString('### ', substr($call['knowledgeText'], 4), 'No second topic is included.');
        $this->assertSame('Report it to CGS immediately.', $result['answer']);
    }

    public function test_a_failed_compose_call_returns_the_topic_text(): void
    {
        $student = $this->eligibleStudent();

        FakeModel::queueRoute(['id' => 'key_lost']);
        foreach (range(1, 4) as $i) {
            FakeModel::queueFailure('503');
        }

        $result = $this->pipeline()->ask($student, 'I lost my locker key, what do I do?', []);

        $this->assertSame('answered', $result['outcome']);
        $this->assertStringContainsString('report it to the Centre for Graduate Studies', $result['answer']);
        $this->assertSame(AssistantLog::ANSWERED_BY_MODEL_ROUTER, AssistantLog::latest('id')->first()->answered_by);
    }

    // -------------------------------------------------------------
    // Round 4 PART B.1: CGS topic counterparts (now in the fallback)
    // -------------------------------------------------------------

    /** Router down: "how do i collect my key" from CGS falls back to the CGS counterpart, cgs_keys, not the student topic. */
    public function test_a_cgs_question_phrased_like_a_student_question_falls_back_to_the_cgs_counterpart(): void
    {
        $cgs = $this->cgs();
        FakeModel::forceUnavailable();

        $result = $this->pipeline()->ask($cgs, 'how do i collect my key', []);

        $this->assertSame('answered', $result['outcome']);
        $this->assertSame('guidance:cgs_keys', AssistantLog::latest('id')->first()->lookup);
    }

    public function test_a_student_question_never_falls_back_to_a_cgs_topic(): void
    {
        $student = $this->eligibleStudent();
        FakeModel::forceUnavailable();

        $this->pipeline()->ask($student, 'how do i collect my key', []);

        $this->assertSame('guidance:locker_key_collect_return', AssistantLog::latest('id')->first()->lookup);
    }

    // -------------------------------------------------------------
    // Round 5 PART A: CGS voice on the compose call
    // -------------------------------------------------------------

    public function test_the_cgs_role_always_gets_isCgsRole_true_on_the_compose_call(): void
    {
        $cgs = $this->cgs();

        FakeModel::queueRoute(['id' => 'cgs_keys']);
        FakeModel::queueAnswer(['kind' => 'guidance', 'answer' => 'Open the seat and use Mark as Collected.']);
        $this->pipeline()->ask($cgs, 'how do i tick a key as taken', []);

        $this->assertTrue(FakeModel::answerCalls()[0]['isCgsRole']);
    }

    public function test_a_student_never_gets_isCgsRole(): void
    {
        $student = $this->eligibleStudent();

        FakeModel::queueRoute(['id' => 'locker_key_collect_return']);
        FakeModel::queueAnswer(['kind' => 'guidance', 'answer' => 'Collect it at CGS.']);
        $this->pipeline()->ask($student, 'how do i collect my key', []);

        $this->assertFalse(FakeModel::answerCalls()[0]['isCgsRole']);
    }
}
