<?php

namespace App\Modules\Chloe\Assistant;

/**
 * PART 9 / PART B / Round 3 PART A: every automated test's provider --
 * selected by ASSISTANT_PROVIDER=fake (see phpunit.xml/.env.testing), so no
 * test ever reaches the real Gemini API. Deterministic and queue-driven,
 * the same shape as Illuminate\Support\Facades\Notification::fake(): a
 * test queues exactly the response (or failure) it wants with
 * queueAnswer()/queueFailure()/queueAggregate()/forceUnavailable(), then
 * asserts on Pipeline's behaviour around it -- including the retry/
 * fallback/circuit-breaker orchestration in Pipeline::
 * callModelWithReliability(), which calls answer() more than once per
 * question exactly like the real provider would.
 *
 * Static state, not instance state: the container resolves AssistantModel
 * once per request/test, but a test configures the queue before that
 * resolution happens, so it has to survive across the binding. reset() is
 * called from TestCase::setUp() (see tests/Support/MakesFakeAssistant.php),
 * never left to carry over between tests.
 */
class FakeModel implements AssistantModel
{
    /**
     * Queued answer() outcomes, consumed in order -- each entry either a
     * success override (kind/answer/sources/lookup/params) or a failure
     * marker from queueFailure(), so a test can interleave the two (e.g.
     * queueFailure('503') then queueAnswer([...]) to simulate "503 then
     * success on retry").
     *
     * @var array<int, array<string, mixed>>
     */
    protected static array $answerQueue = [];

    /** @var array<int, array{text: ?string, error_code: ?string}> queued composeAggregate() outcomes. */
    protected static array $aggregateQueue = [];

    protected static bool $unavailable = false;

    protected static ?string $unavailableErrorCode = null;

    /**
     * Every answer() call this test has made, payload and all -- for
     * PART B.5's privacy tests, which assert on exactly what reached
     * "the model" (no name/email/ID anywhere in $knowledgeText or
     * $lookupCatalogue, only the masked question and recent questions),
     * and for PART C.1's topic-hint tests (topicHints must be topic ids
     * only). $model records which model name Pipeline's reliability
     * orchestrator actually requested for this attempt (primary or
     * fallback), so a retry/fallback test can assert on it directly.
     *
     * @var array<int, array{maskedQuestion: string, recentMaskedQuestions: array<int, string>, knowledgeText: string, lookupCatalogue: array, model: string, timeoutSeconds: int, topicHints: array<int, string>}>
     */
    protected static array $answerCalls = [];

    /** @var array<int, array{maskedQuestion: string, lookupName: string, result: array, model: string, timeoutSeconds: int}> */
    protected static array $aggregateCalls = [];

    public static function reset(): void
    {
        self::$answerQueue = [];
        self::$aggregateQueue = [];
        self::$unavailable = false;
        self::$unavailableErrorCode = null;
        self::$answerCalls = [];
        self::$aggregateCalls = [];
    }

    /** @return array<int, array{maskedQuestion: string, recentMaskedQuestions: array<int, string>, knowledgeText: string, lookupCatalogue: array, model: string, timeoutSeconds: int, topicHints: array<int, string>}> */
    public static function answerCalls(): array
    {
        return self::$answerCalls;
    }

    /** @return array<int, array{maskedQuestion: string, lookupName: string, result: array, model: string, timeoutSeconds: int}> */
    public static function aggregateCalls(): array
    {
        return self::$aggregateCalls;
    }

    public static function aggregateCallCount(): int
    {
        return count(self::$aggregateCalls);
    }

    /**
     * @param  array<string, mixed>  $overrides  any of kind/answer/sources/lookup/params -- unset keys get a sane default (kind: 'out_of_scope', empty sources/params).
     */
    public static function queueAnswer(array $overrides): void
    {
        self::$answerQueue[] = ['__failure' => false] + $overrides + ['kind' => 'out_of_scope', 'answer' => null, 'sources' => [], 'lookup' => null, 'params' => []];
    }

