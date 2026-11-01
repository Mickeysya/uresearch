<?php

namespace App\Modules\Chloe\Assistant;

use App\Modules\Core\Support\Role;
use Illuminate\Support\Str;

/**
 * The single source of truth for every question the assistant can
 * answer: every lookup (Lookups::cgsNames()/studentNames()), every
 * guidance topic (GuidanceLibrary::topicsForRole()), one entry per kind
 * of CGS action, and out_of_scope. Built FROM those registries (not
 * duplicated by hand) so a lookup or topic that exists in code but not
 * here, or here but not in code, fails CatalogueCompletenessTest in
 * both directions -- the catalogue can never silently drift from what
 * the code actually does.
 *
 * Every entry is also what:
 *  - the suggested-question chips show (the 12 existing suggestions are
 *    simply the catalogue entries their own ids point to -- their exact
 *    current wording is reused verbatim as those entries' canonical
 *    question, never reworded);
 *  - the router prompt's own per-role catalogue is built from;
 *  - the "Interpreted as" line fills its {placeholders} from;
 *  - the "Did you mean" chips' own text comes from;
 *  - CatalogueCompletenessTest and the evaluation set's id labels check
 *    against.
 *
 * Entry shape:
 *   id: the lookup name / guidance topic / action key -- never invented,
 *       always exactly the identifier the code already uses.
 *   kind: 'lookup' | 'guidance' | 'action' | 'out_of_scope'
 *   roles: ['student'] | ['cgs'] | ['student','cgs']
 *   question: ONE canonical English question in the role's voice, with
 *       any slot shown inline in braces, e.g. "...in {period}?".
 *   description: one line for the router prompt -- what this answers
 *       and, where two entries could be confused, a contrast hint
 *       naming the other entry explicitly.
 *   examples: 4-6 INVENTED paraphrases, messy/informal ones included,
 *       never a real name, ID or email (an ID is always the "<ID1>"
 *       placeholder shape the router itself receives). They ARE sent to
 *       the model, as part of the role's catalogue in the router prompt
 *       -- which is also why no evaluation or live-check question is
 *       ever copied in here (CatalogueTest checks that).
 *   parameters: param name => ['type', 'required', 'hint'] -- a HINT to
 *       the model only; every value is independently re-validated
 *       before use (Pipeline / PeriodExtractor / the existing lookup
 *       validators), exactly as it already is for the legacy keyword
 *       path.
 *   link: a route name (resolved with the entry's own params where the
 *       route needs them) or null.
 *   summary (guidance only): the first one or two sentences of the
 *       topic's own knowledge text (GuidanceLibrary::sourceText()),
 *       never written by hand.
 *   template (lookup only): the name of the existing Lookups method
 *       whose template answers it -- CatalogueTest checks it exists.
 *   pattern (action only): the regex the Part 5 fallback uses to
 *       recognise this operation in an imperative sentence; every
 *       config('assistant.action_phrases') verb must be matched by one.
 *
 * The canonical question of an entry that a suggested-question chip
 * points at (SuggestedQuestions::textsByCatalogueId()) is that chip's
 * own exact text -- one canonical question per entry, never two
 * wordings.
 */
