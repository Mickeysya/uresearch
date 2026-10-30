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

    /** @var array<int, array<string, mixed>> queued route() outcomes, consumed in order -- same success-override/queueFailure() shape as $answerQueue. */
    protected static array $routeQueue = [];

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

    /**
     * Every route() call's full payload -- Part 3's privacy-payload
     * tests assert on exactly this (the masked question(s), the role's
     * catalogue, the few-shots, today's date -- nothing else).
     *
     * @var array<int, array{maskedQuestion: string, recentMaskedQuestions: array<int, string>, catalogue: array, fewShots: array, todayLabel: string, model: string, timeoutSeconds: int}>
     */
    protected static array $routeCalls = [];

    public static function reset(): void
    {
        self::$answerQueue = [];
        self::$aggregateQueue = [];
        self::$routeQueue = [];
        self::$unavailable = false;
        self::$unavailableErrorCode = null;
        self::$answerCalls = [];
        self::$aggregateCalls = [];
        self::$routeCalls = [];
    }

    /** @return array<int, array{maskedQuestion: string, recentMaskedQuestions: array<int, string>, catalogue: array, fewShots: array, todayLabel: string, model: string, timeoutSeconds: int}> */
    public static function routeCalls(): array
    {
        return self::$routeCalls;
    }

    public static function routeCallCount(): int
    {
        return count(self::$routeCalls);
    }

    /** @param array<string, mixed> $overrides any of id/params/confidence/alternatives/clarify -- unset keys get a sane default. */
    public static function queueRoute(array $overrides): void
    {
        self::$routeQueue[] = ['__failure' => false] + $overrides + ['id' => 'out_of_scope', 'params' => [], 'confidence' => 1.0, 'alternatives' => [], 'clarify' => null];
    }

    /** Queues ONE failed route() attempt with the given error code, consumed in order alongside queueRoute(). */
    public static function queueRouteFailure(string $errorCode): void
    {
        self::$routeQueue[] = ['__failure' => true, 'error_code' => $errorCode];
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

    public function route(string $maskedQuestion, array $recentMaskedQuestions, array $catalogue, array $fewShots, string $todayLabel, string $model, int $timeoutSeconds): array
    {
        self::$routeCalls[] = [
            'maskedQuestion' => $maskedQuestion,
            'recentMaskedQuestions' => $recentMaskedQuestions,
            'catalogue' => $catalogue,
            'fewShots' => $fewShots,
            'todayLabel' => $todayLabel,
            'model' => $model,
            'timeoutSeconds' => $timeoutSeconds,
        ];

        if (self::$unavailable) {
            return ['outcome' => 'unavailable', 'id' => null, 'params' => [], 'confidence' => null, 'alternatives' => [], 'clarify' => null, 'error_code' => self::$unavailableErrorCode];
        }

        if (self::$routeQueue !== []) {
            $next = array_shift(self::$routeQueue);

            if ($next['__failure']) {
                return ['outcome' => 'unavailable', 'id' => null, 'params' => [], 'confidence' => null, 'alternatives' => [], 'clarify' => null, 'error_code' => $next['error_code']];
            }

            unset($next['__failure']);

            return [...$next, 'outcome' => 'ok', 'error_code' => null];
        }

        // No queued response -- a deterministic, no-network default: try
        // to match a catalogue id mentioned verbatim in the question,
        // else out_of_scope. Good enough for a test that only cares
        // about privacy/plumbing, not routing accuracy.
        foreach (array_column($catalogue, 'id') as $id) {
            if (str_contains($maskedQuestion, str_replace('_', ' ', $id))) {
                return ['outcome' => 'ok', 'id' => $id, 'params' => [], 'confidence' => 0.9, 'alternatives' => [], 'clarify' => null, 'error_code' => null];
            }
        }

        return ['outcome' => 'ok', 'id' => 'out_of_scope', 'params' => [], 'confidence' => 0.9, 'alternatives' => [], 'clarify' => null, 'error_code' => null];
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