    /** Round 3 PART A: queues ONE failed answer() attempt with the given error code ("500"|"503"|"504"|"429"|"timeout"), consumed in order alongside queueAnswer() -- lets a test simulate "503 then success", "two 503s", "429 (no retry, straight to fallback)", etc. */
    public static function queueFailure(string $errorCode): void
    {
        self::$answerQueue[] = ['__failure' => true, 'error_code' => $errorCode];
    }

    public static function queueAggregate(?string $text, ?string $errorCode = null): void
    {
        self::$aggregateQueue[] = ['text' => $text, 'error_code' => $errorCode];
    }

    /** Blanket, unconditional failure for every remaining call (both methods) -- for tests that don't care about retry/fallback detail. queueFailure() above is for sequencing a specific failure then recovery. */
    public static function forceUnavailable(bool $unavailable = true, string $errorCode = 'forced_unavailable'): void
    {
        self::$unavailable = $unavailable;
        self::$unavailableErrorCode = $errorCode;
    }

    public function answer(string $maskedQuestion, array $recentMaskedQuestions, string $knowledgeText, array $lookupCatalogue, string $model, int $timeoutSeconds, array $topicHints = [], bool $preferCgsTopics = false, bool $isCgsRole = false): array
    {
        self::$answerCalls[] = [
            'maskedQuestion' => $maskedQuestion,
            'recentMaskedQuestions' => $recentMaskedQuestions,
            'knowledgeText' => $knowledgeText,
            'lookupCatalogue' => $lookupCatalogue,
            'model' => $model,
            'timeoutSeconds' => $timeoutSeconds,
            'topicHints' => $topicHints,
            'preferCgsTopics' => $preferCgsTopics,
            'isCgsRole' => $isCgsRole,
        ];

        if (self::$unavailable) {
            return ['kind' => null, 'answer' => null, 'sources' => [], 'lookup' => null, 'params' => [], 'outcome' => 'unavailable', 'error_code' => self::$unavailableErrorCode];
        }

        if (self::$answerQueue !== []) {
            $next = array_shift(self::$answerQueue);

            if ($next['__failure']) {
                return ['kind' => null, 'answer' => null, 'sources' => [], 'lookup' => null, 'params' => [], 'outcome' => 'unavailable', 'error_code' => $next['error_code']];
            }

            unset($next['__failure']);

            return [...$next, 'outcome' => 'ok', 'error_code' => null];
        }

        // No queued response -- a deterministic, no-network default so a
        // test that only cares about privacy/plumbing doesn't have to
        // queue a full answer for every single question it sends: try to
        // match a lookup name mentioned in the question, else out_of_scope.
        foreach (array_keys($lookupCatalogue) as $name) {
            if (str_contains($maskedQuestion, str_replace('_', ' ', $name))) {
                return ['kind' => 'lookup', 'answer' => null, 'sources' => [], 'lookup' => $name, 'params' => [], 'outcome' => 'ok', 'error_code' => null];
            }
        }

        return ['kind' => 'out_of_scope', 'answer' => null, 'sources' => [], 'lookup' => null, 'params' => [], 'outcome' => 'ok', 'error_code' => null];
    }

    public function composeAggregate(string $maskedQuestion, string $lookupName, array $result, string $model, int $timeoutSeconds): array
    {
        self::$aggregateCalls[] = ['maskedQuestion' => $maskedQuestion, 'lookupName' => $lookupName, 'result' => $result, 'model' => $model, 'timeoutSeconds' => $timeoutSeconds];

        if (self::$unavailable) {
            return ['text' => null, 'outcome' => 'unavailable', 'error_code' => self::$unavailableErrorCode];
        }

        if (self::$aggregateQueue !== []) {
            $next = array_shift(self::$aggregateQueue);

            return $next['text'] === null
                ? ['text' => null, 'outcome' => 'unavailable', 'error_code' => $next['error_code'] ?? 'forced_unavailable']
                : ['text' => $next['text'], 'outcome' => 'ok', 'error_code' => null];
        }

        return ['text' => null, 'outcome' => 'ok', 'error_code' => null];
    }
}
