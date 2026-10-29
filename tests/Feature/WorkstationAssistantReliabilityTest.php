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
 * per-model-budget logic, all driven through FakeModel's queueFailure()/
 * queueAnswer() queue -- Pipeline::callModelWithReliability() calls
 * answer() more than once per question exactly like the real provider
 * would, so these exercise the SAME code path a real 503/429/timeout
 * would hit, with no real network call anywhere.
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

    public function test_503_then_success_on_the_primary_model_never_touches_the_fallback(): void
    {
        $cgs = $this->cgs();

        FakeModel::queueFailure('503');
        FakeModel::queueAnswer(['kind' => 'out_of_scope']);

        $result = $this->pipeline()->ask($cgs, 'a totally unrelated question zzz', []);

        $this->assertSame('refused', $result['outcome']);
        $this->assertCount(2, FakeModel::answerCalls());
        $this->assertSame('gemini-3.5-flash-lite', FakeModel::answerCalls()[0]['model']);
        $this->assertSame('gemini-3.5-flash-lite', FakeModel::answerCalls()[1]['model']);

        $log = AssistantLog::latest('occurred_at')->first();
        $this->assertSame('gemini-3.5-flash-lite', $log->model_used);
        $this->assertNull($log->error_code);
    }

    /** config('assistant.retry_waits_seconds') is [2, 5] -- 1 initial + 2 retries = 3 total PRIMARY attempts before the fallback is ever tried. */
    public function test_three_503s_on_the_primary_then_the_fallback_succeeds(): void
    {
        $cgs = $this->cgs();

        FakeModel::queueFailure('503');
        FakeModel::queueFailure('503');
        FakeModel::queueFailure('503');
        FakeModel::queueAnswer(['kind' => 'out_of_scope']);

        $result = $this->pipeline()->ask($cgs, 'a totally unrelated question zzz', []);

        $this->assertSame('refused', $result['outcome']);
        $calls = FakeModel::answerCalls();
        $this->assertCount(4, $calls);
        $this->assertSame('gemini-3.5-flash-lite', $calls[0]['model']);
        $this->assertSame('gemini-3.5-flash-lite', $calls[1]['model']);
        $this->assertSame('gemini-3.5-flash-lite', $calls[2]['model']);
        $this->assertSame('gemini-3.1-flash-lite', $calls[3]['model']);

        $log = AssistantLog::latest('occurred_at')->first();
        $this->assertSame('gemini-3.1-flash-lite', $log->model_used);
    }

    public function test_503_on_primary_and_fallback_returns_the_busy_message(): void
    {
        $cgs = $this->cgs();

        FakeModel::queueFailure('503');
        FakeModel::queueFailure('503');
        FakeModel::queueFailure('503');
        FakeModel::queueFailure('503');

        $result = $this->pipeline()->ask($cgs, 'a totally unrelated question zzz', []);

        $this->assertSame('unavailable', $result['outcome']);
        $this->assertSame(Pipeline::UNAVAILABLE_MESSAGE, $result['answer']);

        $log = AssistantLog::latest('occurred_at')->first();
        $this->assertNull($log->model_used);
        $this->assertSame('503', $log->error_code);
    }

    /** PART A.1: a 429 is never retried on the SAME model -- it goes straight to the fallback, with no second primary attempt in between. */
    public function test_a_429_skips_the_retry_and_goes_straight_to_the_fallback(): void
    {
        $cgs = $this->cgs();

        FakeModel::queueFailure('429');
        FakeModel::queueAnswer(['kind' => 'out_of_scope']);

        $result = $this->pipeline()->ask($cgs, 'a totally unrelated question zzz', []);

        $this->assertSame('refused', $result['outcome']);
        $calls = FakeModel::answerCalls();
        $this->assertCount(2, $calls, 'A 429 must not be retried on the primary model.');
        $this->assertSame('gemini-3.5-flash-lite', $calls[0]['model']);
        $this->assertSame('gemini-3.1-flash-lite', $calls[1]['model']);
    }

    /** assistant:evaluate/assistant:check call ask() with $allowFallback=false by default -- confirms the primary's own failure is never covered up by a silent fallback switch when measuring the primary alone. */
    public function test_allow_fallback_false_never_tries_the_fallback_model(): void
    {
        $cgs = $this->cgs();

        FakeModel::queueFailure('503');
        FakeModel::queueFailure('503');
        FakeModel::queueFailure('503');

        $result = $this->pipeline()->ask($cgs, 'a totally unrelated question zzz', [], false, false);

        $this->assertSame('unavailable', $result['outcome']);
        $calls = FakeModel::answerCalls();
        foreach ($calls as $call) {
            $this->assertSame('gemini-3.5-flash-lite', $call['model']);
        }

        $log = AssistantLog::latest('occurred_at')->first();
        $this->assertNull($log->model_used);
    }

    /**
     * PART A.3: config('assistant.circuit_breaker.consecutive_failures_
     * to_open') is 3, counted per FAILED ATTEMPT on the primary -- so
     * one request whose 3 retries all fail already trips it (the
     * literal reading of "fails 3 times in a row"), without needing 3
     * separate user questions. The very next request then skips the
     * primary entirely and goes straight to one fallback attempt.
     */
    public function test_three_consecutive_primary_failures_open_the_circuit_breaker(): void
    {
        $cgs = $this->cgs();

        FakeModel::queueFailure('503');
        FakeModel::queueFailure('503');
        FakeModel::queueFailure('503');
        FakeModel::queueFailure('503'); // the one fallback attempt for this same request
        $this->pipeline()->ask($cgs, 'a totally unrelated question zzz', []);

        FakeModel::queueAnswer(['kind' => 'out_of_scope']);
        $before = count(FakeModel::answerCalls());

        $this->pipeline()->ask($cgs, 'one more unrelated question zzz', []);

        $callsThisRequest = array_slice(FakeModel::answerCalls(), $before);
        $this->assertCount(1, $callsThisRequest, 'The open circuit should skip the primary and go straight to one fallback attempt.');
        $this->assertSame('gemini-3.1-flash-lite', $callsThisRequest[0]['model']);
    }

    /** A primary SUCCESS resets its failure count -- two failed attempts (not all three) followed by a success on the same request must NOT open the circuit for the next request. */
    public function test_a_primary_success_resets_the_consecutive_failure_count(): void
    {
        $cgs = $this->cgs();

        FakeModel::queueFailure('503');
        FakeModel::queueFailure('503');
        FakeModel::queueAnswer(['kind' => 'out_of_scope']); // succeeds on the primary's own 3rd attempt
        $this->pipeline()->ask($cgs, 'a totally unrelated question zzz', []);

        FakeModel::queueAnswer(['kind' => 'out_of_scope']);
        $before = count(FakeModel::answerCalls());
        $this->pipeline()->ask($cgs, 'one more unrelated question zzz', []);

        $callsThisRequest = array_slice(FakeModel::answerCalls(), $before);
        $this->assertSame('gemini-3.5-flash-lite', $callsThisRequest[0]['model'], 'The circuit should still be closed -- the success on the 3rd attempt reset the failure count.');
    }

    /** PART A.2: quotas are per model -- exhausting the primary's own minute budget must not block the fallback. */
    public function test_per_model_budget_is_independent(): void
    {
        $cgs = $this->cgs();
        $this->setModelRateLimit('gemini-3.5-flash-lite', 'requests_per_minute', 1);

        FakeModel::queueAnswer(['kind' => 'out_of_scope']);
        $this->pipeline()->ask($cgs, 'first question to use up the primary budget xyz', []);

        // Primary's per-minute budget is now spent; the fallback's own counter is untouched.
        FakeModel::queueAnswer(['kind' => 'out_of_scope']);
        $result = $this->pipeline()->ask($cgs, 'second question xyz', []);

        $this->assertSame('refused', $result['outcome']);
        $log = AssistantLog::latest('occurred_at')->first();
        $this->assertSame('gemini-3.1-flash-lite', $log->model_used, 'The fallback has its own budget, independent of the primary.');
    }

    /** Round 4 PART A.3: the app's own real AI Studio numbers, held at config/assistant.php's own ~80% margin -- a model not explicitly listed falls back to the conservative 'default' bucket, never an unlimited budget. */
    public function test_the_real_per_model_rate_limits_differ_from_the_shared_default(): void
    {
        $limits = config('assistant.rate_limits', []);

        $this->assertSame(12, (int) ($limits['gemini-3.5-flash-lite']['requests_per_minute'] ?? null));
        $this->assertSame(400, (int) ($limits['gemini-3.5-flash-lite']['requests_per_day'] ?? null));
        $this->assertSame(4, (int) ($limits['gemini-3.8-flash']['requests_per_minute'] ?? null));
        $this->assertSame(15, (int) ($limits['gemini-3.8-flash']['requests_per_day'] ?? null));
        $this->assertSame(5, (int) ($limits['default']['requests_per_minute'] ?? null));
        $this->assertSame(100, (int) ($limits['default']['requests_per_day'] ?? null));
        // Note: this class's own setUp() overrides assistant.fallback_model to a fake test name, so the real default ('gemini-3.8-flash') is checked in config/assistant.php's own test instead, not here.
    }

    /** Round 4 PART A.4: the DAY cache key is built in America/Los_Angeles, not server-local time -- a model's daily budget frees up at LA midnight even while the server's own calendar date has not changed yet. */
    public function test_the_daily_budget_resets_at_los_angeles_midnight_not_server_midnight(): void
    {
        $cgs = $this->cgs();
        $this->setModelRateLimit('gemini-3.5-flash-lite', 'requests_per_day', 1);

        // Carbon::setTestNow() parses a bare string in the APP's own timezone (Asia/Kuala_Lumpur, UTC+8 -- found live writing this very test) -- an explicit UTC instant keeps the LA-boundary math unambiguous regardless of app.timezone.
        // 03:00 UTC on 2 Jan is still 1 Jan in Los Angeles (UTC-8 in January -- no DST to complicate it).
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-01-02 03:00:00', 'UTC'));
        FakeModel::queueAnswer(['kind' => 'out_of_scope']);
        $this->pipeline()->ask($cgs, 'first question before la midnight xyz', []);

        // 05:00 UTC, same LA day still -- the primary's daily budget is still spent.
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-01-02 05:00:00', 'UTC'));
        FakeModel::queueAnswer(['kind' => 'out_of_scope']);
        $this->pipeline()->ask($cgs, 'second question still before la midnight xyz', []);

        // 09:00 UTC on 2 Jan is 01:00 on 2 Jan in Los Angeles -- a NEW LA day, so the primary's daily budget is free again even though the app's own (Kuala Lumpur) calendar date has not moved since the first call.
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-01-02 09:00:00', 'UTC'));
        FakeModel::queueAnswer(['kind' => 'out_of_scope']);
        $this->pipeline()->ask($cgs, 'third question after la midnight xyz', []);

        $primaryCalls = array_filter(FakeModel::answerCalls(), fn ($c) => $c['model'] === 'gemini-3.5-flash-lite');
        $this->assertCount(2, $primaryCalls, 'The primary should answer the 1st and 3rd questions -- the 2nd falls to the fallback since the LA day had not yet turned over.');
    }

    /** Round 4 PART A.3: a request whose estimated input size (characters / 4) would not fit the remaining per-minute token budget is treated as exhausted, same as the request-count check -- never a real call. */
    public function test_a_tiny_token_per_minute_budget_blocks_the_primary_before_any_real_call(): void
    {
        $cgs = $this->cgs();
        $this->setModelRateLimit('gemini-3.5-flash-lite', 'tokens_per_minute', 1);

        FakeModel::queueAnswer(['kind' => 'out_of_scope']);
        $this->pipeline()->ask($cgs, 'a normal sized question that still exceeds a 1 token budget xyz', []);

        $primaryCalls = array_filter(FakeModel::answerCalls(), fn ($c) => $c['model'] === 'gemini-3.5-flash-lite');
        $this->assertEmpty($primaryCalls, 'The primary model should never actually be called once its estimated request size exceeds the per-minute token budget.');
    }

    // -------------------------------------------------------------
    // PART C.1: topic hints
    // -------------------------------------------------------------

    public function test_a_keyword_rule_match_passes_topic_hints_to_the_model_instead_of_bypassing_it(): void
    {
        $student = $this->eligibleStudent();

        FakeModel::queueAnswer(['kind' => 'guidance', 'answer' => 'Report it to CGS immediately.', 'sources' => ['key_lost']]);

        $this->pipeline()->ask($student, 'I lost my locker key, what do I do?', []);

        $calls = FakeModel::answerCalls();
        $this->assertNotEmpty($calls);
        $this->assertSame(['key_lost'], $calls[0]['topicHints']);
    }

    /** Hints are topic ids only -- never personal data, same guarantee as the knowledge text and lookup catalogue. */
    public function test_topic_hints_never_contain_personal_data(): void
    {
        $student = $this->eligibleStudent();

        FakeModel::queueAnswer(['kind' => 'guidance', 'answer' => 'Report it to CGS immediately.', 'sources' => ['key_lost']]);

        $this->pipeline()->ask($student, 'I lost my locker key, what do I do?', []);

        $hints = FakeModel::answerCalls()[0]['topicHints'];

        foreach ($hints as $hint) {
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $hint);
        }
    }

    // -------------------------------------------------------------
    // Round 4 PART B.1: CGS topic counterparts
    // -------------------------------------------------------------

    /** "how do i collect my key" matches the STUDENT guidance phrase list -- for CGS, its counterpart (cgs_keys) must be hinted/preferred instead, never the student topic alone. */
    public function test_a_cgs_question_phrased_like_a_student_question_gets_the_cgs_counterpart_hint(): void
    {
        $cgs = $this->cgs();

        FakeModel::queueAnswer(['kind' => 'guidance', 'answer' => 'Mark the key as collected on the seat page.', 'sources' => ['cgs_keys']]);

        $this->pipeline()->ask($cgs, 'how do i collect my key', []);

        $calls = FakeModel::answerCalls();
        $this->assertNotEmpty($calls);
        $this->assertContains('cgs_keys', $calls[0]['topicHints']);
        $this->assertTrue($calls[0]['preferCgsTopics']);
    }

    /** The student topic itself is never student-only-filtered-then-lost -- the counterpart is ADDED alongside it, not a replacement, so the hint list carries both. */
    public function test_the_student_topic_is_kept_alongside_its_cgs_counterpart_in_the_hints(): void
    {
        $cgs = $this->cgs();

        FakeModel::queueAnswer(['kind' => 'guidance', 'answer' => 'Mark the key as collected on the seat page.', 'sources' => ['cgs_keys']]);

        $this->pipeline()->ask($cgs, 'how do i collect my key', []);

        $hints = FakeModel::answerCalls()[0]['topicHints'];
        $this->assertContains('locker_key_collect_return', $hints);
        $this->assertContains('cgs_keys', $hints);
    }

    /** A student asking the exact same thing never gets $preferCgsTopics or an unrelated CGS hint. */
    public function test_a_student_question_never_gets_preferCgsTopics_or_a_cgs_hint(): void
    {
        $student = $this->eligibleStudent();

        FakeModel::queueAnswer(['kind' => 'guidance', 'answer' => 'Collect your key from the CGS office.', 'sources' => ['locker_key_collect_return']]);

        $this->pipeline()->ask($student, 'how do i collect my key', []);

        $call = FakeModel::answerCalls()[0];
        $this->assertFalse($call['preferCgsTopics']);
        $this->assertSame(['locker_key_collect_return'], $call['topicHints']);
    }

    /**
     * CGS staff are allowed BOTH the student and CGS topic lists (they
     * support students too, see GuidanceLibrary::topicsForRole()) -- so
     * the counterpart is never "rescuing" an invalid source here, it is
     * ADDING the CGS-side topic's own link alongside the student-side
     * one the model cited, so CGS staff get the staff-facing link too
     * even when the model's own "sources" named only the student topic.
     */
    public function test_a_model_source_naming_only_the_student_topic_still_gets_the_cgs_counterparts_link_added(): void
    {
        $cgs = $this->cgs();

        FakeModel::queueAnswer(['kind' => 'guidance', 'answer' => 'Mark the key as collected on the seat page.', 'sources' => ['locker_key_collect_return']]);

        $result = $this->pipeline()->ask($cgs, 'how do i collect my key', []);

        $this->assertSame('answered', $result['outcome']);
        $labels = collect($result['links'])->pluck('label')->all();
        $this->assertContains('Workstation Management', $labels, 'cgs_keys own link, added by the counterpart map even though the model only cited the student topic.');
        $this->assertContains('Workstation page', $labels, 'the student topic the model actually cited is still kept, never replaced.');
    }

    // -------------------------------------------------------------
    // Round 5 PART A: CGS voice fix
    // -------------------------------------------------------------

    /** The CGS role always gets the voice instruction, regardless of topic hints -- a different thing from $preferCgsTopics (topic selection). */
    public function test_the_cgs_role_always_gets_isCgsRole_true_even_with_no_topic_hints(): void
    {
        $cgs = $this->cgs();

        FakeModel::queueAnswer(['kind' => 'out_of_scope']);
        $this->pipeline()->ask($cgs, 'something with no keyword match at all zzz', []);

        $call = FakeModel::answerCalls()[0];
        $this->assertTrue($call['isCgsRole']);
    }

    /** A student never gets the CGS voice instruction. */
    public function test_a_student_never_gets_isCgsRole(): void
    {
        $student = $this->eligibleStudent();

        FakeModel::queueAnswer(['kind' => 'out_of_scope']);
        $this->pipeline()->ask($student, 'something with no keyword match at all zzz', []);

        $call = FakeModel::answerCalls()[0];
        $this->assertFalse($call['isCgsRole']);
    }
}
