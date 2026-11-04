<?php

namespace App\Modules\Chloe\Assistant;

/**
 * The one seam between the assistant pipeline and whichever LLM answers a
 * question -- GeminiModel (the real provider) and FakeModel (deterministic,
 * every automated test) are the only two implementations, selected by
 * config('assistant.provider'). Adding a different provider later means
 * writing one more class against this interface, not touching the pipeline.
 *
 * PART B: replaces the old two-step chooseTool()-then-composeGuidance()
 * design with ONE call (answer()) that receives the full knowledge text the
 * role may see, so a rephrased question still finds the right answer
 * without a second round trip. A second, optional call (composeAggregate())
 * turns one non-personal lookup's structured result into a short prose
 * answer. Neither method is ever handed a name, student ID, email or
 * database row -- see Assistant\PrivacyMasker, which runs before either is
 * called, and Pipeline, which strips anything the model invents anyway
 * before it is shown to anyone.
 *
 * Round 3 PART A: both methods now take $model and $timeoutSeconds
 * explicitly -- ONE raw attempt against ONE named model, nothing more.
 * Pipeline::callModelWithReliability() is the thing that calls either
 * method more than once (retries, then the fallback model, respecting the
 * circuit breaker and the per-model budget); this interface stays as
 * simple as "try once, report what happened," which is also what makes
 * the retry/fallback/circuit-breaker logic itself testable through
 * FakeModel (see FakeModel::queueFailure()).
 */
interface AssistantModel
{
    /**
     * The ONE-ATTEMPT call for a typed question that deterministic rules
     * did not already resolve. $knowledgeText is every guidance topic's
     * source text the asking role may see (GuidanceLibrary::
     * knowledgeTextForRole() -- already capped/scored, never raw personal
     * data); $lookupCatalogue is Lookups::definitionsForRole()'s own
     * catalogue for that role. $topicHints (PART C.1) are topic ids a
     * keyword-rule match already thinks are likely relevant -- topic ids
     * only, never personal data -- which the model is told to prefer and
     * include among its sources when they genuinely fit.
     *
     * Round 4 PART B.1: $preferCgsTopics is true only when a topic hint
     * is a student topic with a configured CGS counterpart
     * (config('assistant.cgs_topic_counterparts')) for the CGS role --
     * an instruction-level nudge ("this user is CGS staff: answer from
     * the CGS topics") alongside the hints themselves, never a forced
     * override of the model's own judgement.
     *
     * Round 5 PART A: $isCgsRole is unconditional for the CGS role (a
     * VOICE instruction, not a topic-selection one) -- address the
     * staff member, describe the student in the third person, never
     * answer as if the student themselves is reading it.
     *
     * @param  array<int, string>  $recentMaskedQuestions  up to config('assistant.recent_turns') earlier masked USER questions, oldest first -- never answers
     * @param  array<string, array<string, mixed>>  $lookupCatalogue
     * @param  array<int, string>  $topicHints
     * @return array{kind: ?string, answer: ?string, sources: array<int, string>, lookup: ?string, params: array<string, mixed>, outcome: 'ok'|'unavailable', error_code: ?string}
     *         kind is one of 'guidance'|'lookup'|'action'|'out_of_scope'|'clarify', or null if the call itself failed.
     *         error_code is a short string ("500"|"503"|"504"|"429"|"timeout") when outcome is 'unavailable', else null.
     */
    public function answer(string $maskedQuestion, array $recentMaskedQuestions, string $knowledgeText, array $lookupCatalogue, string $model, int $timeoutSeconds, array $topicHints = [], bool $preferCgsTopics = false, bool $isCgsRole = false): array;

    /**
     * The optional second call, for a non-personal AGGREGATE lookup only
     * (see config('assistant.aggregate_lookups')) -- turns $result (the
     * lookup's own structured numbers, nothing else) into two to five
     * plain-English sentences. Never the source of a fact: every number
     * in the reply must already be in $result, or Pipeline discards it
     * and uses the template instead. One attempt against one named model,
     * same as answer() -- Pipeline does not retry or fail this one over
     * to the fallback, since the template is always a complete answer on
     * its own.
     *
     * @param  array<string, mixed>  $result
     * @return array{text: ?string, outcome: 'ok'|'unavailable', error_code: ?string}
     */
    public function composeAggregate(string $maskedQuestion, string $lookupName, array $result, string $model, int $timeoutSeconds): array;

    /**
     * The semantic router call: picks ONE catalogue id (never invents
     * one) for the masked question, with normalised parameters, a
     * confidence, up to 2 alternative ids, and an optional short
     * clarifying question -- structured JSON output via Gemini's
     * generationConfig.responseSchema (the same current, documented
     * mechanism GeminiModel already uses for answer()/composeAggregate(),
     * just a different schema), so "id" is always one of the ids the
     * schema's own enum was built from. One attempt against one named
     * model; Pipeline's own reliability wrapper is what retries/falls
     * back, exactly as it already does for answer().
     *
     * @param  array<int, array<string, mixed>>  $catalogue  Catalogue::forRole()'s own entries for this role
     * @param  array<int, string>  $recentMaskedQuestions  up to config('assistant.recent_turns') earlier masked USER questions, oldest first
     * @param  array<int, array{question: string, id: string}>  $fewShots  invented few-shot examples, catalogue ids only
     * @return array{outcome: 'ok'|'unavailable', id: ?string, params: array<string, mixed>, confidence: ?float, alternatives: array<int, string>, clarify: ?string, error_code: ?string}
     */
    public function route(string $maskedQuestion, array $recentMaskedQuestions, array $catalogue, array $fewShots, string $todayLabel, string $model, int $timeoutSeconds): array;
}
