<?php

namespace App\Modules\Chloe\Assistant;

use App\Modules\Chloe\Models\AssistantLog;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Support\StudentDirectory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

/**
 * The whole ask() pipeline for a typed question:
 *
 *   role check -> length check -> privacy (IDs/emails masked, a known
 *   name refused, an email used as a lookup key asked back) -> an exact
 *   suggested-question text (answered with no model call) -> a
 *   manipulation phrase (refused) -> the MODEL ROUTER (one call: picks
 *   a catalogue id + parameters, never writes an answer) -> our own
 *   validation (id in the role's catalogue, parameters through the
 *   existing validators and PeriodExtractor) -> execution by kind
 *   (lookup template / guidance topic text, optionally composed /
 *   helpful action refusal / out_of_scope refusal) -> "Interpreted as"
 *   and "Did you mean" -> one metadata log row.
 *
 * When the router is unavailable or returns invalid output (after its
 * retries and the fallback model), fallbackAsk() answers instead from
 * the deterministic layer alone -- QuestionNormaliser, the interrogative
 * guard, KeywordRules, PeriodExtractor, Catalogue keyword scores -- with
 * no further model call. Every answer it gives is logged as
 * rule_fallback.
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

    public const ACTION_NO_TARGET_MESSAGE = "I can't perform actions. Open Workstation Management, find the seat or student, and use the Actions menu on the seat.";

    public const ACTION_STUDENT_MESSAGE = "I can't perform actions. You can do this yourself on your Workstation page.";

    public const PRIVACY_NAME_MESSAGE = 'For privacy, please search using a Student ID or seat number.';

    public const EMAIL_NOT_A_KEY_MESSAGE = 'Please use a Student ID or seat number.';

    public const ANYONE_ELSE_MESSAGE = 'I can only share information about your own workstation.';

    /** A student asked something only CGS staff can see (a forecast, a staff-wide count...). */
    public const STAFF_ONLY_MESSAGE = 'That information is only available to CGS staff. I can help with your own workstation, your locker key, the seats available to you, and the Term of Use.';

    public const ROLE_NOT_SUPPORTED_MESSAGE = 'The assistant is not available for your role yet.';

    /** Out of scope, but the question still sounds like it is about this system. */
    public const ASKED_BACK_DOMAIN_MESSAGE = "I'm not sure I understood. You can ask about seats, locker keys, the Term of Use, or use one of the suggested questions.";

    public const OUT_OF_SCOPE_CANDIDACY_MESSAGE = "I can't help with that. You can ask about your candidacy, appeals, reminders and the dismissal list.";

    public const ASKED_BACK_CANDIDACY_MESSAGE = "I'm not sure I understood. You can ask about candidacy expiry, appeals, reminders or dismissal, or use one of the suggested questions.";

    public const MISSING_TOPIC_MESSAGE = 'Sorry, I could not find information on that topic. Please contact the Centre for Graduate Studies (CGS).';

    /** assistant:ask --trace only: masked text, router ids/params and the decisions, never the key, a real ID or a name. Never logged. */
    protected ?array $trace = null;

    /** Set while fallbackAsk() runs, so every row it writes says rule_fallback -- with the router's own model/error code. */
    protected ?string $answeredByOverride = null;

    protected ?string $fallbackModelUsed = null;

    protected ?string $fallbackErrorCode = null;

    /** assistant:evaluate turns the compose calls off so a run measures (and spends quota on) the router alone. */
    protected bool $composeEnabled = true;

    /** The page the question comes from gives a PRIOR only (that domain's entries first); no entry is hidden by it. */
    protected string $domain = 'workstation';

    public function __construct(protected AssistantModel $model) {}

    public function setDomain(?string $domain): static
    {
        $this->domain = AssistantDomains::valid($domain) ? $domain : 'workstation';

        return $this;
    }

    public function enableTrace(): void
    {
        $this->trace = [];
    }

    /** @return array<int, string> */
    public function trace(): array
    {
        return $this->trace ?? [];
    }

    public function disableCompose(): void
    {
        $this->composeEnabled = false;
    }

    protected function traceLine(string $line): void
    {
        if ($this->trace !== null) {
            $this->trace[] = $line;
        }
    }

    /**
     * $bypassKeywordRules: assistant:evaluate's --model-only -- a router
     * failure returns the busy message directly instead of reaching
     * fallbackAsk(), so a run measures the router alone.
     * $allowFallback: false = primary model only (assistant:evaluate and
     * assistant:check default); the chat endpoints always pass true.
     *
     * @return array{answer: string, interpreted_as: ?string, links: array, outcome: string, suggestions: ?array, chips?: array, meta: array}
     */
    public function ask(User $user, string $message, array $recentUserQuestions, bool $bypassKeywordRules = false, bool $allowFallback = true): array
    {
        $this->answeredByOverride = null;
        $role = AssistantDomains::effectiveRole($user, $this->domain);

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

        // Privacy first, before anything can reach the model.
        $masked = PrivacyMasker::mask($message);
        $maskedQuestion = $masked['masked'];
        $idMap = $masked['idMap'];
        $this->traceLine("masked question: {$maskedQuestion}");

        if (PrivacyMasker::containsKnownStudentName($message)) {
            $this->traceLine('decision: refused, a known student name (no model call)');

            return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'privacy_name', AssistantLog::OUTCOME_REFUSED, self::PRIVACY_NAME_MESSAGE, [], null, AssistantLog::ANSWERED_BY_DIRECT, 'name_detected');
        }

        // An email is never a lookup key -- asked back before the router, so it never even travels as a placeholder.
        if (preg_match('/<EMAIL\d+>/', $maskedQuestion)) {
            $this->traceLine('decision: asked back, an email is not a lookup key (no model call)');

            return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'email_not_a_key', AssistantLog::OUTCOME_ASKED_BACK, self::EMAIL_NOT_A_KEY_MESSAGE, [], null, AssistantLog::ANSWERED_BY_DIRECT, 'email_not_a_key');
        }

        // The exact suggested-question texts, answered without any model call.
        $exactSuggestionId = $this->domain === 'workstation' ? SuggestedQuestions::findIdByExactText($message, $role) : null;

        if ($exactSuggestionId !== null) {
            $this->traceLine("decision: exact suggested question {$exactSuggestionId} (no model call)");

            return $this->askSuggestion($user, $exactSuggestionId);
        }

        // the candidacy chips' exact texts: answered by catalogue id, no model call
        if ($this->domain === 'candidacy' && ($candidacyChip = CandidacyAssistant::findIdByExactText($message, $role)) !== null) {
            $this->traceLine("decision: exact candidacy suggested question {$candidacyChip} (no model call)");

            return $this->askCatalogueChip($user, $role, $candidacyChip, []);
        }

        if (ParameterExtractor::hasManipulationPhrase($maskedQuestion)) {
            $this->traceLine('decision: refused, manipulation phrase (no model call)');

            return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'out_of_scope', AssistantLog::OUTCOME_REFUSED, self::OUT_OF_SCOPE_MESSAGE, [], null, AssistantLog::ANSWERED_BY_RULE, 'manipulation_phrase');
        }

        // a question that clearly belongs to the OTHER domain is redirected without any model call
        if (AssistantDomains::belongsToOther(QuestionNormaliser::normalise($maskedQuestion), $this->domain) !== null) {
            $this->traceLine('decision: redirected, the question belongs to the other domain (no model call)');

            return $this->redirect($user, $role, AssistantLog::ANSWERED_BY_RULE);
        }

        $recentTurns = (int) config('assistant.recent_turns', 5);
        $recentMasked = array_values(array_map(
            fn ($q) => PrivacyMasker::mask((string) $q)['masked'],
            array_slice($recentUserQuestions, -$recentTurns)
        ));

        if ($recentMasked !== []) {
            $this->traceLine('recent (masked): '.implode(' | ', $recentMasked));
        }

        if (! $this->anyModelBudgetAvailable($allowFallback)) {
            $this->traceLine('router: skipped, no model budget left');

            return $bypassKeywordRules
                ? $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'unavailable', AssistantLog::OUTCOME_UNAVAILABLE, self::UNAVAILABLE_MESSAGE, [], null, AssistantLog::ANSWERED_BY_DIRECT, 'provider_unavailable', suggestions: $this->suggestionsList($role), errorCode: 'budget')
                : $this->fallbackAsk($user, $role, $maskedQuestion, $idMap, $recentMasked, true, null, 'budget');
        }

        $catalogue = Catalogue::forRole($role, $this->domain);
        $todayLabel = Carbon::now('Asia/Kuala_Lumpur')->format('l, j F Y');

        $routed = $this->callRouterWithReliability($maskedQuestion, $recentMasked, $catalogue, RouterFewShots::all($this->domain), $todayLabel, $allowFallback);

        $this->traceLine('router: '.($routed['outcome'] === 'ok'
            ? json_encode(['id' => $routed['id'], 'params' => (object) $routed['params'], 'confidence' => $routed['confidence'], 'alternatives' => $routed['alternatives'], 'clarify' => $routed['clarify'] ?? ''], JSON_UNESCAPED_SLASHES).' via '.$routed['model_used']
            : 'failed, error '.($routed['error_code'] ?? 'unknown')));

        if ($routed['outcome'] !== 'ok' || $routed['id'] === null) {
            return $bypassKeywordRules
                ? $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'unavailable', AssistantLog::OUTCOME_UNAVAILABLE, self::UNAVAILABLE_MESSAGE, [], null, AssistantLog::ANSWERED_BY_DIRECT, 'provider_unavailable', suggestions: $this->suggestionsList($role), modelUsed: $routed['model_used'] ?? null, errorCode: $routed['error_code'] ?? null)
                : $this->fallbackAsk($user, $role, $maskedQuestion, $idMap, $recentMasked, $routed['error_code'] !== 'invalid_response', $routed['model_used'] ?? null, $routed['error_code'] ?? null);
        }

        return $this->dispatchRouterResult($user, $role, $routed, $maskedQuestion, $idMap, $recentMasked, $allowFallback, $bypassKeywordRules);
    }

    /**
     * Part 2.3-2.5: re-validate what the router chose, execute it by
     * kind, then add "Interpreted as" and the "Did you mean" chips.
     *
     * @param  array{outcome: string, id: string, params: array, confidence: ?float, alternatives: array, clarify: ?string, model_used: ?string}  $routed
     */
    protected function dispatchRouterResult(User $user, string $role, array $routed, string $maskedQuestion, array $idMap, array $recentMasked, bool $allowFallback, bool $bypassKeywordRules): array
    {
        $id = $routed['id'];
        $entry = Catalogue::find($id);
        $modelUsed = $routed['model_used'] ?? null;

        // The id must exist in the ROLE's own catalogue -- a student never
        // gets a CGS entry, whatever the model said. Invalid output.
        if ($entry === null || ! AssistantRoles::covers($role, $entry['roles']) || ! in_array($entry['domain'], [$this->domain, 'any'], true)) {
            $this->traceLine("validation: id {$id} is not in the {$role} {$this->domain} catalogue -> invalid output");

            return $bypassKeywordRules
                ? $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'unavailable', AssistantLog::OUTCOME_UNAVAILABLE, self::UNAVAILABLE_MESSAGE, [], null, AssistantLog::ANSWERED_BY_DIRECT, 'provider_unavailable', suggestions: $this->suggestionsList($role), modelUsed: $modelUsed, errorCode: 'invalid_id')
                : $this->fallbackAsk($user, $role, $maskedQuestion, $idMap, $recentMasked, false, $modelUsed, 'invalid_id');
        }

        $params = $this->validatedParams($entry, $routed['params'] ?? [], $idMap);

        // A short follow-up that itself names a period ("and january?")
        // takes THAT period, whatever the model carried over from the
        // earlier question -- checked, never trusted.
        if (isset($entry['parameters']['period']) && $recentMasked !== [] && str_word_count($maskedQuestion) <= 5) {
            $own = PeriodExtractor::parseDeterministic(QuestionNormaliser::normalise($maskedQuestion));

            if ($own !== null && PeriodExtractor::toString($own) !== ($params['period'] ?? null)) {
                $params['period'] = PeriodExtractor::toString($own);
                $this->traceLine("validation: follow-up names its own period -> {$params['period']}");
            }
        }

        // A student's question that is plainly a CGS-only forecast is
        // never answered with the nearest student lookup instead.
        if ($this->domain === 'workstation' && $role === Role::STUDENT && $entry['kind'] === 'lookup' && self::hasReleaseCue(QuestionNormaliser::normalise($maskedQuestion)) && PeriodExtractor::parseDeterministic(QuestionNormaliser::normalise($maskedQuestion)) !== null) {
            $this->traceLine("validation: a student asked for a release forecast -> not {$id}, staff-only refusal");
            $result = $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'staff_only', AssistantLog::OUTCOME_REFUSED, self::STAFF_ONLY_MESSAGE, [], null, AssistantLog::ANSWERED_BY_MODEL_ROUTER, 'staff_only', suggestions: $this->suggestionsList($role), modelUsed: $modelUsed);

            return [...$result, 'meta' => ['catalogue_id' => 'out_of_scope', 'kind' => 'out_of_scope', 'confidence' => (float) ($routed['confidence'] ?? 0.0), 'alternatives' => [], 'router_params' => $routed['params'] ?? [], 'model_used' => $modelUsed, 'answered_by' => AssistantLog::ANSWERED_BY_MODEL_ROUTER]];
        }

        $confidence = (float) ($routed['confidence'] ?? 0.0);
        $alternatives = array_values(array_filter($routed['alternatives'] ?? [], fn ($alt) => Catalogue::isAllowed($alt, $role, $this->domain) && $alt !== $id));
        // router_params: the router's own params, still MASKED (placeholders, never a real ID) -- for assistant:evaluate's parameter check.
        $meta = ['catalogue_id' => $id, 'kind' => $entry['kind'], 'confidence' => $confidence, 'alternatives' => $alternatives, 'router_params' => $routed['params'] ?? [], 'model_used' => $modelUsed, 'answered_by' => AssistantLog::ANSWERED_BY_MODEL_ROUTER];

        $clarify = $routed['clarify'] ?? null;

        // "If clarify is non-empty and no id is usable": the clarifying
        // question plus the role's suggested-question chips.
        if ($entry['kind'] === 'out_of_scope' && $clarify !== null && trim($clarify) !== '' && ParameterExtractor::hasDomainWord($maskedQuestion)) {
            $this->traceLine('decision: clarify (out_of_scope with a clarifying question)');
            $result = $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'clarify', AssistantLog::OUTCOME_ASKED_BACK, self::stripInventedPersonalData(trim($clarify)), [], null, AssistantLog::ANSWERED_BY_MODEL_ROUTER, null, suggestions: $this->suggestionsList($role), modelUsed: $modelUsed);

            return [...$result, 'meta' => $meta];
        }

        $this->traceLine("decision: {$entry['kind']} {$id}");

        $result = match ($entry['kind']) {
            'lookup' => $this->answerLookup($user, $role, $id, $params, AssistantLog::ANSWERED_BY_MODEL_ROUTER, AssistantLog::SOURCE_TYPED, maskedQuestion: $maskedQuestion, allowCompose: $this->composeEnabled, modelUsed: $modelUsed),
            'guidance' => $this->answerGuidance($user, $role, $id, AssistantLog::SOURCE_TYPED, AssistantLog::ANSWERED_BY_MODEL_ROUTER, $modelUsed, $this->composeEnabled ? $maskedQuestion : null, $recentMasked, $allowFallback),
            'action' => $this->actionRequestResponse($user, $role, $id, $params, AssistantLog::ANSWERED_BY_MODEL_ROUTER, $modelUsed),
            default => $this->outOfScope($user, $role, $maskedQuestion, AssistantLog::ANSWERED_BY_MODEL_ROUTER, $modelUsed),
        };

        if ($entry['kind'] !== 'out_of_scope') {
            $result['interpreted_as'] = Catalogue::interpretedAs($entry, $params);
        }

        // "Did you mean": below the threshold, or the router named an
        // alternative -- best first, at most 3, never for out_of_scope.
        $threshold = (float) config('assistant.router_min_confidence', 0.6);

        if ($entry['kind'] !== 'out_of_scope' && ($confidence < $threshold || $alternatives !== [])) {
            $result['chips'] = $this->chipsFor([$id, ...$alternatives], $params);
        } elseif ($entry['kind'] === 'out_of_scope' && $alternatives !== []) {
            $result['chips'] = $this->chipsFor($alternatives, $params);
        }

        return [...$result, 'meta' => $meta];
    }

    /**
     * Part 2.3: the router's parameters are a hint, never trusted. Only
     * names the entry declares survive. A period must pass
     * PeriodExtractor (a bad one is dropped, so the lookup asks back
     * which month, quarter or year). An ID must be one of THIS
     * question's own placeholders -- mapped back to the real value here,
     * and only here -- or it is dropped; an email placeholder is kept as
     * is so the lookup's own validator asks for a Student ID instead.
     * Everything else (block, room, seat, months, gender) is passed to
     * the existing validators in Lookups::run(), which ask back for a
     * value they do not accept -- never silently widened to "all".
     */
    protected function validatedParams(array $entry, array $params, array $idMap): array
    {
        $out = [];

        foreach (array_intersect_key($params, $entry['parameters']) as $key => $value) {
            if (! is_scalar($value) || $value === '') {
                continue;
            }

            if ($key === 'period') {
                $period = PeriodExtractor::fromString($value);

                if ($period === null) {
                    $this->traceLine('validation: period did not validate -> dropped, the lookup asks back');

                    continue;
                }

                $out['period'] = PeriodExtractor::toString($period);
                $this->traceLine("validation: period ok ({$out['period']})");

                continue;
            }

            if ($key === 'student_id') {
                if (is_string($value) && isset($idMap[$value])) {
                    $out['student_id'] = PrivacyMasker::unmask($value, $idMap);
                    $this->traceLine('validation: student_id placeholder mapped back');
                } elseif (is_string($value) && PrivacyMasker::isEmailPlaceholder($value)) {
                    $out['student_id'] = $value;
                } else {
                    $this->traceLine('validation: student_id is not a placeholder from this question -> dropped');
                }

                continue;
            }

            $out[$key] = match ($key) {
                'seat_number', 'months' => is_numeric($value) ? (int) $value : $value,
                'block', 'room_code' => strtoupper(trim((string) $value)),
                'gender' => mb_strtolower(trim((string) $value)),
                default => $value,
            };
        }

        if (isset($entry['parameters']['period']) && ! isset($out['period'])) {
            $this->traceLine('validation: no valid period -> the lookup asks back');
        }

        return $out;
    }

    /** @return array<int, array{id: string, text: string, params: array}> */
    protected function chipsFor(array $ids, array $params): array
    {
        return collect(array_unique($ids))
            ->map(fn ($cid) => Catalogue::find($cid))
            ->filter(fn ($e) => $e !== null && $e['kind'] !== 'out_of_scope')
            ->take(3)
            ->map(function (array $e) use ($params) {
                $own = array_intersect_key($params, $e['parameters']);

                return ['id' => $e['id'], 'text' => Catalogue::interpretedAs($e, $own), 'params' => (object) $own];
            })
            ->values()
            ->all();
    }

    /**
     * out_of_scope: a student asking for something only CGS can see gets
     * the polite staff-only refusal; a question that still sounds like
     * this system gets the ask-back with the suggested questions; the
     * rest gets the flat refusal.
     */
    protected function outOfScope(User $user, string $role, string $maskedQuestion, string $answeredBy, ?string $modelUsed): array
    {
        // the model (which only sees THIS domain) found no match, and the words belong to the other domain: the redirect, not a generic refusal
        if (AssistantDomains::belongsToOther(QuestionNormaliser::normalise($maskedQuestion), $this->domain) !== null) {
            return $this->redirect($user, $role, $answeredBy, $modelUsed);
        }

        if ($this->domain === 'candidacy') {
            return AssistantDomains::hasStrongTerm(QuestionNormaliser::normalise($maskedQuestion), 'candidacy')
                ? $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'out_of_scope', AssistantLog::OUTCOME_ASKED_BACK, self::ASKED_BACK_CANDIDACY_MESSAGE, [], null, $answeredBy, null, suggestions: $this->suggestionsList($role), modelUsed: $modelUsed)
                : $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'out_of_scope', AssistantLog::OUTCOME_REFUSED, self::OUT_OF_SCOPE_CANDIDACY_MESSAGE, [], null, $answeredBy, 'out_of_scope', modelUsed: $modelUsed);
        }

        if ($role === Role::STUDENT && (ParameterExtractor::seatNumber($maskedQuestion) !== null || ParameterExtractor::studentIdPlaceholder($maskedQuestion) !== null)) {
            $this->traceLine('decision: student named a seat number or ID -> only their own workstation');

            return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'anyone_else', AssistantLog::OUTCOME_REFUSED, self::ANYONE_ELSE_MESSAGE, [], null, $answeredBy, 'anyone_else', modelUsed: $modelUsed);
        }

        if ($role === Role::STUDENT && $this->looksLikeStaffOnlyQuestion($maskedQuestion)) {
            $this->traceLine('decision: student asked for CGS-only information -> staff-only refusal');

            return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'staff_only', AssistantLog::OUTCOME_REFUSED, self::STAFF_ONLY_MESSAGE, [], null, $answeredBy, 'staff_only', suggestions: $this->suggestionsList($role), modelUsed: $modelUsed);
        }

        if (ParameterExtractor::hasDomainWord($maskedQuestion)) {
            return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'out_of_scope', AssistantLog::OUTCOME_ASKED_BACK, self::ASKED_BACK_DOMAIN_MESSAGE, [], null, $answeredBy, null, suggestions: $this->suggestionsList($role), modelUsed: $modelUsed);
        }

        return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'out_of_scope', AssistantLog::OUTCOME_REFUSED, self::OUT_OF_SCOPE_MESSAGE, [], null, $answeredBy, 'out_of_scope', modelUsed: $modelUsed);
    }

    /**
     * True when a student's question matches a CGS-only catalogue entry
     * clearly better than anything in the student's own catalogue (by
     * Catalogue keyword score, or the release-forecast cue plus a
     * period). No model call.
     */
    protected function looksLikeStaffOnlyQuestion(string $maskedQuestion): bool
    {
        if ($this->domain !== 'workstation') {
            return false; // the student/CGS split of the Workstation catalogue; candidacy answers are role-gated by their own entries
        }

        $normalised = QuestionNormaliser::normalise($maskedQuestion);

        if (self::hasReleaseCue($normalised) && PeriodExtractor::parseDeterministic($normalised) !== null) {
            return true;
        }

        $cgsOnly = collect(Catalogue::keywordCandidates($normalised, 'cgs', 1, 2, 'workstation'))
            ->filter(fn ($c) => ! Catalogue::isAllowed($c['id'], Role::STUDENT))
            ->first();

        if ($cgsOnly === null) {
            return false;
        }

        $bestStudent = Catalogue::keywordCandidates($normalised, Role::STUDENT, 1, 1, 'workstation')[0]['score'] ?? 0;

        return $cgsOnly['score'] > $bestStudent;
    }

    protected static function hasReleaseCue(string $normalised): bool
    {
        return (bool) preg_match('/\b(releas\w*|free(d)?\s+up|freed|become\s+free|be\s+free|vacat\w*|empty\s+up|forecast)\b/', $normalised)
            && ! preg_match('/\b(this|last|past)\s+(week|7 days)\b/', $normalised);
    }

    // =====================================================================
    // Part 5: the deterministic fallback
    // =====================================================================

    /**
     * The router was unavailable ($providerDown) or returned invalid
     * output. Answers from the deterministic layer alone, in this order:
     * a student naming someone else's seat/ID -> refused; an imperative
     * (and NOT a question -- the interrogative guard) -> the helpful
     * action refusal; a release question with a period (or a period-only
     * follow-up to one) -> release_forecast; the keyword rules (lookups
     * with their extracted parameters, guidance topics) -> the matching
     * answer; a student asking for CGS-only data -> staff-only refusal;
     * otherwise "Did you mean" chips by catalogue keyword score plus the
     * role's suggested questions. Never another model call.
     */
    protected function fallbackAsk(User $user, string $role, string $maskedQuestion, array $idMap, array $recentMasked, bool $providerDown, ?string $modelUsed, ?string $errorCode): array
    {
        $this->answeredByOverride = AssistantLog::ANSWERED_BY_RULE_FALLBACK;
        $this->fallbackModelUsed = $modelUsed;
        $this->fallbackErrorCode = $errorCode;
        $normalised = QuestionNormaliser::normalise($maskedQuestion);
        $this->traceLine("fallback: normalised question: {$normalised}");

        $result = $this->fallbackDecide($user, $role, $normalised, $maskedQuestion, $idMap, $recentMasked, $providerDown, $modelUsed, $errorCode);
        $this->answeredByOverride = $this->fallbackModelUsed = $this->fallbackErrorCode = null;

        return [...$result, 'meta' => ($result['meta'] ?? []) + ['answered_by' => AssistantLog::ANSWERED_BY_RULE_FALLBACK, 'model_used' => $modelUsed, 'error_code' => $errorCode]];
    }

    protected function fallbackDecide(User $user, string $role, string $normalised, string $maskedQuestion, array $idMap, array $recentMasked, bool $providerDown, ?string $modelUsed, ?string $errorCode): array
    {
        $answeredBy = AssistantLog::ANSWERED_BY_RULE_FALLBACK;
        // the Workstation keyword rules only exist in the Workstation domain; the candidacy keyword layer (CandidacyAssistant::keywordMatch) only in the candidacy one
        $rawMatches = $this->domain === 'workstation' ? KeywordRules::matchAll($normalised) : [];
        $deterministic = $this->domain === 'workstation' ? KeywordRules::extractDeterministic($normalised, $role) : null;
        $deterministicName = $deterministic['name'] ?? null;

        if ($this->domain === 'workstation' && $role === Role::STUDENT
            && (array_intersect($rawMatches, ['seat_lookup', 'student_lookup']) !== [] || in_array($deterministicName, ['seat_lookup', 'student_lookup'], true))) {
            $this->traceLine('fallback: student asked about someone else\'s seat');

            return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'anyone_else', AssistantLog::OUTCOME_REFUSED, self::ANYONE_ELSE_MESSAGE, [], null, $answeredBy, 'anyone_else');
        }

        // An imperative -- never a question (the interrogative guard), never a how-to.
        $actionId = $this->fallbackActionId($normalised, $role);

        if ($actionId === null && $deterministicName === 'action_request') {
            $actionId = Catalogue::actionIdForText($normalised, $role, $this->domain) ?? ($this->domain === 'workstation' ? 'action_other' : null);
        }

        if ($actionId !== null) {
            $params = array_filter([
                'seat_number' => ParameterExtractor::seatNumber($normalised),
                'student_id' => ($p = ParameterExtractor::studentIdPlaceholder($normalised)) !== null ? PrivacyMasker::unmask($p, $idMap) : null,
            ], fn ($v) => $v !== null);
            $this->traceLine("fallback: action {$actionId}");

            return $this->withInterpretation($this->actionRequestResponse($user, $role, $actionId, $params, $answeredBy), $actionId, $params);
        }

        // a plain Study Candidacy question (keyword layer of the fallback): the lookup or topic it names, for THIS role only
        if ($this->domain === 'candidacy' && ($candidacyId = CandidacyAssistant::keywordMatch($normalised, $role)) !== null) {
            $entry = Catalogue::find($candidacyId);
            $this->traceLine("fallback: candidacy {$candidacyId}");
            $answer = match ($entry['kind']) {
                'lookup' => $this->answerLookup($user, $role, $candidacyId, [], $answeredBy),
                'guidance' => $this->answerGuidance($user, $role, $candidacyId, AssistantLog::SOURCE_TYPED, $answeredBy, $modelUsed),
                default => $this->actionRequestResponse($user, $role, $candidacyId, [], $answeredBy),
            };

            return $this->withInterpretation($answer, $candidacyId, []);
        }

        // release_forecast: a release cue with a period, or a bare-period follow-up to one.
        $period = PeriodExtractor::parseDeterministic($normalised);
        $isFollowUp = $period !== null && str_word_count(preg_replace('/\d+/', '', $normalised)) <= 4
            && $recentMasked !== [] && self::hasReleaseCue(QuestionNormaliser::normalise(end($recentMasked)));

        if ($this->domain === 'workstation' && $period !== null && (self::hasReleaseCue($normalised) || $isFollowUp)) {
            if ($role === Role::STUDENT) {
                return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'staff_only', AssistantLog::OUTCOME_REFUSED, self::STAFF_ONLY_MESSAGE, [], null, $answeredBy, 'staff_only', suggestions: $this->suggestionsList($role));
            }

            $params = ['period' => PeriodExtractor::toString($period)];
            $this->traceLine('fallback: release_forecast '.$params['period']);

            return $this->withInterpretation($this->answerLookup($user, $role, 'release_forecast', $params, $answeredBy), 'release_forecast', $params);
        }

        if ($deterministicName === 'out_of_scope') {
            return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'out_of_scope', AssistantLog::OUTCOME_REFUSED, self::OUT_OF_SCOPE_MESSAGE, [], null, $answeredBy, 'out_of_scope');
        }

        // CGS asking a student-phrased guidance question gets the CGS
        // counterpart topic first (config('assistant.cgs_topic_counterparts')).
        if (AssistantRoles::hasCgs($role)) {
            $counterpartMap = config('assistant.cgs_topic_counterparts', []);

            foreach ($deterministicName !== null ? [$deterministicName, ...$rawMatches] : $rawMatches as $name) {
                $studentTopic = str_starts_with($name, 'guidance:') ? substr($name, strlen('guidance:')) : null;

                if ($studentTopic !== null && isset($counterpartMap[$studentTopic]) && ! in_array("guidance:{$counterpartMap[$studentTopic]}", $rawMatches, true)) {
                    array_unshift($rawMatches, "guidance:{$counterpartMap[$studentTopic]}");
                }
            }
        }

        $primaryLookup = null;
        $parameters = [];
        $guidanceTopic = null;

        if ($deterministicName !== null && str_starts_with($deterministicName, 'guidance:') && $this->isAllowedForRole($deterministicName, $role)) {
            $guidanceTopic = substr($deterministicName, strlen('guidance:'));
            $guidanceTopic = AssistantRoles::hasCgs($role) ? (config('assistant.cgs_topic_counterparts', [])[$guidanceTopic] ?? $guidanceTopic) : $guidanceTopic;
        } elseif ($deterministicName !== null && $deterministicName !== 'action_request' && $this->isAllowedForRole($deterministicName, $role)) {
            $primaryLookup = $deterministicName;
            $parameters = $deterministic['parameters'];
        } else {
            $allowed = array_values(array_unique(array_filter($rawMatches, fn (string $n) => $this->isAllowedForRole($n, $role))));
            $primaryLookup = collect($allowed)->first(fn ($n) => ! str_starts_with($n, 'guidance:'));
            $firstGuidance = collect($allowed)->first(fn ($n) => str_starts_with($n, 'guidance:'));
            $guidanceTopic = $primaryLookup === null && $firstGuidance !== null ? substr($firstGuidance, strlen('guidance:')) : null;
        }

        if ($primaryLookup !== null) {
            $parameters = array_map(fn ($v) => is_string($v) ? PrivacyMasker::unmask($v, $idMap) : $v, $parameters);
            $this->traceLine("fallback: lookup {$primaryLookup}");

            return $this->withInterpretation($this->answerLookup($user, $role, $primaryLookup, $parameters, $answeredBy), $primaryLookup, $parameters);
        }

        if ($guidanceTopic !== null) {
            $this->traceLine("fallback: guidance {$guidanceTopic}");

            return $this->withInterpretation($this->answerGuidance($user, $role, $guidanceTopic, AssistantLog::SOURCE_TYPED, $answeredBy, $modelUsed), $guidanceTopic, []);
        }

        if ($this->domain === 'workstation' && $role === Role::STUDENT && $this->looksLikeStaffOnlyQuestion($maskedQuestion)) {
            return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'staff_only', AssistantLog::OUTCOME_REFUSED, self::STAFF_ONLY_MESSAGE, [], null, $answeredBy, 'staff_only', suggestions: $this->suggestionsList($role));
        }

        // Nothing matched: "Did you mean" chips by keyword score, plus the suggested questions.
        // Action entries are left out: clicking one could only ever give a refusal.
        $candidates = array_filter(Catalogue::keywordCandidates($normalised, $role, 6, 2, $this->domain), fn ($c) => Catalogue::find($c['id'])['kind'] !== 'action');
        $chips = $this->chipsFor(array_column($candidates, 'id'), []);
        $this->traceLine('fallback: no match, '.count($chips).' keyword chip(s)');

        $result = $providerDown
            ? $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'unavailable', AssistantLog::OUTCOME_UNAVAILABLE, self::UNAVAILABLE_MESSAGE, [], null, $answeredBy, 'provider_unavailable', suggestions: $this->suggestionsList($role), modelUsed: $modelUsed, errorCode: $errorCode)
            : $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'out_of_scope', AssistantLog::OUTCOME_ASKED_BACK, self::ASKED_BACK_DOMAIN_MESSAGE, [], null, $answeredBy, null, suggestions: $this->suggestionsList($role), modelUsed: $modelUsed, errorCode: $errorCode);

        return $chips === [] ? $result : [...$result, 'chips' => $chips];
    }

    /** The imperative test: not interrogative, not a how-to lead-in, an action pattern, and either a named target or the verb first. */
    protected function fallbackActionId(string $normalised, string $role): ?string
    {
        if (QuestionNormaliser::isInterrogative($normalised) || ParameterExtractor::hasGuidanceLeadIn($normalised)) {
            return null;
        }

        $actionId = Catalogue::actionIdForText($normalised, $role, $this->domain);

        if ($actionId === null) {
            return null;
        }

        $hasTarget = ParameterExtractor::seatNumber($normalised) !== null || ParameterExtractor::studentIdPlaceholder($normalised) !== null;
        $verbFirst = (bool) preg_match('/^(submit|file|send|resend|trigger|export|upload|download|dismiss|decline|accept|reject|return|release|reassign|assign|mark|set|put|disable|restore|terminate|withdraw|cancel|approve|delete|remove|update|change|undo|edit|free|kick|move|give|add|tick|clear|lift|issue|swap|start|bring|un-?disable)\b/', $normalised);

        return $hasTarget || $verbFirst ? $actionId : null;
    }

    /** Adds the "Interpreted as" line for a catalogue id to a fallback answer. */
    protected function withInterpretation(array $result, string $catalogueId, array $params): array
    {
        $entry = Catalogue::find($catalogueId);

        if ($entry !== null && $entry['kind'] !== 'out_of_scope' && in_array($result['outcome'], [AssistantLog::OUTCOME_ANSWERED, AssistantLog::OUTCOME_REFUSED, AssistantLog::OUTCOME_ASKED_BACK], true)) {
            $result['interpreted_as'] = Catalogue::interpretedAs($entry, $params);
            $result['meta'] = ['catalogue_id' => $catalogueId, 'kind' => $entry['kind']];
        }

        return $result;
    }

    // =====================================================================
    // Execution by kind
    // =====================================================================

    /**
     * Guidance: the chosen topic is the primary source. When a masked
     * question is given (the router path, compose enabled) and the
     * budget allows, ONE compose call writes the answer from that
     * topic's text alone; the text is accepted only if it is non-empty
     * and every number in it appears in the topic text or the question
     * (otherwise the model invented it). In every other case -- no
     * budget, provider down, invalid text, a chip click, the fallback --
     * the topic text itself is the answer.
     */
    protected function answerGuidance(User $user, string $role, string $topicId, string $source, string $answeredBy, ?string $modelUsed, ?string $maskedQuestion = null, array $recentMasked = [], bool $allowFallback = true): array
    {
        $sourceText = GuidanceLibrary::isAllowed($topicId, $role, $this->domain) ? GuidanceLibrary::sourceText($topicId) : null;

        if ($sourceText === null) {
            return $this->finish($user, $role, $source, 'guidance:unknown', AssistantLog::OUTCOME_ERROR, self::MISSING_TOPIC_MESSAGE, [], null, AssistantLog::ANSWERED_BY_DIRECT, 'missing_topic', modelUsed: $modelUsed);
        }

        $text = $sourceText;
        $isCandidacy = CandidacyAssistant::isTopic($topicId);

        // a candidacy topic is shown as written, with its own "Source:" line (no model rewrite that could drop it)
        if (! $isCandidacy && $maskedQuestion !== null && $this->anyModelBudgetAvailable($allowFallback)) {
            $knowledge = "### {$topicId}\n{$sourceText}";
            $composed = $this->callModelWithReliability($maskedQuestion, $recentMasked, $knowledge, [], [$topicId], $allowFallback, false, AssistantRoles::hasCgs($role));
            $candidate = self::stripInventedPersonalData(trim((string) ($composed['answer'] ?? '')));

            if ($composed['outcome'] === 'ok' && ($composed['kind'] ?? null) === 'guidance' && $candidate !== '' && ! self::saysNotCovered($candidate) && GuidanceLibrary::everyNumberAppearsIn($candidate, $knowledge.' '.$maskedQuestion)) {
                $text = $candidate;
                $this->traceLine('compose: guidance text accepted from '.$composed['model_used']);
            } else {
                $this->traceLine('compose: not used ('.($composed['outcome'] === 'ok' ? 'failed validation' : 'error '.($composed['error_code'] ?? 'unknown')).') -> topic text');
            }
        }

        return $this->finish($user, $role, $source, "guidance:{$topicId}", AssistantLog::OUTCOME_ANSWERED, $text, $isCandidacy ? CandidacyAssistant::linksForTopic($topicId, $role) : GuidanceLibrary::linksForTopics([$topicId]), null, $answeredBy, null, modelUsed: $modelUsed);
    }

    /**
     * Lookups run through their existing functions and templates --
     * every number comes from the database. An AGGREGATE lookup chosen by
     * the router may get the existing second, composing call (single
     * attempt, primary model only, validated: any number not in the
     * structured result rejects it); a personal lookup never does.
     */
    protected function answerLookup(User $user, string $role, string $lookup, array $parameters, string $answeredBy, string $source = AssistantLog::SOURCE_TYPED, ?string $maskedQuestion = null, bool $allowCompose = false, ?string $modelUsed = null): array
    {
        if (! Lookups::isAllowed($lookup, $role, $this->domain)) {
            return $role === Role::STUDENT && in_array($lookup, ['seat_lookup', 'student_lookup'], true)
                ? $this->finish($user, $role, $source, 'anyone_else', AssistantLog::OUTCOME_REFUSED, self::ANYONE_ELSE_MESSAGE, [], null, $answeredBy, 'anyone_else', modelUsed: $modelUsed)
                : $this->finish($user, $role, $source, 'staff_only', AssistantLog::OUTCOME_REFUSED, self::STAFF_ONLY_MESSAGE, [], null, $answeredBy, 'role_not_permitted', modelUsed: $modelUsed);
        }

        $result = Lookups::run($lookup, $parameters, $user, $role);

        if ($result['outcome'] === 'asked_back') {
            return $this->finish($user, $role, $source, $lookup, AssistantLog::OUTCOME_ASKED_BACK, $result['text'], [], null, $answeredBy, null, modelUsed: $modelUsed);
        }

        $text = $result['text'];
        $primaryModel = (string) config('assistant.gemini.model');
        $composeEstimatedTokens = (int) ceil((strlen($maskedQuestion ?? '') + strlen($lookup) + strlen(json_encode($result) ?: '')) / 4);

        if ($allowCompose && $maskedQuestion !== null && in_array($lookup, config('assistant.aggregate_lookups', []), true) && $this->modelBudgetAvailable($primaryModel, $composeEstimatedTokens)) {
            $this->recordModelCall($primaryModel, $composeEstimatedTokens);
            $timeout = (int) min(10, (int) config('assistant.total_deadline_seconds', 22));
            $aggregate = $this->model->composeAggregate($maskedQuestion, $lookup, $result, $primaryModel, $timeout);

            if ($aggregate['outcome'] === 'ok' && is_string($aggregate['text']) && trim($aggregate['text']) !== '') {
                $candidate = self::stripInventedPersonalData(trim($aggregate['text']));

                if (! self::saysNotCovered($candidate) && GuidanceLibrary::everyNumberAppearsIn($candidate, json_encode($result))) {
                    $text = $candidate;
                    $answeredBy = $answeredBy === AssistantLog::ANSWERED_BY_MODEL_ROUTER ? $answeredBy : AssistantLog::ANSWERED_BY_MODEL_COMPOSE;
                    $this->traceLine('compose: aggregate text accepted');
                } else {
                    $this->traceLine('compose: aggregate text invented a number -> template');
                }
            }
        }

        if (empty($result['skipFooter'])) {
            $text .= "\n\nBased on live data at ".Carbon::now('Asia/Kuala_Lumpur')->format('j M Y, g:ia').'.';
        }

        return $this->finish($user, $role, $source, $lookup, AssistantLog::OUTCOME_ANSWERED, $text, $result['links'] ?? [], $result['resultCount'] ?? null, $answeredBy, null, modelUsed: $modelUsed);
    }

    /**
     * Actions are never executed. The refusal says where to do it
     * yourself: a valid seat number -> that seat's page; a known
     * student ID -> the seat that student holds (or that they hold
     * none); an unknown ID -> says so; no target -> Workstation
     * Management. A student is pointed at their own Workstation page.
     */
    protected function actionRequestResponse(User $user, string $role, string $actionId, array $parameters, string $answeredBy, ?string $modelUsed = null): array
    {
        if (str_starts_with($actionId, 'cand_action_')) {
            $refusal = CandidacyAssistant::refusal($actionId, $role);

            return $this->finish($user, $role, AssistantLog::SOURCE_TYPED, $actionId, AssistantLog::OUTCOME_REFUSED, $refusal['text'], $refusal['links'], null, $answeredBy, 'action_request', modelUsed: $modelUsed);
        }

        $log = fn (string $text, array $links, string $reason) => $this->finish($user, $role, AssistantLog::SOURCE_TYPED, $actionId, AssistantLog::OUTCOME_REFUSED, $text, $links, null, $answeredBy, $reason, modelUsed: $modelUsed);

        if ($role === Role::STUDENT) {
            return $log(self::ACTION_STUDENT_MESSAGE, [['label' => 'Workstation page', 'url' => route('workstation.select')]], 'action_request');
        }

        $seatNumber = isset($parameters['seat_number']) ? (int) $parameters['seat_number'] : null;

        if ($seatNumber !== null && $seatNumber >= 1 && $seatNumber <= 315 && Workstation::whereKey($seatNumber)->exists()) {
            return $log(self::ACTION_REFUSED_MESSAGE, [['label' => 'Seat Details', 'url' => route('workstation.cgs.seat', $seatNumber)]], 'action_request');
        }

        $studentId = $parameters['student_id'] ?? null;

        if (is_string($studentId) && preg_match('/^\d{8}$/', $studentId)) {
            $identity = StudentDirectory::findByStudentId($studentId);

            if ($identity === null) {
                return $log("I can't perform actions, and no student was found with ID {$studentId}. Please check the ID, or search in Workstation Management.", [['label' => 'Workstation Management', 'url' => route('workstation.cgs.operations')]], 'action_unknown_id');
            }

            $request = WorkstationRequest::query()
                ->when($identity->user, fn ($q) => $q->where('student_id', $identity->user->id))
                ->when($identity->demoProfile, fn ($q) => $q->where('demo_profile_id', $identity->demoProfile->id))
                ->where('status', WorkstationRequest::STATUS_CONFIRMED)
                ->latest('requested_at')
                ->first();

            if ($request !== null) {
                return $log("I can't perform actions. Student {$studentId}'s seat is open below -- you can continue from there.", [['label' => 'Seat Details', 'url' => route('workstation.cgs.seat', $request->workstation_id)]], 'action_request');
            }

            return $log("I can't perform actions. Student {$studentId} does not currently hold a workstation -- you can assign one from Workstation Management.", [['label' => 'Workstation Management', 'url' => route('workstation.cgs.operations')]], 'action_request');
        }

        return $log(self::ACTION_NO_TARGET_MESSAGE, [['label' => 'Workstation Management', 'url' => route('workstation.cgs.operations')]], 'action_request');
    }

    /** A compose text that says its source does not answer the question is worse than the source itself -- rejected, and the topic text or template is used instead. */
    protected static function saysNotCovered(string $text): bool
    {
        return (bool) preg_match('/\b(does not|doesn\'t|do not|don\'t)\s+(cover|contain|mention|include|say|provide|specify|have)\b|\bnot (covered|mentioned|specified)\b|\bno information\b/i', $text);
    }

    /** Never allowed in anything the model writes -- a URL, an 8-digit ID, or an email, even a real one it happened to see in the question, is removed rather than trusted. */
    public static function stripInventedPersonalData(string $text): string
    {
        $text = preg_replace('#https?://\S+#i', '[link removed]', $text) ?? $text;
        $text = preg_replace('/\b\d{8}\b/', '[ID removed]', $text) ?? $text;
        $text = preg_replace('/\b[\w.+-]+@[\w-]+\.[\w.-]+\b/i', '[email removed]', $text) ?? $text;

        return $text;
    }

    // =====================================================================
    // Suggestion chips and "Did you mean" chips -- never the model
    // =====================================================================

    /**
     * POST .../suggestion: a curated suggestion id (s1_rules...), or a
     * "Did you mean" chip's catalogue id with its params. No model call,
     * no quota, either way.
     */
    public function askSuggestion(User $user, string $suggestionId, array $params = []): array
    {
        $this->answeredByOverride = null;
        $role = AssistantDomains::effectiveRole($user, $this->domain);

        if ($role === null) {
            return $this->finish($user, $user->role, AssistantLog::SOURCE_SUGGESTION, 'role_not_supported', AssistantLog::OUTCOME_REFUSED, self::ROLE_NOT_SUPPORTED_MESSAGE, [], null, AssistantLog::ANSWERED_BY_DIRECT, 'role_not_supported');
        }

        $suggestion = $this->domain === 'workstation' ? SuggestedQuestions::find($suggestionId, $role) : null;

        if ($suggestion === null) {
            return $this->askCatalogueChip($user, $role, $suggestionId, $params);
        }

        $meta = ['catalogue_id' => $suggestion['catalogue_id'], 'kind' => Catalogue::find($suggestion['catalogue_id'])['kind'] ?? null, 'answered_by' => AssistantLog::ANSWERED_BY_DIRECT];

        if ($suggestion['type'] === 'static') {
            $text = SuggestedAnswers::staticText($suggestion['target']);

            if ($text === null) {
                return $this->finish($user, $role, AssistantLog::SOURCE_SUGGESTION, "suggestion:{$suggestionId}", AssistantLog::OUTCOME_ERROR, self::MISSING_TOPIC_MESSAGE, [], null, AssistantLog::ANSWERED_BY_DIRECT, 'missing_topic');
            }

            return [...$this->finish($user, $role, AssistantLog::SOURCE_SUGGESTION, "suggestion:{$suggestionId}", AssistantLog::OUTCOME_ANSWERED, $text, [], null, AssistantLog::ANSWERED_BY_DIRECT, null), 'meta' => $meta];
        }

        $dynamic = SuggestedAnswers::dynamic($suggestion['target'], $user, $role);

        return [...$this->finish($user, $role, AssistantLog::SOURCE_SUGGESTION, "suggestion:{$suggestionId}", AssistantLog::OUTCOME_ANSWERED, $dynamic['text'], $dynamic['links'], null, AssistantLog::ANSWERED_BY_DIRECT, null), 'meta' => $meta];
    }

    /**
     * A "Did you mean" chip click, by catalogue id. The params come from
     * the browser, so they are validated exactly like the router's own:
     * declared names only, a period through PeriodExtractor, a seat
     * number in range, a student ID as 8 digits -- never trusted.
     */
    protected function askCatalogueChip(User $user, string $role, string $catalogueId, array $params): array
    {
        $entry = Catalogue::find($catalogueId);

        if ($entry === null || ! AssistantRoles::covers($role, $entry['roles']) || ! in_array($entry['domain'], [$this->domain, 'any'], true)) {
            return $this->finish($user, $role, AssistantLog::SOURCE_SUGGESTION, 'unknown_suggestion', AssistantLog::OUTCOME_ERROR, 'That suggested question is not available.', [], null, AssistantLog::ANSWERED_BY_DIRECT, 'unknown_suggestion');
        }

        // A chip's student_id is the real ID the user typed (already
        // unmasked server-side when the chip was built) -- accepted only
        // in the plain 8-digit shape, so map it through an identity
        // "placeholder" table for validatedParams().
        $idMap = [];
        if (is_string($params['student_id'] ?? null) && preg_match('/^\d{8}$/', $params['student_id'])) {
            $idMap[$params['student_id']] = $params['student_id'];
        }
        $params = $this->validatedParams($entry, $params, $idMap);

        $result = match ($entry['kind']) {
            'lookup' => $this->answerLookup($user, $role, $entry['id'], $params, AssistantLog::ANSWERED_BY_DIRECT, AssistantLog::SOURCE_SUGGESTION),
            'guidance' => $this->answerGuidance($user, $role, $entry['id'], AssistantLog::SOURCE_SUGGESTION, AssistantLog::ANSWERED_BY_DIRECT, null),
            'action' => $this->actionRequestResponse($user, $role, $entry['id'], $params, AssistantLog::ANSWERED_BY_DIRECT),
            default => $this->finish($user, $role, AssistantLog::SOURCE_SUGGESTION, 'out_of_scope', AssistantLog::OUTCOME_REFUSED, self::OUT_OF_SCOPE_MESSAGE, [], null, AssistantLog::ANSWERED_BY_DIRECT, 'out_of_scope'),
        };

        if ($entry['kind'] !== 'out_of_scope') {
            $result['interpreted_as'] = Catalogue::interpretedAs($entry, $params);
        }

        return [...$result, 'meta' => ['catalogue_id' => $entry['id'], 'kind' => $entry['kind'], 'answered_by' => AssistantLog::ANSWERED_BY_DIRECT]];
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

    /**
     * Part 2.7: the router's own reliability sequence -- the exact same
     * retry/backoff/circuit-breaker/fallback-model/per-model-budget
     * shape as callModelWithReliability(), just calling
     * AssistantModel::route() instead of answer(). At most 1 router
     * call per typed question (plus, separately, at most 1 compose call
     * -- see answerLookup()'s own composeAggregate() path, unchanged).
     *
     * @param  array<int, array<string, mixed>>  $catalogue
     * @param  array<int, array{question: string, id: string}>  $fewShots
     * @return array{outcome: string, id: ?string, params: array, confidence: ?float, alternatives: array, clarify: ?string, error_code: ?string, model_used: ?string}
     */
    protected function callRouterWithReliability(string $maskedQuestion, array $recentMasked, array $catalogue, array $fewShots, string $todayLabel, bool $allowFallback): array
    {
        $deadline = microtime(true) + (int) config('assistant.total_deadline_seconds', 22);
        $primaryModel = (string) config('assistant.gemini.model');
        $fallbackModel = (string) config('assistant.fallback_model');
        $waits = config('assistant.retry_waits_seconds', [2, 5]);
        $lastErrorCode = null;
        $estimatedTokens = (int) ceil((strlen($maskedQuestion) + strlen(implode(' ', $recentMasked)) + strlen(json_encode($catalogue) ?: '') + strlen(json_encode($fewShots) ?: '')) / 4);

        if (! $this->circuitIsOpen($primaryModel)) {
            $maxAttempts = 1 + count($waits);

            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                $timeout = $this->attemptTimeout($deadline);

                if ($timeout === null || ! $this->modelBudgetAvailable($primaryModel, $estimatedTokens)) {
                    break;
                }

                $this->recordModelCall($primaryModel, $estimatedTokens);
                $result = $this->model->route($maskedQuestion, $recentMasked, $catalogue, $fewShots, $todayLabel, $primaryModel, $timeout);

                if ($result['outcome'] === 'ok') {
                    $this->recordModelOutcome($primaryModel, true);

                    return [...$result, 'model_used' => $primaryModel];
                }

                $this->recordModelOutcome($primaryModel, false);
                $lastErrorCode = $result['error_code'] ?? null;

                if ($lastErrorCode === '429') {
                    break;
                }

                if ($attempt < count($waits) && $this->attemptTimeout($deadline, $waits[$attempt]) !== null) {
                    Sleep::sleep($waits[$attempt]);
                } else {
                    break;
                }
            }
        }

        if (! $allowFallback) {
            return ['outcome' => 'unavailable', 'id' => null, 'params' => [], 'confidence' => null, 'alternatives' => [], 'clarify' => null, 'error_code' => $lastErrorCode, 'model_used' => null];
        }

        $timeout = $this->attemptTimeout($deadline);

        if ($timeout !== null && $this->modelBudgetAvailable($fallbackModel, $estimatedTokens)) {
            $this->recordModelCall($fallbackModel, $estimatedTokens);
            $result = $this->model->route($maskedQuestion, $recentMasked, $catalogue, $fewShots, $todayLabel, $fallbackModel, $timeout);

            if ($result['outcome'] === 'ok') {
                $this->recordModelOutcome($fallbackModel, true);

                return [...$result, 'model_used' => $fallbackModel];
            }

            $this->recordModelOutcome($fallbackModel, false);
            $lastErrorCode = $result['error_code'] ?? $lastErrorCode;
        }

        return ['outcome' => 'unavailable', 'id' => null, 'params' => [], 'confidence' => null, 'alternatives' => [], 'clarify' => null, 'error_code' => $lastErrorCode, 'model_used' => null];
    }

    /** The assistant's own two-role model -- 'student' | 'cgs' | null (everyone else). Shared with the controller's suggestions() so both agree on who gets an empty list vs. the refusal message. */
    public static function normalizeRole(string $dbRole): ?string
    {
        if ($dbRole === Role::STUDENT) {
            return Role::STUDENT;
        }

        if (in_array($dbRole, [Role::SUPERVISOR, Role::CHAIR, Role::DEAN_PGR], true)) {
            return AssistantRoles::APPROVER;
        }

        return Role::isCgs($dbRole) ? 'cgs' : null;
    }

    protected function isAllowedForRole(string $name, string $role): bool
    {
        if (str_starts_with($name, 'guidance:')) {
            return GuidanceLibrary::isAllowed(substr($name, strlen('guidance:')), $role, $this->domain);
        }

        return Lookups::isAllowed($name, $role, $this->domain);
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
        // Metadata only: the catalogue id (in lookup) and its kind, the
        // outcome, who decided, the model and the error code -- never
        // any question or answer text.
        AssistantLog::record($user->id, $role, $source, $lookup, $outcome, $refusalReason, $resultCount, $this->answeredByOverride ?? $answeredBy, $modelUsed ?? $this->fallbackModelUsed, $errorCode ?? $this->fallbackErrorCode, Catalogue::kindForLogLookup($lookup), $this->domain);

        return [
            'answer' => $text,
            'interpreted_as' => $interpretedAs,
            'links' => $links,
            'outcome' => $outcome,
            'suggestions' => $suggestions,
        ];
    }

    /** The other domain's question asked here: a short redirect with a link to the user's page there, and the CURRENT domain's suggested questions below it. */
    protected function redirect(User $user, string $role, string $answeredBy, ?string $modelUsed = null): array
    {
        $result = $this->finish($user, $role, AssistantLog::SOURCE_TYPED, 'wrong_domain', AssistantLog::OUTCOME_REFUSED, AssistantDomains::redirectMessage($this->domain), AssistantDomains::redirectLinks($user, $this->domain), null, $answeredBy, 'wrong_domain', suggestions: $this->suggestionsList($role), modelUsed: $modelUsed);

        return [...$result, 'meta' => ['catalogue_id' => 'wrong_domain', 'kind' => null, 'answered_by' => $answeredBy]];
    }

    /** @return array<int, array{id: string, text: string}> */
    protected function suggestionsList(string $role): array
    {
        return collect(SuggestedQuestions::forDomain($role, $this->domain))
            ->map(fn (array $s, string $id) => ['id' => $id, 'text' => $s['text']])
            ->values()
            ->all();
    }
}
