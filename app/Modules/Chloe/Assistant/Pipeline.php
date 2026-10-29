<?php

namespace App\Modules\Chloe\Assistant;

use App\Modules\Chloe\Models\AssistantLog;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

/**
 * The whole ask() pipeline, start to finish: role check -> length check ->
 * privacy masking -> deterministic rules (a clear lookup, an action
 * request, a manipulation phrase) -> otherwise ONE model call that can see
 * every guidance topic the role may read plus the lookup catalogue (PART
 * B) -> role-gated dispatch and answer validation -> PART 7 log row.
 *
 * Guidance-TOPIC phrase matches from KeywordRules (config('assistant.
 * keyword_rules')'s "guidance:*" entries, and the lost/damaged-key role
 * routing in KeywordRules::extractDeterministic()) are deliberately no
 * longer used to short-circuit the model call the way a LOOKUP match
 * still is -- PART B's whole point is that the model, with every topic's
 * text already in front of it, finds the right guidance far more
 * reliably than a flat phrase list ever did, including a rephrased
 * question the phrase list was never written to catch. They still exist
 * and still work, just demoted to the one place PART B.6 asks for: the
 * fallback answer when the model itself is unavailable.
 *
 * The conversation itself is never stored server-side: $message and
 * $recentUserQuestions live only for the duration of one call to ask(),
 * and nothing here writes either one anywhere.
 */
final class Pipeline
{
    public const UNAVAILABLE_MESSAGE = 'The assistant is busy right now. Please try again in a moment, or use the suggested questions.';

    public const OUT_OF_SCOPE_MESSAGE = "I can't help with that. You can ask about workstations, locker keys, and the Term of Use.";

    public const ACTION_REFUSED_MESSAGE = "I can't perform actions. Please open the seat to continue.";

    public const PRIVACY_NAME_MESSAGE = 'For privacy, please search using a Student ID or seat number.';

    public const ANYONE_ELSE_MESSAGE = 'I can only share information about your own workstation.';

    public const ROLE_NOT_SUPPORTED_MESSAGE = 'The assistant is not available for your role yet.';

    /** PART B.6: the model said out_of_scope but the question still sounds like it's about this system -- never shown for the DETERMINISTIC out_of_scope result (an explicit manipulation phrase), which stays the flat refusal. */
    public const ASKED_BACK_DOMAIN_MESSAGE = "I'm not sure I understood. You can ask about seats, locker keys, the Term of Use, or use one of the suggested questions.";

    public const MISSING_TOPIC_MESSAGE = 'Sorry, I could not find information on that topic. Please contact the Centre for Graduate Studies (CGS).';

    public function __construct(protected AssistantModel $model) {}