final class Catalogue
{
    /** @var array<int, array<string, mixed>>|null */
    protected static ?array $cache = null;

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return self::$cache ??= self::finalise([
            ...self::lookupEntries(),
            ...self::guidanceEntries(),
            ...self::actionEntries(),
            ...CandidacyAssistant::entries(),
            [
                'id' => 'out_of_scope',
                'kind' => 'out_of_scope',
                'roles' => [Role::STUDENT, 'cgs', 'approver'],
                'question' => '',
                'description' => 'The question is not about the Workstation/locker-key system or Study Candidacy at all, or is unrelated small talk -- or it asks for something no entry in THIS catalogue answers (for a student: CGS-only information such as release forecasts, other students, or staff-wide statistics).',
                'examples' => ['what is the weather today', 'tell me a joke', 'who won the football last night', 'how do i reset my email password', 'whats for lunch'],
                'parameters' => [],
                'link' => null,
            ],
        ]);
    }

    /**
     * Applies the derived fields: a suggested question's exact text as
     * its entry's canonical question, the guidance summaries, the lookup
     * template names.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    protected static function finalise(array $entries): array
    {
        $suggestionTexts = SuggestedQuestions::textsByCatalogueId();

        foreach ($entries as &$entry) {
            $entry['domain'] ??= 'workstation';

            if (isset($suggestionTexts[$entry['id']])) {
                $entry['question'] = $suggestionTexts[$entry['id']];
            }
            if ($entry['kind'] === 'guidance') {
                $entry['summary'] = self::summaryFromTopicText($entry['id']);
            }
            if ($entry['kind'] === 'lookup') {
                $entry['template'] ??= Str::camel($entry['id']);
            }
        }

        return $entries;
    }

    /** The first one or two sentences of a topic's own text, markdown stripped, at most ~240 characters. */
    protected static function summaryFromTopicText(string $topic): string
    {
        $text = GuidanceLibrary::sourceText($topic) ?? '';
        $text = preg_replace('/[*_#`]+|^\s*[•\-]\s*/mu', '', $text) ?? $text;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        preg_match_all('/[^.!?]+[.!?]+(\s|$)/u', $text, $m);
        $sentences = array_map('trim', $m[0]);
        $summary = $sentences[0] ?? $text;

        if (isset($sentences[1]) && mb_strlen($summary.' '.$sentences[1]) <= 240) {
            $summary .= ' '.$sentences[1];
        }

        return mb_strlen($summary) > 240 ? rtrim(mb_substr($summary, 0, 237)).'...' : $summary;
    }

    /** @return array<int, array<string, mixed>> */
    public static function forRole(string $role, ?string $domain = null): array
    {
        $entries = array_values(array_filter(self::all(), fn ($e) => AssistantRoles::covers($role, $e['roles'])));

        if ($domain !== null) {
            // the current page only gives a PRIOR: that domain's entries first (stable), nothing is removed
            usort($entries, fn ($a, $b) => (($a['domain'] === $domain) ? 0 : 1) <=> (($b['domain'] === $domain) ? 0 : 1));
        }

        return $entries;
    }

    public static function find(string $id): ?array
    {
        foreach (self::all() as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        return null;
    }

    public static function isAllowed(string $id, string $role): bool
    {
        $entry = self::find($id);

        return $entry !== null && AssistantRoles::covers($role, $entry['roles']);
    }

    /**
     * Part 5 fallback: which action entry an IMPERATIVE sentence names,
     * or null. Checked most-specific first (a "release hold" is not a
     * plain release, a "reassign" is not an "assign"). The caller is
     * responsible for the interrogative guard and the how-to lead-ins --
     * this only answers "which operation", never "is it an action".
     */
    public static function actionIdForText(string $normalisedText, string $role): ?string
    {
        foreach (self::ACTION_MATCH_ORDER as $id) {
            $entry = self::find($id);

            if ($entry !== null && AssistantRoles::covers($role, $entry['roles']) && preg_match($entry['pattern'], $normalisedText)) {
                return $id;
            }
        }

        return null;
    }

    /** Most specific first -- see actionIdForText(). */
    public const ACTION_MATCH_ORDER = [
        'action_release_hold', 'action_reassign', 'action_assign', 'action_mark_key_collected',
        'action_mark_key_returned', 'action_set_maintenance', 'action_restore',
        'action_terminate_or_withdraw', 'action_cancel_request',
        'cand_action_decide', 'cand_action_submit', 'cand_action_reminders', 'cand_action_export_upload', 'cand_action_dismiss',
        'action_release', 'action_other',
    ];

    protected const STOPWORDS = [
        'the', 'and', 'for', 'you', 'your', 'are', 'can', 'how', 'what', 'which', 'when', 'where', 'who', 'why',
        'does', 'did', 'will', 'would', 'should', 'could', 'have', 'has', 'with', 'this', 'that', 'there', 'about',
        'any', 'many', 'much', 'please', 'tell', 'give', 'show', 'need', 'want', 'into', 'from', 'get', 'got', 'not',
        'all', 'its', 'our', 'may', 'now', 'pls', 'one', 'some', 'list', 'mean', 'means', 'happens', 'happen',
    ];

    /**
     * Part 5 fallback's "Did you mean" chips: entries of this role
     * ranked by how many significant words of the question appear in
     * their canonical question or example paraphrases (crudely stemmed),
     * at least $minScore shared words, best first. Pure word overlap,
     * no model.
     *
     * @return array<int, array{id: string, score: int}>
     */
    public static function keywordCandidates(string $normalisedText, string $role, int $limit = 3, int $minScore = 2): array
    {
        $words = self::significantWords($normalisedText);

        if ($words === []) {
            return [];
        }

        $scored = [];

        foreach (self::forRole($role) as $entry) {
            if ($entry['kind'] === 'out_of_scope') {
                continue;
            }

            $entryWords = array_flip(self::significantWords($entry['question'].' '.implode(' ', $entry['examples']).' '.str_replace('_', ' ', $entry['id'])));
            $score = count(array_filter($words, fn ($w) => isset($entryWords[$w])));

            if ($score >= $minScore) {
                $scored[] = ['id' => $entry['id'], 'score' => $score];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /** @return array<int, string> unique, crudely stemmed words of 3+ letters, stopwords removed. */
    protected static function significantWords(string $text): array
    {
        $tokens = preg_split('/[^a-z0-9]+/', mb_strtolower($text)) ?: [];
        $out = [];

        foreach ($tokens as $t) {
            if (mb_strlen($t) < 3 || in_array($t, self::STOPWORDS, true)) {
                continue;
            }

            $out[] = preg_replace('/(ing|ed|es|s)$/', '', $t) ?: $t;
        }

        return array_values(array_unique($out));
    }

    /**
     * Part 2.5: the entry's own canonical question, with every {slot}
     * filled from $params -- "How many seats may be released in
     * {period}?" + period "2026-11" -> "...in November 2026?". Any OTHER
     * param present (an optional block/gender the router narrowed by,
     * not named in the question template) is appended as a short,
     * uniform ", for ..." suffix rather than spliced into the sentence.
     * A slot with no value reads "the given <slot>".
     */
    public static function interpretedAs(array $entry, array $params): string
    {
        $question = $entry['question'];
        $usedKeys = [];

        $question = preg_replace_callback('/\{([a-z_]+)\}/i', function ($m) use ($params, &$usedKeys) {
            $key = $m[1];
            $usedKeys[] = $key;

            $value = array_key_exists($key, $params) ? self::formatParam($key, $params[$key]) : '';

            return $value !== '' ? $value : 'the given '.str_replace('_', ' ', $key);
        }, $question) ?? $question;

        $extras = collect($params)
            ->only(array_keys($entry['parameters']))
            ->except($usedKeys)
            ->map(fn ($v, $k) => self::formatParam($k, $v))
            ->filter(fn ($v) => $v !== '')
            ->values()
            ->all();

        if ($extras !== []) {
            $question = rtrim($question, '?!. ').', for '.implode(', ', $extras).'?';
        }

        return trim(preg_replace('/\s{2,}/', ' ', $question) ?? $question);
    }

    protected static function formatParam(string $key, mixed $value): string
    {
        if ($key === 'period') {
            $period = PeriodExtractor::fromString($value);

            return $period !== null ? PeriodExtractor::label($period) : '';
        }

        if ($key === 'block' && is_string($value) && $value !== '') {
            return 'Block '.strtoupper($value);
        }

        if ($key === 'gender' && is_string($value) && $value !== '') {
            return mb_strtolower($value).' rooms';
        }

        if ($key === 'months' && is_numeric($value)) {
            return (string) (int) $value;
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * The assistant_logs.lookup value -> its catalogue kind, for the
     * catalogue_kind column: a lookup name, "guidance:<topic>", an
     * action id, or "out_of_scope". Anything else (a refusal type such
     * as "privacy_name") has no kind -- null.
     */
    public static function kindForLogLookup(string $lookup): ?string
    {
        $id = str_starts_with($lookup, 'guidance:') ? substr($lookup, strlen('guidance:')) : $lookup;

        return self::find($id)['kind'] ?? null;
    }

    /** The catalogue id a log lookup value stands for ("guidance:key_lost" -> "key_lost"), or null. */
    public static function idForLogLookup(?string $lookup): ?string
    {
        if ($lookup === null) {
            return null;
        }

        $id = str_starts_with($lookup, 'guidance:') ? substr($lookup, strlen('guidance:')) : $lookup;

        return self::find($id) !== null ? $id : null;
    }

    // =====================================================================
    // LOOKUPS -- every id here must exist in Lookups::cgsNames()/
    // studentNames(), and every name in those two lists must appear here
    // (CatalogueCompletenessTest checks both directions).
    // =====================================================================

    /** @return array<int, array<string, mixed>> */
    protected static function lookupEntries(): array
    {
        return [
            [
                'id' => 'my_workstation', 'kind' => 'lookup', 'roles' => [Role::STUDENT],
                'question' => 'What is my workstation, and when does my loan end?',
                'description' => "The student's own block, room, seat, loan-valid-until date, and any termination notice.",
                'examples' => ['where is my seat', 'what seat do i have', 'when does my loan end', 'wat workstation i got', 'do i have a notice on my seat'],
                'parameters' => [], 'link' => 'workstation.select',
            ],
            [
                'id' => 'my_locker_key', 'kind' => 'lookup', 'roles' => [Role::STUDENT],
                'question' => 'What is my locker key status and deadline?',
                'description' => "The student's own locker key status and dates. Contrast: not locker_key_request/locker_key_collect_return (how-to guidance) -- this is the student's OWN current status.",
                'examples' => ['where is my key status', 'when must i collect my key', 'is my key return pending', 'wen i need return key', 'key status pls'],
                'parameters' => [], 'link' => 'workstation.select',
            ],
            [
                'id' => 'available_seats', 'kind' => 'lookup', 'roles' => [Role::STUDENT],
                'question' => 'How many seats are available in my gender\'s rooms?',
                'description' => "Available seat counts per room RIGHT NOW, in the student's own gender's rooms only. Contrast: never a future or forecast question (how many seats WILL be released / freed next month) -- students cannot see forecasts, so that is out_of_scope.",
                'examples' => ['any seats free', 'how many seats left', 'got empty seat or not', 'seats available for me', 'free seat count'],
                'parameters' => [], 'link' => 'workstation.select',
            ],
            [
                'id' => 'block_comparison', 'kind' => 'lookup', 'roles' => [Role::STUDENT, 'cgs'],
                'question' => 'How do Block N2 and Block J2 compare?',
                'description' => 'Compares Block N2 and Block J2 side by side -- which is fuller, and the seat counts for each. A student sees only their own gender; CGS sees both.',
                'examples' => ['n2 or j2 which fuller', 'compare the two blocks', 'which block more packed', 'which block has more space', 'block n2 vs j2'],
                'parameters' => [], 'link' => 'workstation.cgs.operations',
            ],
            [
                'id' => 'seat_availability', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'How many seats are Available, Occupied, Maintenance or Awaiting Key Return?',
                'description' => 'Available/Occupied/Maintenance/Awaiting-Key-Return counts per room, optionally narrowed to one block. Contrast: not seat_status_by_block (that compares every block side by side at once).',
                'examples' => ['how many free in n2', 'status count for block j2', 'seats breakdown pls', 'occupied vs available seats', 'how many empty seats overall'],
                'parameters' => ['block' => ['type' => 'string', 'required' => false, 'hint' => '"N2" or "J2"'], 'room_code' => ['type' => 'string', 'required' => false, 'hint' => 'block-floor-room format, e.g. "J2-00-00"']],
                'link' => 'workstation.cgs.operations',
            ],
            [
                'id' => 'overall_stats', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'What are the overall seat totals?',
                'description' => 'Total seat counts across the whole catalogue: Available, Occupied, Maintenance, Awaiting Key Return, keys pending.',
                'examples' => ['overall numbers pls', 'total seats how many', 'give me the big picture', 'how many seats total got', 'stats summary'],
                'parameters' => [], 'link' => 'workstation.cgs.operations',
            ],
            [
                'id' => 'seat_lookup', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'What is the status of Seat {seat_number}?',
                'description' => 'Status, occupant and key status for ONE seat number. Contrast: a bare "release seat 252" with no question word is the action entry release, not this.',
                'examples' => ['whats seat 252 status', 'who is in seat 11', 'seat #87 details', 'check seat 140 for me', 'seat 303 occupied or not'],
                'parameters' => ['seat_number' => ['type' => 'integer', 'required' => true, 'hint' => '1-315']],
                'link' => null,
            ],
            [
                'id' => 'student_lookup', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'What is the seat and key status for student {student_id}?',
                'description' => "One student's seat, key status and loan-valid-until date, by 8-digit student ID placeholder (never a real name).",
                'examples' => ['check <ID1> seat status', 'where is student <ID1> sitting', 'key status for <ID1>', 'does <ID1> have a seat', '<ID1> loan valid until when'],
                'parameters' => ['student_id' => ['type' => 'string', 'required' => true, 'hint' => 'the masked ID placeholder exactly as given, e.g. "<ID1>"']],
                'link' => null,
            ],
            [
                'id' => 'keys_pending_collection', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'How many locker keys are pending collection?',
                'description' => 'How many locker keys are waiting to be collected.',
                'examples' => ['how many keys not collected', 'keys waiting pickup', 'pending collection count', 'how many uncollected keys', 'keys still at cgs'],
                'parameters' => [], 'link' => 'workstation.cgs.operations',
            ],
            [
                'id' => 'keys_pending_return', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'How many locker keys are pending return, and how many are overdue?',
                'description' => 'How many locker keys are waiting to be returned, and how many are overdue. Contrast: not return_outlook (that breaks the same thing down by due-soon/overdue bands with optional block/gender).',
                'examples' => ['how many keys not returned', 'overdue keys how many', 'keys pending return count', 'any late key returns', 'outstanding keys total'],
                'parameters' => [], 'link' => 'workstation.cgs.operations',
            ],
            [
                'id' => 'awaiting_key_return_seats', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'How many seats are Awaiting Key Return?',
                'description' => 'How many seats are Awaiting Key Return (the former occupant has left but their key is not back yet).',
                'examples' => ['how many seats waiting for key', 'awaiting key return count', 'seats stuck pending key', 'how many seat need key back', 'key not returned seats'],
                'parameters' => [], 'link' => 'workstation.cgs.operations',
            ],
            [
                'id' => 'termination_notices', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'How many seats are currently in a termination notice period?',
                'description' => 'How many seats are currently in a termination notice period (an active vacate-by countdown).',
                'examples' => ['how many students in notice period', 'termination notice count', 'how many seats got notice', 'active vacate notices', 'students being terminated how many'],
                'parameters' => [], 'link' => 'workstation.cgs.operations',
            ],
            [
                'id' => 'maintenance_seats', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'How many seats are under maintenance, and which ones?',
                'description' => 'How many seats are under maintenance, and which ones.',
                'examples' => ['which seats under maintenance', 'how many seats disabled', 'maintenance seat list', 'seats not usable right now', 'broken seats how many'],
                'parameters' => [], 'link' => 'workstation.cgs.operations',
            ],
            [
                'id' => 'room_occupancy', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'What is the occupancy percentage per room?',
                'description' => 'Occupancy percentage per room. Contrast: not almost_full_rooms (that filters to rooms AT OR ABOVE the almost-full threshold only).',
                'examples' => ['occupancy percent by room', 'which rooms are fuller', 'room by room occupancy', 'show occupancy rates', 'per room percentage full'],
                'parameters' => [], 'link' => 'workstation.cgs.operations',
            ],
            [
                'id' => 'candidacy_expiring', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'How many current seat holders\' candidacy expires within {months} months?',
                'description' => "How many current WORKSTATION SEAT HOLDERS' candidacy expires within N months (default 3 if not given). Contrast: not candidacy_expiry_distribution (that buckets everyone into 0-3/3-6/6-12/12+ at once, no single N); not cand_cgs_expiring (that counts ALL students, not only seat holders).",
                'examples' => ['how many expiring soon', 'candidacy ending in 6 months how many', 'students expiring this quarter', 'expiry count next 3 months', 'how many about to expire'],
                'parameters' => ['months' => ['type' => 'integer', 'required' => false, 'hint' => '1-12, default 3']],
                'link' => 'workstation.cgs.operations',
            ],
            [
                'id' => 'releases_this_week', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'How many seats were released in the last 7 days?',
                'description' => 'How many seats were released in the last 7 days (a look BACK, not a forecast). Contrast: not release_forecast/seat_release_outlook (those look forward).',
                'examples' => ['how many released this week', 'seats freed up recently', 'released last 7 days count', 'recent releases how many', 'this week release count'],
                'parameters' => [], 'link' => 'workstation.cgs.operations',
            ],
            [
                'id' => 'seat_release_outlook', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'Which seats are likely to become free in the next 3 months?',
                'description' => 'A FIXED "next 3 months" summary of likely releases: termination notices, Awaiting Key Return, and candidacy expiring, with no period parameter. Contrast: not release_forecast (that answers ONE specific month/quarter/year the person names, e.g. "November 2026" or "Q1 2027" -- if a specific period is named, use release_forecast instead).',
                'examples' => ['which seats likely free soon', 'upcoming releases overview', 'seats coming free generally', 'release outlook pls', 'whats freeing up soon'],
                'parameters' => [], 'link' => 'workstation.cgs.operations',
            ],
            [
                'id' => 'release_forecast', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'How many seats may be released in {period}?',
                'description' => 'How many seats may be released in ONE specific month, quarter or year (a forecast MAXIMUM, same source as the Insights page forecast chart). Contrast: not seat_release_outlook (that is a fixed, period-less "next 3 months" summary with no month/quarter/year named) -- use release_forecast whenever a specific month, quarter or year is named or implied (including "next month", "this month", a bare month name, or a bare year).',
                'examples' => ['how many seats free up in march 2027', 'seats releasing next month', 'release forecast for 2027', 'how many seat can release in q2 2027', 'forecast releases for feb', 'how many going to be vacated in august 2027'],
                'parameters' => ['period' => ['type' => 'object', 'required' => true, 'hint' => 'one of {"kind":"month","year":2026,"month":11}, {"kind":"quarter","year":2027,"quarter":1}, {"kind":"year","year":2027} -- resolve "next month"/"this month"/a bare month name/a bare year against today\'s date']],
                'link' => 'workstation.cgs.insights',
            ],
            [
                'id' => 'almost_full_rooms', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'Which rooms are almost full?',
                'description' => 'Which rooms are almost full (occupancy at or above the almost-full threshold). Contrast: not room_occupancy (that lists every room, not just the near-full ones).',
                'examples' => ['which rooms nearly full', 'almost full rooms list', 'any room close to capacity', 'rooms running out of space', 'near full rooms pls'],
                'parameters' => [], 'link' => 'workstation.cgs.operations',
            ],
            [
                'id' => 'insights_summary', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'What are the Insights page key highlights?',
                'description' => 'The Insights page key highlights, as a few short sentences -- a broad overview, not one specific number.',
                'examples' => ['give me the insights summary', 'whats on the insights page', 'overall highlights pls', 'insights overview', 'summarise insights for me'],
                'parameters' => [], 'link' => 'workstation.cgs.insights',
            ],
            [
                'id' => 'programme_breakdown', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'Which programmes use workstations most?',
                'description' => 'Top programmes by share of current seat holders. Optional block or gender to narrow it.',
                'examples' => ['which programme uses seats most', 'top programmes by seat holders', 'programme breakdown pls', 'most common programme among students here', 'which course has most seats'],
                'parameters' => ['block' => ['type' => 'string', 'required' => false, 'hint' => '"N2" or "J2"'], 'gender' => ['type' => 'string', 'required' => false, 'hint' => '"male" or "female"']],
                'link' => 'workstation.cgs.insights',
            ],
            [
                'id' => 'gender_comparison', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'How does occupancy compare between male and female rooms?',
                'description' => 'Male rooms versus female rooms: seat counts and occupancy percentage for each. Optional block.',
                'examples' => ['male vs female occupancy', 'how full are male rooms vs female', 'gender split occupancy', 'compare male female seats', 'which gender rooms fuller'],
                'parameters' => ['block' => ['type' => 'string', 'required' => false, 'hint' => '"N2" or "J2"']],
                'link' => 'workstation.cgs.insights',
            ],
            [
                'id' => 'key_status_overview', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'What is the locker key status breakdown among current seat holders?',
                'description' => 'Locker key status counts among current seat holders: not requested, pending collection, collected, return pending. Optional block or gender.',
                'examples' => ['key status breakdown pls', 'how many keys not requested', 'locker key overview', 'breakdown of key statuses', 'key status by block'],
                'parameters' => ['block' => ['type' => 'string', 'required' => false, 'hint' => '"N2" or "J2"'], 'gender' => ['type' => 'string', 'required' => false, 'hint' => '"male" or "female"']],
                'link' => 'workstation.cgs.insights',
            ],
            [
                'id' => 'candidacy_expiry_distribution', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'When do candidacies expire, across all current seat holders?',
                'description' => 'Current seat holders bucketed by how soon their candidacy expires: 0-3, 3-6, 6-12, over 12 months, all at once. Contrast: not candidacy_expiring (that is a single count for ONE N-month window, no buckets).',
                'examples' => ['candidacy expiry buckets pls', 'when do candidacies expire overall', 'expiry distribution', 'spread of candidacy end dates', 'expiry timeline for all students'],
                'parameters' => ['block' => ['type' => 'string', 'required' => false, 'hint' => '"N2" or "J2"'], 'gender' => ['type' => 'string', 'required' => false, 'hint' => '"male" or "female"']],
                'link' => 'workstation.cgs.insights',
            ],
            [
                'id' => 'length_of_stay_distribution', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'How long have current seat holders been in their seats?',
                'description' => 'Current seat holders by time since allocation: under 6, 6-12, 12-18, over 18 months. Optional block or gender.',
                'examples' => ['how long students been sitting', 'length of stay breakdown', 'how long in seat on average', 'tenure distribution pls', 'how old are the allocations'],
                'parameters' => ['block' => ['type' => 'string', 'required' => false, 'hint' => '"N2" or "J2"'], 'gender' => ['type' => 'string', 'required' => false, 'hint' => '"male" or "female"']],
                'link' => 'workstation.cgs.insights',
            ],
            [
                'id' => 'seat_status_by_block', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'What is the seat status breakdown for each block, side by side?',
                'description' => 'Occupied/Available/Maintenance/Awaiting-Key-Return counts for EVERY block compared at once. Contrast: not seat_availability (that answers ONE block, or the whole catalogue, not a side-by-side comparison).',
                'examples' => ['seats by status per block', 'break down occupied available maintenance per block', 'block by block status', 'compare block statuses', 'status split for n2 and j2 together'],
                'parameters' => [], 'link' => 'workstation.cgs.insights',
            ],
            [
                'id' => 'return_outlook', 'kind' => 'lookup', 'roles' => ['cgs'],
                'question' => 'Which locker keys are coming up for return soon?',
                'description' => 'Locker keys due back: due within 7 days, due in 8-14 days, overdue. Optional block or gender.',
                'examples' => ['when are keys due back', 'which keys returning soon', 'upcoming key returns', 'return due dates pls', 'keys due this week vs overdue'],
                'parameters' => ['block' => ['type' => 'string', 'required' => false, 'hint' => '"N2" or "J2"'], 'gender' => ['type' => 'string', 'required' => false, 'hint' => '"male" or "female"']],
                'link' => 'workstation.cgs.insights',
            ],
        ];
    }

    // =====================================================================
    // GUIDANCE -- every id here must exist in GuidanceLibrary::
    // STUDENT_TOPICS/CGS_TOPICS, and vice versa. Descriptions/contrast
    // hints are taken from GuidanceLibrary::topicDescriptions() verbatim
    // (Part 1.1's own instruction: "taken from the topic text, not
    // invented"), examples are newly invented here.
    // =====================================================================

    /** @return array<int, array<string, mixed>> */
    protected static function guidanceEntries(): array
    {
        $descriptions = GuidanceLibrary::topicDescriptions();

        $questions = [
            'select_seat' => 'How do I browse and select a workstation seat?',
            'visibility_rules' => 'Why can\'t I see some rooms or seats?',
            'confirmation' => 'What happens if I don\'t confirm my seat selection in time?',
            'change_or_return' => 'How do I change or return my workstation?',
            'locker_key_request' => 'How do I request a locker key?',
            'locker_key_collect_return' => 'How do I collect or return my locker key?',
            'key_lost' => 'What do I do if I lose my locker key?',
            'termination_vacate' => 'What does a termination or vacate notice mean, and when must I move out?',
            'eligibility_loan_period' => 'Am I eligible for a workstation, and how long does the loan last?',
            'contact_and_hours' => 'What are CGS\'s contact details and opening hours?',
            'terms_of_use' => 'What are the workstation rules I must follow?',
            'student_return_reasons' => 'Which reason should I choose when returning my workstation?',
            'student_system_guide' => 'How does the whole workstation process work, from start to finish?',
            'student_status_glossary' => 'What do the different seat and key statuses mean?',
            'student_timeline' => 'What are all the time limits in the workstation system?',

            'cgs_reassign' => 'How do I reassign a seat to a different student?',
            'cgs_release' => 'How do I release a seat?',
            'cgs_assign' => 'How do I assign a student to a seat?',
            'cgs_maintenance' => 'How do I set a seat to maintenance?',
            'cgs_release_hold' => 'How do I release a hold on a seat?',
            'cgs_keys' => 'How do I mark a locker key as collected or returned, or handle a lost key report?',
            'cgs_termination' => 'How does the termination/vacate notice process work?',
            'cgs_search_export' => 'How do I search for a student or seat, or export results?',
            'system_rules' => 'What are the overall workstation system rules?',
            'cgs_reasons' => 'Which reason should I choose for a seat action?',
            'cgs_system_guide' => 'How does the whole CGS side of the workstation system work?',
            'cgs_status_glossary' => 'What do the different seat and key statuses mean, and which action moves between them?',
            'cgs_automation' => 'What does the daily automated job do on its own?',
            'cgs_insights_definitions' => 'How is each number on the Insights page calculated?',
            'cgs_assistant_scope' => 'What can this assistant do and not do?',
        ];

        $examples = [
            'select_seat' => ['how to pick a seat', 'how do i choose my workstation', 'steps to select a seat', 'where do i browse seats', 'how to get a workstation'],
            'visibility_rules' => ['why cant i see some rooms', 'some seats missing why', 'why is j2 not showing for me', 'hidden seats reason', 'why limited room choices'],
            'confirmation' => ['what if i dont confirm in time', 'confirmation window how long', 'missed confirm what happens', 'seat selection expired meaning', 'do i lose the seat if i dont click confirm'],
            'change_or_return' => ['can i move to diff seat', 'how to change my workstation', 'how do i give back my seat', 'return workstation steps', 'can i move to another room'],
            'locker_key_request' => ['how to request key', 'how do i apply for locker key', 'how do i get a locker key', 'key request steps', 'how to ask for my key'],
            'locker_key_collect_return' => ['how do i collect my key', 'how to return the key', 'key collection process', 'where to return locker key', 'collect key steps'],
            'key_lost' => ['i misplaced my locker key', 'i lost my key what to do', 'my key missing help', 'key got lost', 'damaged my locker key now what'],
            'termination_vacate' => ['what is a vacate notice', 'termination notice meaning', 'when must i move out', 'got notice what now', 'vacate by date meaning'],
            'eligibility_loan_period' => ['am i eligible for workstation', 'how long is the loan', 'who can get a seat', 'eligibility rules pls', 'how long can i keep my seat'],
            'contact_and_hours' => ['cgs opening hours', 'how to contact cgs', 'cgs office hours', 'where is cgs located', 'cgs phone number'],
            'terms_of_use' => ['what are the rules', 'workstation terms pls', 'rules i must follow', 'term of use summary', 'what am i agreeing to'],
            'student_return_reasons' => ['which reason to pick when returning', 'return reason options', 'what reason for giving back seat', 'return workstation reason list', 'why choose a reason to return'],
            'student_system_guide' => ['how does this whole thing work', 'explain the full process', 'walk me through the system', 'guide to using this system', 'how it all works step by step'],
            'student_status_glossary' => ['what does occupied mean', 'status meanings pls', 'glossary of seat statuses', 'what is awaiting key return', 'explain the status words'],
            'student_timeline' => ['how long do i have to confirm', 'all the deadlines pls', 'time limits in this system', 'how many days to collect key', 'deadlines overview'],

            'cgs_reassign' => ['how to reassign a student', 'reassign seat steps', 'move student to new seat how', 'reassign process pls', 'how do i move someone to another seat'],
            'cgs_release' => ['what are the steps to release someone', 'release process steps', 'how to free up a seat', 'release a student from seat how', 'steps for releasing'],
            'cgs_assign' => ['how to assign a student', 'assign seat steps', 'put a student in a seat how', 'assign process pls', 'how do i give someone a seat'],
            'cgs_maintenance' => ['how to set maintenance', 'mark seat as maintenance steps', 'maintenance process pls', 'how do i disable a seat', 'put seat under maintenance how'],
            'cgs_release_hold' => ['how to release hold', 'release hold steps', 'how do i clear a hold', 'release a key hold how', 'hold release process'],
            'cgs_keys' => ['how do i mark key as collected', 'how to mark key returned', 'student says key missing what should i do', 'key marking process', 'handling a lost key report how'],
            'cgs_termination' => ['how does termination process work', 'vacate process from cgs side', 'termination steps for cgs', 'how do notices work', 'explain the termination flow'],
            'cgs_search_export' => ['how to search for a student', 'export results how', 'search by seat number how', 'how do i export the list', 'finding a student steps'],
            'system_rules' => ['overall system rules pls', 'what are the cgs side rules', 'rules for the whole system', 'general rules overview', 'system policy summary'],
            'cgs_reasons' => ['which reason for release', 'reason options for reassign', 'what reason to pick for maintenance', 'reason list for actions', 'which reason should i choose'],
            'cgs_system_guide' => ['explain the whole cgs flow', 'guide to the cgs side', 'how does cgs side work overall', 'walk me through cgs features', 'full cgs process explained'],
            'cgs_status_glossary' => ['status meanings from cgs side', 'what does each status mean', 'glossary for cgs', 'explain all the statuses', 'status to action mapping'],
            'cgs_automation' => ['what does the daily job do', 'automatic triggers explained', 'what runs automatically', 'automation rules pls', 'which things happen on their own'],
            'cgs_insights_definitions' => ['how is the forecast calculated', 'insights numbers explained', 'how do you compute occupancy percent', 'what does the forecast chart mean', 'insights page definitions'],
            'cgs_assistant_scope' => ['what can you do', 'what cant this bot do', 'assistant capabilities pls', 'can you make changes for me', 'what is this chatbot for'],
        ];

        $entries = [];
        foreach (GuidanceLibrary::STUDENT_TOPICS as $topic) {
            $entries[] = [
                'id' => $topic, 'kind' => 'guidance', 'roles' => [Role::STUDENT, 'cgs'],
                'question' => $questions[$topic], 'description' => $descriptions[$topic],
                'examples' => $examples[$topic], 'parameters' => [], 'link' => null,
            ];
        }
        foreach (GuidanceLibrary::CGS_TOPICS as $topic) {
            $entries[] = [
                'id' => $topic, 'kind' => 'guidance', 'roles' => ['cgs'],
                'question' => $questions[$topic], 'description' => $descriptions[$topic],
                'examples' => $examples[$topic], 'parameters' => [], 'link' => null,
            ];
        }

        return $entries;
    }

    // =====================================================================
    // ACTIONS -- the assistant never executes any of these (Pipeline
    // always returns the helpful refusal with links, see Part 2.4); one
    // entry per kind of operation named in Part 1.1.
    // =====================================================================

    public const ACTION_KINDS = [
        'action_release', 'action_reassign', 'action_assign', 'action_mark_key_collected',
        'action_mark_key_returned', 'action_set_maintenance', 'action_restore',
        'action_terminate_or_withdraw', 'action_release_hold', 'action_cancel_request',
        'action_other',
        'cand_action_decide', 'cand_action_submit', 'cand_action_reminders', 'cand_action_export_upload', 'cand_action_dismiss',
    ];

    /** @return array<int, array<string, mixed>> */
    protected static function actionEntries(): array
    {
        return [
            [
                'id' => 'action_release', 'kind' => 'action', 'roles' => ['cgs'],
                'question' => 'Release Seat {seat_number}.',
                'description' => 'An IMPERATIVE to release a specific seat now -- not a question about how releasing works (that is cgs_release). Only when a seat number or student ID is actually named as the target.',
                'examples' => ['release seat 87', 'free up seat 87 now', 'release <ID1> seat', 'kick out seat 140', 'can u release seat 19 for me'],
                'parameters' => ['seat_number' => ['type' => 'integer', 'required' => false, 'hint' => '1-315, if named'], 'student_id' => ['type' => 'string', 'required' => false, 'hint' => 'the ID placeholder copied exactly, e.g. "<ID1>" -- nothing added before or after it; a seat number never goes here']],
                'link' => 'workstation.cgs.operations',
                'pattern' => '/\brelease\b|\bfree\s+up\b|\bkick\s+out\b/',
            ],
            [
                'id' => 'action_reassign', 'kind' => 'action', 'roles' => ['cgs'],
                'question' => 'Reassign Seat {seat_number} to another student.',
                'description' => 'An imperative to reassign a specific seat -- not how-to guidance (that is cgs_reassign).',
                'examples' => ['reassign seat 11 to someone else', 'move <ID1> to a new seat now', 'reassign seat 303', 'swap out whoever is in seat 55', 'please reassign this seat'],
                'parameters' => ['seat_number' => ['type' => 'integer', 'required' => false, 'hint' => '1-315, if named'], 'student_id' => ['type' => 'string', 'required' => false, 'hint' => 'the ID placeholder copied exactly, e.g. "<ID1>" -- nothing added before or after it; a seat number never goes here']],
                'link' => 'workstation.cgs.operations',
                'pattern' => '/\breassign\b|\bswap\s+out\b|\bmove\s+\S+\s+to\s+(a\s+|another\s+|new\s+)?(new\s+)?seat\b/',
            ],
            [
                'id' => 'action_assign', 'kind' => 'action', 'roles' => ['cgs'],
                'question' => 'Assign student {student_id} to Seat {seat_number}.',
                'description' => 'An imperative to assign a specific student to a specific seat now -- not how-to guidance (that is cgs_assign).',
                'examples' => ['assign <ID1> to seat 42', 'put <ID1> in seat 303', 'give seat 11 to <ID1>', 'assign seat 87 now', 'add student <ID1> to a seat'],
                'parameters' => ['seat_number' => ['type' => 'integer', 'required' => false, 'hint' => '1-315, if named'], 'student_id' => ['type' => 'string', 'required' => false, 'hint' => 'the ID placeholder copied exactly, e.g. "<ID1>" -- nothing added before or after it; a seat number never goes here']],
                'link' => 'workstation.cgs.operations',
                'pattern' => '/\bassign\b|\bput\s+\S+\s+in(to)?\s+seat\b|\bgive\s+seat\b|\badd\s+(student\s+)?\S+\s+to\s+(a\s+)?seat\b/',
            ],
            [
                'id' => 'action_mark_key_collected', 'kind' => 'action', 'roles' => ['cgs'],
                'question' => 'Mark the locker key for {student_id} as Collected.',
                'description' => 'An imperative to mark a specific key as collected -- not how-to guidance (that is cgs_keys). "can u help me to mark <ID1> as key collected" is this, not a question.',
                'examples' => ['help me tick <ID1> key as collected', 'mark <ID1> key as collected', 'set key for seat 55 to collected', 'collected key for <ID1> pls update', 'key collected for seat 11 mark it'],
                'parameters' => ['seat_number' => ['type' => 'integer', 'required' => false, 'hint' => '1-315, if named'], 'student_id' => ['type' => 'string', 'required' => false, 'hint' => 'the ID placeholder copied exactly, e.g. "<ID1>" -- nothing added before or after it; a seat number never goes here']],
                'link' => 'workstation.cgs.operations',
                'pattern' => '/\b(mark|set|tick|update)\b.*\bcollected\b|\bkey\s+collected\b.*\bmark\b|\bmark\s+as\s+collected\b/',
            ],
            [
                'id' => 'action_mark_key_returned', 'kind' => 'action', 'roles' => ['cgs'],
                'question' => 'Mark the locker key for {student_id} as Returned.',
                'description' => 'An imperative to mark a specific key as returned -- not how-to guidance (that is cgs_keys).',
                'examples' => ['mark key returned for <ID1>', 'key for seat 140 is back mark it', 'set <ID1> key to returned', 'returned key please update seat 87', 'key is returned for <ID1>'],
                'parameters' => ['seat_number' => ['type' => 'integer', 'required' => false, 'hint' => '1-315, if named'], 'student_id' => ['type' => 'string', 'required' => false, 'hint' => 'the ID placeholder copied exactly, e.g. "<ID1>" -- nothing added before or after it; a seat number never goes here']],
                'link' => 'workstation.cgs.operations',
                'pattern' => '/\b(mark|set|tick|update)\b.*\breturned\b|\bkey\b.*\bback\b.*\bmark\b|\bmark\s+as\s+returned\b|\bmark\s+the\s+key\b/',
            ],
            [
                'id' => 'action_set_maintenance', 'kind' => 'action', 'roles' => ['cgs'],
                'question' => 'Set Seat {seat_number} to Maintenance.',
                'description' => 'An imperative to put a specific seat into maintenance -- not how-to guidance (that is cgs_maintenance).',
                'examples' => ['set seat 252 to maintenance', 'disable seat 11 now', 'put seat 87 under maintenance', 'mark seat 303 broken', 'seat 140 needs maintenance set it'],
                'parameters' => ['seat_number' => ['type' => 'integer', 'required' => false, 'hint' => '1-315, if named']],
                'link' => 'workstation.cgs.operations',
                'pattern' => '/\b(set|put|mark|move)\b.*\bmaintenance\b|\bdisable\s+seat\b|\bset\s+maintenance\b|\bmark\s+seat\s+\S+\s+broken\b/',
            ],
            [
                'id' => 'action_restore', 'kind' => 'action', 'roles' => ['cgs'],
                'question' => 'Restore Seat {seat_number} to Available.',
                'description' => 'An imperative to restore a specific seat out of maintenance back to Available.',
                'examples' => ['restore seat 252', 'bring seat 11 back online', 'un-disable seat 87', 'restore this seat now', 'seat 303 fixed restore it'],
                'parameters' => ['seat_number' => ['type' => 'integer', 'required' => false, 'hint' => '1-315, if named']],
                'link' => 'workstation.cgs.operations',
                'pattern' => '/\brestore\b|\bback\s+online\b|\bun-?disable\b/',
            ],
            [
                'id' => 'action_terminate_or_withdraw', 'kind' => 'action', 'roles' => ['cgs'],
                'question' => 'Start or withdraw a termination notice for Seat {seat_number}.',
                'description' => 'An imperative to start a termination notice for a seat, or withdraw an existing one -- not how-to guidance (that is cgs_termination).',
                'examples' => ['terminate seat 252', 'withdraw the notice on seat 11', 'start termination for <ID1>', 'cancel the vacate notice seat 87', 'issue a termination notice now'],
                'parameters' => ['seat_number' => ['type' => 'integer', 'required' => false, 'hint' => '1-315, if named'], 'student_id' => ['type' => 'string', 'required' => false, 'hint' => 'the ID placeholder copied exactly, e.g. "<ID1>" -- nothing added before or after it; a seat number never goes here']],
                'link' => 'workstation.cgs.operations',
                'pattern' => '/\bterminat(e|ion)\b|\bwithdraw\b|\bissue\s+a\s+(termination|vacate)\b|\bcancel\s+the\s+vacate\b/',
            ],
            [
                'id' => 'action_release_hold', 'kind' => 'action', 'roles' => ['cgs'],
                'question' => 'Release the hold on Seat {seat_number}.',
                'description' => 'An imperative to release a hold on a specific seat -- not how-to guidance (that is cgs_release_hold).',
                'examples' => ['release hold on seat 252', 'clear the hold seat 11', 'release hold now', 'seat 87 hold remove it', 'take seat 303 off hold'],
                'parameters' => ['seat_number' => ['type' => 'integer', 'required' => false, 'hint' => '1-315, if named']],
                'link' => 'workstation.cgs.operations',
                'pattern' => '/\b(release|remove|clear|lift)\s+(the\s+)?hold\b|\boff\s+hold\b/',
            ],
            [
                'id' => 'action_cancel_request', 'kind' => 'action', 'roles' => [Role::STUDENT],
                'question' => 'Cancel my locker key request.',
                'description' => "An imperative from a STUDENT to cancel their own pending locker key request -- the assistant never does this either; it links to the student's own Workstation page where the real Cancel button is.",
                'examples' => ['cancel my key request', 'undo my key request', 'i want to cancel the key request', 'stop my locker key request', 'remove my pending key request'],
                'parameters' => [], 'link' => 'workstation.select',
                'pattern' => '/\b(cancel|undo|stop|remove)\b.*\brequest\b/',
            ],
            [
                'id' => 'action_other', 'kind' => 'action', 'roles' => [Role::STUDENT, 'cgs'],
                'question' => 'Approve, delete or change something in the system.',
                'description' => 'Any other imperative to approve, delete, change, update or undo something not covered by a more specific action entry above (a bare "approve the pending thing" with no appeal named is this one; approving an APPEAL is cand_action_decide).',
                'examples' => ['approve this request', 'delete seat 55', 'change the room for seat 11', 'update my details', 'undo the last action'],
                'parameters' => ['seat_number' => ['type' => 'integer', 'required' => false, 'hint' => '1-315, if named'], 'student_id' => ['type' => 'string', 'required' => false, 'hint' => 'the ID placeholder copied exactly, e.g. "<ID1>" -- nothing added before or after it; a seat number never goes here']],
                'link' => null,
                'pattern' => '/\b(approve|delete|remove|update|change|undo|edit)\b/',
            ],
        ];
    }
}