    /** @return array{answer: string, interpreted_as: ?string, links: array, outcome: string, suggestions: ?array} */
    /**
     * $bypassKeywordRules: assistant:evaluate's --model-only -- skips the
     * deterministic layer entirely so every question reaches the model,
     * to measure it alone.
     * $allowFallback (Round 3 PART A.4): assistant:evaluate/assistant:
     * check default this to false (primary model only, so results
     * measure that model) unless their own --allow-fallback flag is
     * passed; the real chat endpoints always pass the default, true.
     */
    public function ask(User $user, string $message, array $recentUserQuestions, bool $bypassKeywordRules = false, bool $allowFallback = true): array
    {
        $role = self::normalizeRole($user->role);

        if ($role === null) {
            return $this->finish($user, $user->role, AssistantLog::SOURCE_TYPED, 'role_not_supported', AssistantLog::OUTCOME_REFUSED, self::ROLE_NOT_SUPPORTED_MESSAGE, [], null, AssistantLog::ANSWERED_BY_DIRECT, 'role_not_supported');
        }

        $message = trim($message);
        $maxLength = (int) config('assistant.max_question_length', 300);

        if ($message === '') {
            return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'empty_question', AssistantLog::OUTCOME_REFUSED, 'Please type a question.', [], null, AssistantLog::ANSWERED_BY_DIRECT, 'empty_question');
        }
        if (mb_strlen($message) > $maxLength) {
            return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'question_too_long', AssistantLog::OUTCOME_REFUSED, "Please ask a shorter question ({$maxLength} characters or fewer).", [], null, AssistantLog::ANSWERED_BY_DIRECT, 'question_too_long');
        }

        // Masking and name-detection BEFORE anything else touches the model.
        $masked = PrivacyMasker::mask($message);
        $maskedQuestion = $masked['masked'];
        $idMap = $masked['idMap'];

        if (PrivacyMasker::containsKnownStudentName($message)) {
            return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'privacy_name', AssistantLog::OUTCOME_REFUSED, self::PRIVACY_NAME_MESSAGE, [], null, AssistantLog::ANSWERED_BY_DIRECT, 'name_detected');
        }

        // Deterministic, parameter-carrying matches (an explicit seat
        // number, a block, an ID placeholder, an action verb, a
        // manipulation phrase...) are tried before the plain phrase
        // lists -- see KeywordRules::extractDeterministic() for why a
        // phrase list alone can't express "J2 free seats?".
        $rawMatches = $bypassKeywordRules ? [] : KeywordRules::matchAll($maskedQuestion);
        $deterministic = $bypassKeywordRules ? null : KeywordRules::extractDeterministic($maskedQuestion, $role);
        $deterministicName = $deterministic['name'] ?? null;

        // A student's question naming a CGS-only identity lookup is refused
        // outright, before any model call -- cheaper and it is always
        // refused anyway once role-gated below.
        if (! $bypassKeywordRules && $role === Role::STUDENT
            && (array_intersect($rawMatches, ['seat_lookup', 'student_lookup']) !== [] || in_array($deterministicName, ['seat_lookup', 'student_lookup'], true))) {
            return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'anyone_else', AssistantLog::OUTCOME_REFUSED, self::ANYONE_ELSE_MESSAGE, [], null, AssistantLog::ANSWERED_BY_RULE, 'anyone_else');
        }

        if ($deterministicName === 'out_of_scope') {
            return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'out_of_scope', AssistantLog::OUTCOME_REFUSED, self::OUT_OF_SCOPE_MESSAGE, [], null, AssistantLog::ANSWERED_BY_RULE, 'out_of_scope');
        }

        if ($deterministicName === 'action_request') {
            return $this->actionRequestResponse($user, $role, $deterministic['parameters'], AssistantLog::ANSWERED_BY_RULE);
        }

        // Round 4 PART B.1: for the CGS role, a raw match (deterministic
        // OR phrase-list) naming a STUDENT guidance topic with a
        // configured CGS counterpart also counts as a match on that
        // counterpart -- added to $rawMatches itself, BEFORE the
        // role-allow-list filtering just below, so neither the
        // deterministic path nor the phrase-list path ever silently
        // drops it the way plain role-filtering otherwise would (a
        // student-only topic id is never isAllowedForRole() for 'cgs').
        // $preferCgsTopics is true whenever this expansion actually
        // added something, and is passed to the model as an explicit
        // "this user is CGS staff" instruction alongside the hints.
        $preferCgsTopics = false;

        if ($role === 'cgs') {
            $counterpartMap = config('assistant.cgs_topic_counterparts', []);
            $namesToCheck = $deterministicName !== null ? [...$rawMatches, $deterministicName] : $rawMatches;

            foreach ($namesToCheck as $name) {
                if (! str_starts_with($name, 'guidance:')) {
                    continue;
                }

                $studentTopic = substr($name, strlen('guidance:'));

                if (isset($counterpartMap[$studentTopic])) {
                    $preferCgsTopics = true;
                    $counterpartMatch = "guidance:{$counterpartMap[$studentTopic]}";

                    if (! in_array($counterpartMatch, $rawMatches, true)) {
                        // Round 4 PART D: PREPENDED, not appended -- found
                        // live via assistant:evaluate that appending left
                        // the student topic as $topicHints[0] (and so
                        // $fallbackGuidanceTopic), which is the OPPOSITE
                        // of "prefer" when both are hinted.
                        array_unshift($rawMatches, $counterpartMatch);
                    }
                }
            }
        }

        // Only a LOOKUP match short-circuits the model call -- a
        // "guidance:*" match (deterministic or phrase-list) is set aside
        // instead: $fallbackGuidanceTopic (the first one) for PART B.6's
        // fallback when both models fail, and $topicHints (PART C.1,
        // ALL of them, topic ids only) passed to the model itself as
        // "likely relevant" -- the model still decides, but no longer has
        // to find a matched topic unaided.
        $multipleMatched = false;
        $primaryLookup = null;
        $parameters = [];
        $fallbackGuidanceTopic = null;
        $topicHints = [];

        if ($deterministicName !== null && str_starts_with($deterministicName, 'guidance:') && $this->isAllowedForRole($deterministicName, $role)) {
            $fallbackGuidanceTopic = substr($deterministicName, strlen('guidance:'));
            $topicHints = [$fallbackGuidanceTopic];
        } elseif ($deterministicName !== null && $this->isAllowedForRole($deterministicName, $role)) {
            $primaryLookup = $deterministicName;
            $parameters = $deterministic['parameters'];
        } else {
            $allowedMatches = array_values(array_unique(array_filter(
                $rawMatches,
                fn (string $name) => $this->isAllowedForRole($name, $role)
            )));

            $lookupMatches = array_values(array_filter($allowedMatches, fn ($n) => ! str_starts_with($n, 'guidance:')));
            $guidanceMatches = array_values(array_filter($allowedMatches, fn ($n) => str_starts_with($n, 'guidance:')));

            $multipleMatched = count($lookupMatches) > 1;
            $primaryLookup = $lookupMatches[0] ?? null;
            $topicHints = array_map(fn ($n) => substr($n, strlen('guidance:')), $guidanceMatches);
            $fallbackGuidanceTopic = $topicHints[0] ?? null;
        }

        if ($primaryLookup !== null) {
            // The same unmask every model-chosen parameter also gets below --
            // a rule-matched lookup can carry a placeholder too (e.g. the
            // deterministic ID-placeholder extraction for student_lookup).
            $parameters = array_map(fn ($v) => is_string($v) ? PrivacyMasker::unmask($v, $idMap) : $v, $parameters);

            return $this->answerLookup($user, $role, $primaryLookup, $parameters, AssistantLog::ANSWERED_BY_RULE, $multipleMatched);
        }

        // Otherwise: the one model call, with every guidance topic the
        // role may see and the lookup catalogue, so a rephrased question
        // still finds the right answer. Round 3 PART A: this one call is
        // now a whole reliability sequence (retries, then the fallback
        // model), never a single bare attempt.
        if (! $this->anyModelBudgetAvailable($allowFallback)) {
            return $this->unavailableOrFallback($user, $role, $fallbackGuidanceTopic, null, null);
        }

        $recentTurns = (int) config('assistant.recent_turns', 5);
        $recentMasked = array_map(
            fn ($q) => PrivacyMasker::mask((string) $q)['masked'],
            array_slice($recentUserQuestions, -$recentTurns)
        );

        $knowledge = GuidanceLibrary::knowledgeTextForRole($role, $maskedQuestion);
        $lookupCatalogue = Lookups::definitionsForRole($role);

        $result = $this->callModelWithReliability($maskedQuestion, $recentMasked, $knowledge['text'], $lookupCatalogue, $topicHints, $allowFallback, $preferCgsTopics, $role === 'cgs');

        if ($result['outcome'] !== 'ok' || ! is_string($result['kind'] ?? null)) {
            return $this->unavailableOrFallback($user, $role, $fallbackGuidanceTopic, $result['model_used'] ?? null, $result['error_code'] ?? null);
        }

        return $this->dispatchModelResult($user, $role, $result, $maskedQuestion, $knowledge['text'], $idMap);
    }

    /**
     * Round 3 PART A: the whole reliability sequence for one call --
     * tries the primary model (skipped entirely if its circuit breaker
     * is open), with up to 2 retries on 500/503/504/timeout (never on
     * 429, which goes straight to the fallback instead), 2 then 5
     * second backoff waits between primary attempts, all bounded by
     * config('assistant.total_deadline_seconds') so the whole sequence
     * never runs past the chat widget's own client timeout; then, if
     * $allowFallback and there is still time and budget, ONE attempt on
     * the fallback model. Every attempt (success or failure) is counted
     * against that MODEL's own per-minute/per-day budget and its own
     * circuit-breaker failure count -- quotas are per model on Google's
     * side, so the two models never share a counter.
     *
     * @param  array<int, string>  $topicHints
     * @return array{kind: ?string, answer: ?string, sources: array, lookup: ?string, params: array, outcome: string, error_code: ?string, model_used: ?string}
     */
    protected function callModelWithReliability(string $maskedQuestion, array $recentMasked, string $knowledgeText, array $lookupCatalogue, array $topicHints, bool $allowFallback, bool $preferCgsTopics = false, bool $isCgsRole = false): array
    {
        $deadline = microtime(true) + (int) config('assistant.total_deadline_seconds', 22);
        $primaryModel = (string) config('assistant.gemini.model');
        $fallbackModel = (string) config('assistant.fallback_model');
        $waits = config('assistant.retry_waits_seconds', [2, 5]);
        $lastErrorCode = null;
        $estimatedTokens = $this->estimateRequestTokens($maskedQuestion, $recentMasked, $knowledgeText, $lookupCatalogue, $topicHints);

        if (! $this->circuitIsOpen($primaryModel)) {
            $maxAttempts = 1 + count($waits);

            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                $timeout = $this->attemptTimeout($deadline);

                if ($timeout === null || ! $this->modelBudgetAvailable($primaryModel, $estimatedTokens)) {
                    break;
                }

                $this->recordModelCall($primaryModel, $estimatedTokens);
                $result = $this->model->answer($maskedQuestion, $recentMasked, $knowledgeText, $lookupCatalogue, $primaryModel, $timeout, $topicHints, $preferCgsTopics, $isCgsRole);

                if ($result['outcome'] === 'ok') {
                    $this->recordModelOutcome($primaryModel, true);

                    return [...$result, 'model_used' => $primaryModel];
                }

                $this->recordModelOutcome($primaryModel, false);
                $lastErrorCode = $result['error_code'] ?? null;

                // PART A.1: a 429 is never retried on the SAME model.
                if ($lastErrorCode === '429') {
                    break;
                }

                if ($attempt < count($waits) && $this->attemptTimeout($deadline, $waits[$attempt]) !== null) {
                    // Sleep::sleep(), not a raw sleep() call -- Sleep::fake() (every test's own setUp(), see tests/TestCase.php) makes this instant without changing the retry logic itself.
                    Sleep::sleep($waits[$attempt]);
                } else {
                    break;
                }
            }
        }

        if (! $allowFallback) {
            return ['kind' => null, 'answer' => null, 'sources' => [], 'lookup' => null, 'params' => [], 'outcome' => 'unavailable', 'error_code' => $lastErrorCode, 'model_used' => null];
        }

        $timeout = $this->attemptTimeout($deadline);

        if ($timeout !== null && $this->modelBudgetAvailable($fallbackModel, $estimatedTokens)) {
            $this->recordModelCall($fallbackModel, $estimatedTokens);
            $result = $this->model->answer($maskedQuestion, $recentMasked, $knowledgeText, $lookupCatalogue, $fallbackModel, $timeout, $topicHints, $preferCgsTopics, $isCgsRole);

            if ($result['outcome'] === 'ok') {
                $this->recordModelOutcome($fallbackModel, true);

                return [...$result, 'model_used' => $fallbackModel];
            }

            $this->recordModelOutcome($fallbackModel, false);
            $lastErrorCode = $result['error_code'] ?? $lastErrorCode;
        }

        return ['kind' => null, 'answer' => null, 'sources' => [], 'lookup' => null, 'params' => [], 'outcome' => 'unavailable', 'error_code' => $lastErrorCode, 'model_used' => null];
    }

    /** Seconds left before $deadline, minus an optional $reserve (e.g. the next backoff wait) -- null when that would leave under half a second, capped at 10s per attempt so one slow call can never eat the whole budget. */
    protected function attemptTimeout(float $deadline, float $reserve = 0): ?int
    {
        $remaining = $deadline - microtime(true) - $reserve;

        return $remaining > 0.5 ? (int) max(1, min(10, floor($remaining))) : null;
    }

    /** @param array{kind: string, answer: ?string, sources: array, lookup: ?string, params: array} $result */
    protected function dispatchModelResult(User $user, string $role, array $result, string $maskedQuestion, string $knowledgeText, array $idMap): array
    {
        $modelUsed = $result['model_used'] ?? null;
        $kind = $result['kind'];

        if ($kind === 'out_of_scope') {
            if (ParameterExtractor::hasDomainWord($maskedQuestion)) {
                return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'out_of_scope', AssistantLog::OUTCOME_ASKED_BACK, self::ASKED_BACK_DOMAIN_MESSAGE, [], null, AssistantLog::ANSWERED_BY_MODEL, null, suggestions: $this->suggestionsList($role), modelUsed: $modelUsed);
            }

            return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'out_of_scope', AssistantLog::OUTCOME_REFUSED, self::OUT_OF_SCOPE_MESSAGE, [], null, AssistantLog::ANSWERED_BY_MODEL, 'out_of_scope', modelUsed: $modelUsed);
        }

        if ($kind === 'action') {
            $params = array_map(fn ($v) => is_string($v) ? PrivacyMasker::unmask($v, $idMap) : $v, $result['params'] ?? []);

            return $this->actionRequestResponse($user, $role, $params, AssistantLog::ANSWERED_BY_MODEL, $modelUsed);
        }

        if ($kind === 'clarify') {
            $text = self::stripInventedPersonalData(trim((string) ($result['answer'] ?? '')));

            if ($text === '') {
                $text = 'Could you tell me a bit more about what you need?';
            }

            return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'clarify', AssistantLog::OUTCOME_ASKED_BACK, $text, [], null, AssistantLog::ANSWERED_BY_MODEL, null, modelUsed: $modelUsed);
        }

        if ($kind === 'lookup') {
            $lookupName = (string) ($result['lookup'] ?? '');

            if ($lookupName === '' || ! $this->isAllowedForRole($lookupName, $role)) {
                if ($role === Role::STUDENT && in_array($lookupName, ['seat_lookup', 'student_lookup'], true)) {
                    return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'anyone_else', AssistantLog::OUTCOME_REFUSED, self::ANYONE_ELSE_MESSAGE, [], null, AssistantLog::ANSWERED_BY_MODEL, 'anyone_else', modelUsed: $modelUsed);
                }

                return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'out_of_scope', AssistantLog::OUTCOME_REFUSED, self::OUT_OF_SCOPE_MESSAGE, [], null, AssistantLog::ANSWERED_BY_MODEL, 'role_not_permitted', modelUsed: $modelUsed);
            }

            $params = array_map(fn ($v) => is_string($v) ? PrivacyMasker::unmask($v, $idMap) : $v, $result['params'] ?? []);

            return $this->answerLookup($user, $role, $lookupName, $params, AssistantLog::ANSWERED_BY_MODEL, false, AssistantLog::SOURCE_TYPED, maskedQuestion: $maskedQuestion, allowCompose: true, modelUsed: $modelUsed);
        }

        if ($kind === 'guidance') {
            return $this->answerModelGuidance($user, $role, $result, $maskedQuestion, $knowledgeText, $modelUsed);
        }

        // Any other/unknown kind the model returned -- never guessed at, always the fallback.
        return $this->unavailableOrFallback($user, $role, null, $modelUsed, 'invalid_kind');
    }

    /**
     * PART B.3's own validation: only topic ids the role may actually see
     * are accepted as sources (anything else is silently dropped, never
     * trusted); the model's text is stripped of any URL/8-digit-number/
     * email first (it is never allowed to invent or echo one back); then
     * every remaining number must appear in the knowledge text it was
     * actually given or in the question itself, or the model is
     * presumed to have invented it and the cited sections' own raw text
     * is used instead.
     */
    protected function answerModelGuidance(User $user, string $role, array $result, string $maskedQuestion, string $knowledgeText, ?string $modelUsed): array
    {
        $allowedTopics = GuidanceLibrary::topicsForRole($role);
        // Round 4 PART B.1: the model's own raw "sources" can echo a student topic id straight from the hints it was given -- not CGS-allowed on its own, but its CGS counterpart is, so it is added here BEFORE the role-allow-list filter below, same rule as the pre-call hints.
        $rawSources = $this->addCgsTopicCounterparts($result['sources'] ?? [], $role);
        $sources = array_values(array_intersect($rawSources, $allowedTopics));

        $text = self::stripInventedPersonalData(trim((string) ($result['answer'] ?? '')));
        $valid = $text !== '' && $sources !== [] && GuidanceLibrary::everyNumberAppearsIn($text, $knowledgeText.' '.$maskedQuestion);

        $answeredBy = AssistantLog::ANSWERED_BY_MODEL_GUIDANCE;

        if (! $valid) {
            if ($sources === []) {
                return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'guidance:unknown', AssistantLog::OUTCOME_ERROR, self::MISSING_TOPIC_MESSAGE, [], null, AssistantLog::ANSWERED_BY_DIRECT, 'missing_topic', modelUsed: $modelUsed);
            }

            $text = collect($sources)->map(fn ($t) => GuidanceLibrary::sourceText($t))->filter()->implode("\n\n");
            $answeredBy = AssistantLog::ANSWERED_BY_DIRECT;
        }

        $links = GuidanceLibrary::linksForTopics($sources);
        $primaryTopic = $sources[0] ?? 'unknown';

        return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, "guidance:{$primaryTopic}", AssistantLog::OUTCOME_ANSWERED, $text, $links, null, $answeredBy, null, modelUsed: $modelUsed);
    }

    /** Never allowed in anything the model writes -- a URL, an 8-digit ID, or an email, even a real one it happened to see in the question, is removed rather than trusted. */
    public static function stripInventedPersonalData(string $text): string
    {
        $text = preg_replace('#https?://\S+#i', '[link removed]', $text) ?? $text;
        $text = preg_replace('/\b\d{8}\b/', '[ID removed]', $text) ?? $text;
        $text = preg_replace('/\b[\w.+-]+@[\w-]+\.[\w.-]+\b/i', '[email removed]', $text) ?? $text;

        return $text;
    }

    /** PART B.6: model unavailable/invalid -- a guidance topic the keyword rules already found gets its raw source text; otherwise the busy message with the suggested questions. $modelUsed/$errorCode (Round 3 PART A.5) are whatever callModelWithReliability() last saw, metadata only, logged either way. */
    protected function unavailableOrFallback(User $user, string $role, ?string $fallbackGuidanceTopic, ?string $modelUsed, ?string $errorCode): array
    {
        if ($fallbackGuidanceTopic !== null) {
            $source = GuidanceLibrary::sourceText($fallbackGuidanceTopic);

            if ($source !== null) {
                $links = GuidanceLibrary::linksForTopics([$fallbackGuidanceTopic]);

                return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, "guidance:{$fallbackGuidanceTopic}", AssistantLog::OUTCOME_ANSWERED, $source, $links, null, AssistantLog::ANSWERED_BY_DIRECT, null, modelUsed: $modelUsed, errorCode: $errorCode);
            }
        }

        return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'unavailable', AssistantLog::OUTCOME_UNAVAILABLE, self::UNAVAILABLE_MESSAGE, [], null, AssistantLog::ANSWERED_BY_DIRECT, 'provider_unavailable', suggestions: $this->suggestionsList($role), modelUsed: $modelUsed, errorCode: $errorCode);
    }

    /** POST .../suggestion -- never touches the model or the keyword matcher. */
    public function askSuggestion(User $user, string $suggestionId): array
    {
        $role = self::normalizeRole($user->role);

        if ($role === null) {
            return $this->finish($user, $user->role, AssistantLog::SOURCE_SUGGESTION, 'role_not_supported', AssistantLog::OUTCOME_REFUSED, self::ROLE_NOT_SUPPORTED_MESSAGE, [], null, AssistantLog::ANSWERED_BY_DIRECT, 'role_not_supported');
        }

        $suggestion = SuggestedQuestions::find($suggestionId, $role);

        if ($suggestion === null) {
            return $this->finish($user, $role, AssistantLog::SOURCE_SUGGESTION, 'unknown_suggestion', AssistantLog::OUTCOME_ERROR, 'That suggested question is not available.', [], null, AssistantLog::ANSWERED_BY_DIRECT, 'unknown_suggestion');
        }

        if ($suggestion['type'] === 'static') {
            $text = SuggestedAnswers::staticText($suggestion['target']);

            if ($text === null) {
                return $this->finish($user, $role, AssistantLog::SOURCE_SUGGESTION, "suggestion:{$suggestionId}", AssistantLog::OUTCOME_ERROR, self::MISSING_TOPIC_MESSAGE, [], null, AssistantLog::ANSWERED_BY_DIRECT, 'missing_topic');
            }

            return $this->finish($user, $role, AssistantLog::SOURCE_SUGGESTION, "suggestion:{$suggestionId}", AssistantLog::OUTCOME_ANSWERED, $text, [], null, AssistantLog::ANSWERED_BY_DIRECT, null);
        }

        $dynamic = SuggestedAnswers::dynamic($suggestion['target'], $user, $role);

        return $this->finish($user, $role, AssistantLog::SOURCE_SUGGESTION, "suggestion:{$suggestionId}", AssistantLog::OUTCOME_ANSWERED, $dynamic['text'], $dynamic['links'], null, AssistantLog::ANSWERED_BY_DIRECT, null);
    }

    protected function answerLookup(User $user, string $role, string $lookup, array $parameters, string $answeredBy, bool $multipleMatched, string $source = AssistantLog::SOURCE_TYPED, ?string $maskedQuestion = null, bool $allowCompose = false, ?string $modelUsed = null): array
    {
        $result = Lookups::run($lookup, $parameters, $user, $role);

        if ($result['outcome'] === 'asked_back') {
            return $this->finish($user, $role, $source, $lookup, AssistantLog::OUTCOME_ASKED_BACK, $result['text'], [], null, $answeredBy, null, modelUsed: $modelUsed);
        }

        $text = $result['text'];
        $links = $result['links'] ?? [];
        $resultCount = $result['resultCount'] ?? null;
        $interpretedAs = null;

        // PART B.4: a non-personal AGGREGATE lookup, chosen by the model,
        // may get a SECOND model call that turns the structured result
        // into two to five sentences of prose -- never for a lookup
        // that returns personal data (never offered here at all, since
        // $allowCompose is only true from the model's own 'lookup' kind,
        // and never for a rule-matched lookup, which stays a template).
        // Single attempt, primary model only -- never retried or failed
        // over to the fallback, since the template is always a complete
        // answer on its own; $modelUsed (the lookup's own CHOICE) is
        // unaffected either way.
        $composed = null;
        $primaryModel = (string) config('assistant.gemini.model');
        $composeEstimatedTokens = (int) ceil((strlen($maskedQuestion ?? '') + strlen($lookup) + strlen(json_encode($result) ?: '')) / 4);

        if ($allowCompose && $maskedQuestion !== null && in_array($lookup, config('assistant.aggregate_lookups', []), true) && $this->modelBudgetAvailable($primaryModel, $composeEstimatedTokens)) {
            $this->recordModelCall($primaryModel, $composeEstimatedTokens);
            $timeout = (int) min(10, (int) config('assistant.total_deadline_seconds', 22));
            $aggregate = $this->model->composeAggregate($maskedQuestion, $lookup, $result, $primaryModel, $timeout);

            if ($aggregate['outcome'] === 'ok' && is_string($aggregate['text']) && trim($aggregate['text']) !== '') {
                $candidate = self::stripInventedPersonalData(trim($aggregate['text']));

                if (GuidanceLibrary::everyNumberAppearsIn($candidate, json_encode($result))) {
                    $composed = $candidate;
                }
            }
        }

        if (empty($result['skipFooter'])) {
            $paramsText = $parameters === [] ? 'none' : collect($parameters)->map(fn ($v, $k) => "{$k}={$v}")->implode(', ');
            $label = ucfirst(str_replace('_', ' ', $lookup));
            $interpretedAs = "{$label} · {$paramsText}";
            $time = Carbon::now('Asia/Kuala_Lumpur')->format('j M Y, g:ia');

            if ($composed !== null) {
                $answeredBy = AssistantLog::ANSWERED_BY_MODEL_COMPOSE;
                $text = $composed."\n\nBased on live data at {$time}.\nInterpreted as: {$interpretedAs}";
            } else {
                $text .= "\n\nBased on live data at {$time}.\nInterpreted as: {$interpretedAs}";
            }
        }

        if ($multipleMatched) {
            $text .= "\n\nPlease ask one question at a time.";
        }

        return $this->finish($user, $role, $source, $lookup, AssistantLog::OUTCOME_ANSWERED, $text, $links, $resultCount, $answeredBy, null, $interpretedAs, modelUsed: $modelUsed);
    }

    protected function actionRequestResponse(User $user, string $role, array $parameters, string $answeredBy, ?string $modelUsed = null): array
    {
        $links = [];
        $seatNumber = isset($parameters['seat_number']) ? (int) $parameters['seat_number'] : null;

        if ($seatNumber && $seatNumber >= 1 && $seatNumber <= 315) {
            $links[] = ['label' => 'Seat Details', 'url' => route('workstation.cgs.seat', $seatNumber)];
        }

        return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'action_request', AssistantLog::OUTCOME_REFUSED, self::ACTION_REFUSED_MESSAGE, $links, null, $answeredBy, 'action_request', modelUsed: $modelUsed);
    }

    /** The assistant's own two-role model -- 'student' | 'cgs' | null (everyone else). Shared with the controller's suggestions() so both agree on who gets an empty list vs. the refusal message. */
    public static function normalizeRole(string $dbRole): ?string
    {
        if ($dbRole === Role::STUDENT) {
            return Role::STUDENT;
        }

        return Role::isCgs($dbRole) ? 'cgs' : null;
    }

    /**
     * Round 4 PART B.1: for the CGS role only, every student topic id in
     * $topicIds that has a configured CGS counterpart
     * (config('assistant.cgs_topic_counterparts')) gets that counterpart
     * appended -- the student topic id itself is never removed, only
     * added to. A no-op for the student role or when nothing in
     * $topicIds has a counterpart.
     *
     * @param  array<int, string>  $topicIds
     * @return array<int, string>
     */
    protected function addCgsTopicCounterparts(array $topicIds, string $role): array
    {
        if ($role !== 'cgs') {
            return $topicIds;
        }

        $map = config('assistant.cgs_topic_counterparts', []);

        foreach ($topicIds as $topicId) {
            if (isset($map[$topicId]) && ! in_array($map[$topicId], $topicIds, true)) {
                // Round 4 PART D: PREPENDED, not appended -- found live
                // via assistant:evaluate that appending left the model's
                // own (student-side) choice as $sources[0], so the
                // logged/primary topic stayed the student one even
                // though its CGS counterpart was correctly present in
                // the list -- the opposite of "prefer".
                array_unshift($topicIds, $map[$topicId]);
            }
        }

        return $topicIds;
    }

    protected function isAllowedForRole(string $name, string $role): bool
    {
        if (str_starts_with($name, 'guidance:')) {
            return GuidanceLibrary::isAllowed(substr($name, strlen('guidance:')), $role);
        }

        return Lookups::isAllowed($name, $role);
    }

    /**
     * Round 3 PART A.2: the free-tier budget, checked BEFORE calling the
     * provider -- now ONE pair of counters PER MODEL NAME, since quotas
     * are per model on Google's side. $allowFallback narrows this to
     * "is there any point even trying" before the reliability sequence
     * starts (the sequence itself re-checks per model as it goes).
     */
    protected function anyModelBudgetAvailable(bool $allowFallback): bool
    {
        if ($this->modelBudgetAvailable((string) config('assistant.gemini.model'))) {
            return true;
        }

        return $allowFallback && $this->modelBudgetAvailable((string) config('assistant.fallback_model'));
    }

    /**
     * Round 4 PART A.3: limits are now PER MODEL NAME (see config('assistant.
     * rate_limits') -- 'default' covers any model name not explicitly
     * listed, so an unexpected name never gets an unlimited budget. Also
     * checks the estimated-input-token minute budget when $estimatedTokens
     * is given (0 -- the default -- skips that check, used by the coarse
     * "is it even worth trying" pre-check in anyModelBudgetAvailable(),
     * before the actual request text exists).
     */
    protected function modelBudgetAvailable(string $model, int $estimatedTokens = 0): bool
    {
        $limits = $this->modelLimits($model);

        $minuteKey = "assistant:calls:minute:{$model}:".now()->format('YmdHi');
        $dayKey = "assistant:calls:day:{$model}:".$this->dayCacheDate();

        $perMinute = (int) Cache::get($minuteKey, 0);
        $perDay = (int) Cache::get($dayKey, 0);

        if ($perMinute >= (int) ($limits['requests_per_minute'] ?? 5) || $perDay >= (int) ($limits['requests_per_day'] ?? 100)) {
            return false;
        }

        if ($estimatedTokens > 0) {
            $tokensKey = "assistant:tokens:minute:{$model}:".now()->format('YmdHi');
            $tokensUsed = (int) Cache::get($tokensKey, 0);

            if ($tokensUsed + $estimatedTokens > (int) ($limits['tokens_per_minute'] ?? 200000)) {
                return false;
            }
        }

        return true;
    }

    protected function recordModelCall(string $model, int $estimatedTokens = 0): void
    {
        $minuteKey = "assistant:calls:minute:{$model}:".now()->format('YmdHi');
        $dayKey = "assistant:calls:day:{$model}:".$this->dayCacheDate();

        Cache::put($minuteKey, (int) Cache::get($minuteKey, 0) + 1, 70);
        Cache::put($dayKey, (int) Cache::get($dayKey, 0) + 1, 90000);

        if ($estimatedTokens > 0) {
            $tokensKey = "assistant:tokens:minute:{$model}:".now()->format('YmdHi');
            Cache::put($tokensKey, (int) Cache::get($tokensKey, 0) + $estimatedTokens, 70);
        }
    }

    /**
     * Model names like "gemini-3.5-flash-lite" contain dots of their own,
     * so a single dot-joined config() path such as
     * "assistant.rate_limits.{$model}.requests_per_minute" silently
     * mis-splits on EVERY dot (Laravel's config()/Arr::get() dot notation
     * has no escaping) and returns null -- found live writing this
     * round's own test, where it made every per-model limit silently
     * resolve to the 'default' bucket regardless of which model was
     * asked about. Fetching the whole map with one safe (dot-free-at-the-
     * boundary) config() call and then indexing into it with plain array
     * access avoids the bug entirely.
     */
    protected function modelLimits(string $model): array
    {
        $allLimits = config('assistant.rate_limits', []);

        return $allLimits[$model] ?? $allLimits['default'] ?? [];
    }

    /** Round 4 PART A.4: the DAY cache key only, built in config('assistant.day_timezone') (Google's own quota reset instant) -- the per-minute key above stays server-local, a window far too short for a timezone offset to matter. Never touches an existing counter's value, only which key a future call lands on. */
    protected function dayCacheDate(): string
    {
        return now(config('assistant.day_timezone', 'America/Los_Angeles'))->format('Ymd');
    }

    /** Round 4 PART A.3: crude input-size estimate (characters / 4, no real tokenizer) for everything actually sent to the model -- knowledge text and the lookup catalogue dominate the size, so both are included alongside the question/recent turns/hints. */
    protected function estimateRequestTokens(string $maskedQuestion, array $recentMasked, string $knowledgeText, array $lookupCatalogue, array $topicHints): int
    {
        $chars = strlen($maskedQuestion) + strlen($knowledgeText)
            + strlen(implode(' ', $recentMasked))
            + strlen(json_encode($lookupCatalogue) ?: '')
            + strlen(implode(' ', $topicHints));

        return (int) ceil($chars / 4);
    }

    /**
     * Round 3 PART A.3: the circuit breaker, per model -- three
     * consecutive failures on one model opens it (new requests skip
     * straight to the fallback) for config('assistant.circuit_breaker.
     * open_seconds'); any success on that model resets its own failure
     * count to zero. Two plain Cache entries per model, nothing else.
     */
    protected function circuitIsOpen(string $model): bool
    {
        return now()->timestamp < (int) Cache::get("assistant:circuit:open_until:{$model}", 0);
    }

    protected function recordModelOutcome(string $model, bool $success): void
    {
        $failuresKey = "assistant:circuit:failures:{$model}";

        if ($success) {
            Cache::forget($failuresKey);

            return;
        }

        $failures = (int) Cache::get($failuresKey, 0) + 1;
        $threshold = (int) config('assistant.circuit_breaker.consecutive_failures_to_open', 3);
        $openSeconds = (int) config('assistant.circuit_breaker.open_seconds', 60);

        if ($failures >= $threshold) {
            Cache::put("assistant:circuit:open_until:{$model}", now()->timestamp + $openSeconds, $openSeconds + 5);
            Cache::forget($failuresKey);
        } else {
            Cache::put($failuresKey, $failures, 3600);
        }
    }

    protected function finish(
        User $user,
        string $role,
        string $source,
        string $lookup,
        string $outcome,
        string $text,
        array $links,
        ?int $resultCount,
        string $answeredBy,
        ?string $refusalReason,
        ?string $interpretedAs = null,
        ?array $suggestions = null,
        ?string $modelUsed = null,
        ?string $errorCode = null,
    ): array {
        AssistantLog::record($user->id, $role, $source, $lookup, $outcome, $refusalReason, $resultCount, $answeredBy, $modelUsed, $errorCode);

        return [
            'answer' => $text,
            'interpreted_as' => $interpretedAs,
            'links' => $links,
            'outcome' => $outcome,
            'suggestions' => $suggestions,
        ];
    }

    /** @return array<int, array{id: string, text: string}> */
    protected function suggestionsList(string $role): array
    {
        return collect(SuggestedQuestions::forRole($role))
            ->map(fn (array $s, string $id) => ['id' => $id, 'text' => $s['text']])
            ->values()
            ->all();
    }
}
