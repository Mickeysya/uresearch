<?php

namespace App\Modules\Chloe\Assistant;

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\File;

/**
 * The Study Candidacy part of the ONE assistant: its catalogue entries (domain "candidacy"), guidance topics (docs/knowledge/candidacy/<topic>.md, each ending with a
 * "Source:" line), lookup definitions, refusals and suggested questions. The Workstation classes only call into this one; nothing here is a second assistant.
 *
 * Roles: 'student' | 'approver' | 'cgs' (see AssistantRoles). The assistant explains and shows data. It never approves, returns, rejects, submits, sends reminders,
 * exports, uploads or dismisses, and never predicts a decision: those requests are refused with a short reason and a link to the right page.
 */
final class CandidacyAssistant
{
    public const DOMAIN = 'candidacy';

    /** The knowledge files, in the order a role looks in them: its own file first, then the shared ones (system flow, Handbook). */
    protected const FILES = ['student' => '10-student.md', 'approver' => '20-approver.md', 'cgs' => '30-cgs.md'];

    protected const SHARED_FILES = ['00-system-flow.md', '01-handbook.md'];

    /** @var array<string, array<string, string>>|null topic id => ['student'|'approver'|'cgs'|'shared' => section text] */
    protected static ?array $knowledge = null;

    /**
     * Every `## cand_<intent>` section of the five knowledge files. A topic is answered for a role only when that role's file has the section, or a shared file has it
     * (shared = every role); so the role list of a topic is never typed in code and a role cannot be handed another role's text.
     *
     * @return array<string, array<string, string>>
     */
    public static function knowledge(): array
    {
        if (self::$knowledge !== null) {
            return self::$knowledge;
        }

        $out = [];
        $read = function (string $file, string $key) use (&$out): void {
            $path = base_path('docs/knowledge/candidacy/'.$file);

            if (! File::exists($path)) {
                return;
            }

            $parts = preg_split('/^## (cand_[a-z0-9_]+)\s*$/m', File::get($path), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

            for ($i = 1; $i < count($parts); $i += 2) {
                $out[$parts[$i]][$key] = trim($parts[$i + 1] ?? '');
            }
        };

        foreach (self::FILES as $key => $file) {
            $read($file, $key);
        }

        foreach (self::SHARED_FILES as $file) {
            $read($file, 'shared');
        }

        return self::$knowledge = $out;
    }

    public static function flushKnowledge(): void
    {
        self::$knowledge = null;
    }

    /** @return array<string, list<string>> topic id => the plain roles that have an answer */
    public static function topicRoles(): array
    {
        $out = [];

        foreach (self::knowledge() as $topic => $sections) {
            $out[$topic] = isset($sections['shared']) ? ['student', 'approver', 'cgs'] : array_values(array_intersect(array_keys(self::FILES), array_keys($sections)));
        }

        return $out;
    }

    public static function isTopic(string $id): bool
    {
        return isset(self::knowledge()[$id]);
    }

    /** @return list<string> */
    public static function topicsFor(string $plainRole): array
    {
        return array_keys(array_filter(self::topicRoles(), fn ($roles) => in_array($plainRole, $roles, true)));
    }

    /** @return list<string> */
    public static function lookupNames(string $plainRole): array
    {
        $prefix = match ($plainRole) {
            'student' => ['cand_my_', 'cand_can_appeal', 'cand_next_reminder'],
            'approver' => ['cand_ap_'],
            default => ['cand_cgs_'],
        };

        return array_values(array_filter(array_keys(CandidacyLookups::methods()), fn ($n) => collect($prefix)->contains(fn ($p) => str_starts_with($n, $p))));
    }

    /** The text of a topic for the role: that role's section (CGS before approver before student for a CGS user who is also an approver), else the shared one. Null when the role has none. */
    public static function sourceText(string $topic, ?string $role = null): ?string
    {
        $sections = self::knowledge()[$topic] ?? null;

        if ($sections === null) {
            return null;
        }

        $order = $role === null ? ['cgs', 'approver', 'student'] : array_values(array_intersect(['cgs', 'approver', 'student'], AssistantRoles::set($role)));
        $text = null;

        foreach ([...$order, 'shared'] as $key) {
            if (isset($sections[$key])) {
                $text = $sections[$key];
                break;
            }
        }

        return $text === null ? null : GuidanceLibrary::applyPlaceholders($text);
    }

    /** @return array<string, array{description:string, parameters: array<string,string>}> */
    public static function definitions(): array
    {
        $out = [];

        foreach (self::lookupSpecs() as $id => $spec) {
            $out[$id] = ['description' => $spec['description'], 'parameters' => array_map(fn ($p) => $p['hint'], $spec['parameters'] ?? [])];
        }

        return $out;
    }

    public static function run(string $name, array $parameters, User $user): array
    {
        return CandidacyLookups::run($name, $parameters, $user);
    }

    /** @return array<int, array{label:string,url:string}> */
    public static function linksForTopic(string $topic, string $role): array
    {
        $plain = AssistantRoles::set($role);
        $student = $plain === ['student'];
        $cgs = in_array('cgs', $plain, true);
        $portal = [['Approval Portal', route('candidacy.portal.queue')]];

        $links = match ($topic) {
            'cand_see_all_appeals' => $student ? [['My Candidacy', route('candidacy.status')]] : ($cgs ? [['Candidacy Operations', route('candidacy.cgs.operations')], ['Approval Portal', route('candidacy.portal.queue')]] : $portal),
            'cand_when_how_appeal', 'cand_required_documents' => $student ? [['New appeal', route('candidacy.appeals.new')]] : [],
            'cand_resubmit', 'cand_after_return_rejection', 'cand_after_approval' => $student ? [['My Candidacy', route('candidacy.status')]] : [],
            'cand_how_decide', 'cand_approver_rules', 'cand_return_effect', 'cand_portal_guide', 'cand_waiting_colours' => $portal,
            'cand_my_signature' => [['My Signature', route('candidacy.signature.show')]],
            'cand_cgs_dismissal_list_guide' => [['Dismissal List', route('candidacy.cgs.dismissals')]],
            'cand_cgs_export_registry' => [['Dismissal List', route('candidacy.cgs.dismissals')]],
            'cand_cgs_registry_upload' => [['Dismissal Upload', route('candidacy.cgs.dismissal-upload')]],
            'cand_cgs_insights_guide' => [['Candidacy Insights', route('candidacy.cgs.insights')]],
            'cand_cgs_operations_guide' => [['Candidacy Operations', route('candidacy.cgs.operations')]],
            default => [],
        };

        return array_map(fn ($l) => ['label' => $l[0], 'url' => $l[1]], $links);
    }

    // ------------------------------------------------------------------ keyword rules (the fallback when the model is busy)

    /**
     * id => regexes over the NORMALISED question. A rule can only ever answer a role that the catalogue gives that id to (see keywordMatch), and every pattern names what it
     * is about, so a vague "how ... appeal" can no longer reach a how-to written for another role. Order matters: the more specific first.
     */
    protected const KEYWORDS = [
        'cand_see_all_appeals' => ['/\b(see|view|find|show|list|open|check|where|access)\b.*\b(all|every|full|complete|whole)\b.*\bappeals?\b/', '/\b(full|complete|whole)\b.*\bappeals?\s+(list|table)\b/', '/\bappeals?\s+list\b/', '/\ball\s+(the\s+|my\s+)?appeals?\b/'],
        'cand_my_expiry' => ['/\b(my\s+)?candidacy\b.*\b(expire|expiry|end|deadline|days\s+left)\b/', '/\b(expire|expiry)\s+date\b.*\bcandidacy\b/'],
        'cand_my_extension' => ['/\bextension\b.*\b(left|remain|used|much|many)\b/', '/\b(left|remain|used)\b.*\bextension\b/'],
        'cand_can_appeal' => ['/\b(can|may|allowed|eligible)\b.*\b(i|we)\b.*\bappeal\b/', '/\bappeal\b.*\b(still|now|eligible|possible)\b/'],
        'cand_my_appeal' => ['/\b(status|where|who has|progress)\b.*\b(my\s+)?appeal\b/', '/\bmy\s+appeal\b.*\b(now|status|step|stage)\b/'],
        'cand_my_return_reason' => ['/\bwhy\b.*\bappeal\b.*\breturned\b/', '/\bappeal\b.*\b(sent|send)\s+back\b/'],
        'cand_next_reminder' => ['/\b(next|another|when)\b.*\b(candidacy|expiry)?\s*reminder\b/'],
        'cand_my_softbound' => ['/\bsoft\s*bound\b.*\b(recorded|received|status|submitted)\b/'],
        'cand_ap_pending' => ['/\b(how\s+many|number)\b.*\bappeals?\b.*\b(waiting|pending)\b.*\b(me|my)\b/', '/\bappeals?\b.*\bpending\s+my\s+action\b/'],
        'cand_ap_oldest' => ['/\b(oldest|longest)\b.*\bappeal\b/'],
        'cand_ap_waiting_days' => ['/\b(5|10|five|ten)\s+days\b.*\bappeal/', '/\bappeals?\b.*\b(5|10)\s+days/'],
        'cand_ap_decided_month' => ['/\b(approved|returned)\b.*\bthis\s+month\b.*\bappeal|\bappeal.*\b(approved|returned)\b.*\bthis\s+month\b/'],
        'cand_ap_ongoing' => ['/\bongoing\b.*\bappeals?\b/'],
        'cand_cgs_pending_by_step' => ['/\bappeals?\b.*\b(each|every|per)\s+(step|stage)\b/', '/\b(step|stage)\b.*\bappeals?\b.*\b(waiting|pending)\b/'],
        'cand_cgs_outcome_counts' => ['/\bappeals?\b.*\b(approved|rejected|closed\s+expired)\b.*\b(how\s+many|count|number|total)\b/', '/\b(how\s+many|count|number)\b.*\bappeals?\b.*\b(approved|rejected|closed)\b/'],
        'cand_cgs_oldest' => ['/\b(oldest|longest)\b.*\bappeals?\b/'],
        'cand_cgs_waiting_10' => ['/\bappeals?\b.*\b(10|ten)\s+days\b/'],
        'cand_cgs_avg_waiting' => ['/\baverage\b.*\b(waiting|wait)\b.*\b(appeal|step)/'],
        'cand_cgs_expiring' => ['/\b(students?|candidac(y|ies))\b.*\bexpir\w*\b.*\b(month|months)\b/', '/\bexpir\w*\b.*\bwithin\b.*\bmonths?\b/'],
        'cand_cgs_no_action' => ['/\b(no\s+action|nothing|not\s+acted|high\s+risk)\b.*\b(expir|student)/'],
        'cand_cgs_twelve_of_twelve' => ['/\b(12|twelve)\s+(of\s+12\s+)?months?\b.*\b(used|extension|students?)/', '/\bused\b.*\b(all|full)\b.*\bextension\b/'],
        'cand_cgs_by_department' => ['/\bappeals?\b.*\bby\s+department\b/', '/\bper\s+department\b.*\bappeals?\b/'],
        'cand_cgs_reminders_sent' => ['/\breminders?\b.*\b(sent|went\s+out)\b/', '/\bhow\s+many\b.*\breminders?\b/'],
        'cand_cgs_reminder_response' => ['/\breminders?\b.*\b(response|respond|work|effective)/'],
        'cand_cgs_dismissal_candidates' => ['/\bdismissal\s+(candidates?|list)\b.*\b(how\s+many|count|size|number)\b/', '/\b(how\s+many)\b.*\bdismissal\b/'],
        'cand_cgs_dismissed' => ['/\b(dismissed|dismissals?)\b.*\b(this|last)\s+(month|30)/'],
        'cand_cgs_in_progress' => ['/\bappeals?\b.*\bin\s+progress\b/'],
        'cand_how_decide' => ['/\bhow\b.*\b(approve|return|reject|verify|decide)\b.*\bappeals?\b/', '/\bhow\b.*\b(do|can)\b.*\b(i|we)\b.*\b(approve|return|reject|verify)\b/'],
        'cand_required_documents' => ['/\b(documents?|attach|files?|form)\b.*\bappeal\b/', '/\bappeal\b.*\b(documents?|attach|form)\b/'],
        'cand_when_how_appeal' => ['/\bhow\b.*\b(do|can|to)\b.*\b(appeal|apply\s+for\s+(an\s+)?extension|extend)\b/', '/\b(steps?|process)\b.*\b(appeal|extension)\b/', '/\bhow\b.*\b(submit|make|start|file)\b.*\b(an?\s+)?appeal\b/'],
        'cand_if_expires' => ['/\b(candidacy|candidature)\b.*\b(expires?|expired|ends?|miss)\b.*\b(what|happen)/', '/\bwhat\b.*\bhappens?\b.*\bcandidacy\b.*\bexpir/'],
        'cand_reminders' => ['/\b(candidacy|expiry)\s+reminders?\b/'],
        'cand_outcome_prediction' => ['/\b(will|going\s+to|chance|likely)\b.*\bappeal\b.*\b(approved|accepted|rejected|pass)/', '/\bappeal\b.*\b(approved|rejected)\b.*\b(will|chance|likely)/'],
        'cand_my_signature' => ['/\bsignature\b/'],
        'cand_return_reasons' => ['/\b(return|returning)\s+reasons?\b/', '/\breasons?\b.*\b(return|returned)\b/'],
        'cand_return_effect' => ['/\b(return|returned)\b.*\bappeal\b.*\b(what|happen|after)/'],
        'cand_dean_rejection' => ['/\b(dean|reject\w*)\b.*\b(reject\w*|final)\b/'],
        'cand_withdraw' => ['/\bwithdraw/', '/\bcancel\b.*\bappeal\b/'],
        'cand_resubmit' => ['/\bresubmit/'],
        'cand_twelve_month_limit' => ['/\b(12|twelve)\s*-?\s*months?\b.*\b(limit|maximum|rule)/', '/\bmaximum\b.*\bextension\b/'],
        'cand_deadline' => ['/\bappeal\b.*\b(deadline|last\s+day|on\s+or\s+before)\b/'],
        'cand_waiting_colours' => ['/\b(colou?rs?|dots?)\b.*\b(waiting|days)\b/', '/\bwaiting\b.*\b(colou?rs?|dots?)\b/'],
        'cand_notifications' => ['/\b(notif\w*|e\s*mail|bell)\b.*\b(appeal|approve|return)\w*/'],
        'cand_cgs_registry_upload' => ['/\bupload\b.*\bregistry\b/', '/\bdismissal\s+upload\b/'],
        'cand_cgs_export_registry' => ['/\bexport\b.*\b(dismissal|list)\b/'],
        'cand_cgs_dismissal_list_guide' => ['/\b(who|why|rule)\b.*\bdismissal\s+list\b/'],
        'cand_cgs_insights_guide' => ['/\binsights\b.*\b(read|mean|explain|calculated)\b/'],
        'cand_cgs_operations_guide' => ['/\boperations\b.*\b(read|use|filter|explain|mean)\w*/'],
        'cand_approver_rules' => ['/\b(what|which)\b.*\b(can|do)\b.*\b(my\s+step|as\s+an?\s+(approver|supervisor|chair|dean))\b/', '/\bwhat\s+can\s+i\s+do\b.*\bappeal/'],
        'cand_approval_route' => ['/\b(who|which)\b.*\b(approves?|decides?|signs?)\b.*\bappeal/', '/\bappeal\b.*\b(route|order\s+of\s+approvers|approval\s+steps)\b/'],
        'cand_status_meanings' => ['/\bappeal\b.*\bstatus(es)?\b.*\bmean\b/', '/\bwhat\b.*\b(returned|closed\s+expired|with\s+cgs)\b.*\bmean\b/'],
        'cand_after_approval' => ['/\bafter\b.*\b(appeal\s+)?(is\s+)?approved\b/', '/\bapproval\s+memo\b/', '/\bmemo\b/'],
        'cand_appeal_rules' => ['/\bappeal\s+rules\b/', '/\brules?\b.*\b(for|of)\b.*\bappeals?\b/'],
        'cand_what_is_candidacy' => ['/\b(how\s+long|maximum|duration|period)\b.*\bcandidacy\b/', '/\bwhat\s+is\b.*\bcandidacy\b/'],
        'cand_after_return_rejection' => ['/\bappeal\b.*\b(rejected|returned)\b.*\b(now|next|what)\b/', '/\b(rejected|returned)\b.*\bappeal\b.*\b(now|next)\b/'],
    ];

    /** Words that carry no meaning for the match score. */
    protected const STOP = ['the', 'and', 'for', 'are', 'was', 'what', 'when', 'how', 'can', 'does', 'did', 'you', 'your', 'our', 'with', 'from', 'that', 'this', 'there', 'have', 'has', 'any', 'all', 'please', 'tell', 'about', 'into', 'out', 'not', 'who', 'where', 'which', 'would', 'could', 'should', 'get', 'got', 'need', 'want', 'know', 'give', 'show'];

    /**
     * The catalogue id a question plainly names, for THIS role only, or null. The role's own catalogue decides what may be answered (an id the role is not given is never
     * returned), a regex rule needs a candidacy word in the question, and the word-overlap check below needs a minimum score and a clear winner. The model is the primary
     * router; this runs only when the model is busy.
     */
    public static function keywordMatch(string $normalised, string $role): ?string
    {
        if (! preg_match('/\b(candidacy|candidature|appeals?|extension|softbound|soft\s+bound|dismissal|dismissed|reminders?|approver|signature|memo|operations|insights)\b/', $normalised)) {
            return null;
        }

        foreach (self::KEYWORDS as $id => $patterns) {
            $entry = Catalogue::find($id);

            if ($entry === null || ! AssistantRoles::covers($role, $entry['roles'])) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (@preg_match($pattern, $normalised) === 1) {
                    return $id;
                }
            }
        }

        return self::scoredMatch($normalised, $role);
    }

    /** @return list<string> */
    protected static function words(string $text): array
    {
        $out = [];

        foreach (preg_split('/[^a-z0-9]+/', mb_strtolower($text)) ?: [] as $t) {
            if (mb_strlen($t) >= 3 && ! in_array($t, self::STOP, true)) {
                $out[] = preg_replace('/(ing|ed|es|s)$/', '', $t) ?: $t;
            }
        }

        return array_values(array_unique($out));
    }

    /** The one entry of the role that shares most of the question's meaningful words: at least 3 shared, at least 60% of the question's words, and one more than the runner-up. */
    protected static function scoredMatch(string $normalised, string $role): ?string
    {
        $words = self::words($normalised);

        if (count($words) < 3) {
            return null;
        }

        $scores = [];

        foreach (Catalogue::forRole($role, self::DOMAIN) as $entry) {
            if ($entry['kind'] === 'action' || $entry['kind'] === 'out_of_scope') {
                continue;
            }

            $entryWords = array_flip(self::words($entry['question'].' '.implode(' ', $entry['examples'])));
            $scores[$entry['id']] = count(array_filter($words, fn ($w) => isset($entryWords[$w])));
        }

        arsort($scores);
        $ids = array_keys($scores);
        $best = $scores[$ids[0] ?? ''] ?? 0;
        $second = $scores[$ids[1] ?? ''] ?? 0;

        return $best >= 3 && $best / count($words) >= 0.6 && $best >= $second + 1 ? $ids[0] : null;
    }

    // ------------------------------------------------------------------ scope and role guards

    /** Handbook topics outside Study Candidacy (config candidacy domain `out_of_scope_terms`). */
    public static function isOutsideScope(string $normalised): bool
    {
        $found = false;

        foreach ((array) config('assistant.domains.candidacy.out_of_scope_terms') as $term) {
            if (preg_match('/(?<![a-z0-9])'.preg_quote($term, '/').'(?![a-z0-9])/i', $normalised) === 1) {
                $found = true;
                break;
            }
        }

        // a core candidacy word keeps the question in scope ("appeal" is not enough to override "thesis correction" only when the word is just "extension")
        return $found && ! preg_match('/\b(candidacy|candidature|appeals?|softbound|soft\s*-?\s*bound|dismissal|dismissed|dismiss|reminders?|memo|approvers?)\b/', $normalised);
    }

    /** The pages and words that belong to one role only, and whom they belong to. */
    protected const ROLE_ONLY = [
        'cgs' => ['/\b(operations|insights|dismissal\s+list|dismissal\s+upload|registry\s+(file|result)|export)\b/'],
        'approver' => ['/\b(approval\s+portal|pending\s+my\s+action|verify)\b/'],
        'student' => ['/\b(submit\s+an\s+appeal|new\s+appeal)\b/', '/\bhow\b.*\b(appeal|apply|ask)\b.*\b(for\s+)?(an?\s+)?(extension|more\s+time)\b/'],
    ];

    /** The plain role a question is plainly about when it is not the asking role's, or null. */
    public static function wrongRoleFor(string $normalised, string $role): ?string
    {
        $mine = AssistantRoles::set($role);

        foreach (self::ROLE_ONLY as $owner => $patterns) {
            if (in_array($owner, $mine, true) || ($owner === 'approver' && in_array('cgs', $mine, true))) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $normalised) === 1) {
                    return $owner;
                }
            }
        }

        return null;
    }

    /** What the assistant says when nothing fits: honest, with the asking role's own suggestions shown under it by the pipeline. */
    public static function noMatchMessage(string $normalised, string $role): string
    {
        $owner = self::wrongRoleFor($normalised, $role);
        $label = ['student' => 'students', 'approver' => 'approvers', 'cgs' => 'CGS'];

        if ($owner !== null) {
            $mine = AssistantRoles::set($role);

            return 'That is for '.$label[$owner].', so I can\'t answer it for your account. As '.($mine === ['student'] ? 'a student' : ($mine === ['approver'] ? 'an approver' : 'CGS')).', you can ask me about the questions below.';
        }

        return 'I could not match that to something I can answer. Try one of these questions, or ask it in other words.';
    }

    // ------------------------------------------------------------------ refusals

    /** @return array{text:string, links: array<int, array{label:string,url:string}>} */
    public static function refusal(string $actionId, string $role): array
    {
        $plain = AssistantRoles::set($role);
        $cgs = in_array('cgs', $plain, true);
        $approver = in_array('approver', $plain, true);
        $link = fn (string $label, string $route) => [['label' => $label, 'url' => route($route)]];

        return match ($actionId) {
            'cand_action_decide' => [
                'text' => "I can't approve, return or reject an appeal. Only the approvers and the Dean decide, and only in the Approval Portal. I can explain the rules and show the current status.",
                'links' => $cgs || $approver ? $link('Approval Portal', 'candidacy.portal.queue') : $link('My candidacy', 'candidacy.status'),
            ],
            'cand_action_submit' => [
                'text' => "I can't submit an appeal for you, and an appeal cannot be withdrawn once it is submitted. You can write and submit it yourself from New appeal.",
                'links' => $link('New appeal', 'candidacy.appeals.new'),
            ],
            'cand_action_reminders' => [
                'text' => "I can't send reminders. They go out automatically by e-mail and bell at 08:00, 3, 2 and 1 month before each expiry date. You can see who has been reminded on the Insights page.",
                'links' => $link('Candidacy Insights', 'candidacy.cgs.insights'),
            ],
            'cand_action_export_upload' => [
                'text' => "I can't export or upload files. The Dismissal List has the Export button, and the Registry's result is uploaded on Dismissal Upload (preview first, then confirm).",
                'links' => [['label' => 'Dismissal List', 'url' => route('candidacy.cgs.dismissals')], ['label' => 'Dismissal Upload', 'url' => route('candidacy.cgs.dismissal-upload')]],
            ],
            'cand_action_dismiss' => [
                'text' => "I can't dismiss anyone. A student is marked as dismissed only when the Registry's confirmed list is uploaded and confirmed on Dismissal Upload. I can show who is on the Dismissal List.",
                'links' => [['label' => 'Dismissal List', 'url' => route('candidacy.cgs.dismissals')], ['label' => 'Dismissal Upload', 'url' => route('candidacy.cgs.dismissal-upload')]],
            ],
            default => ['text' => "I can't make changes. I can explain and show data.", 'links' => []],
        };
    }

    // ------------------------------------------------------------------ suggested questions (answered without a model call)

    /** @return array<string, string> catalogue id => the exact question the chip shows */
    public static function suggestions(string $role): array
    {
        $out = [];

        foreach (AssistantRoles::set($role) as $plain) {
            $out += match ($plain) {
                'student' => [
                    'cand_my_expiry' => 'When does my candidacy expire?', 'cand_my_extension' => 'How many months of extension do I have left?', 'cand_can_appeal' => 'Can I still submit an appeal?',
                    'cand_my_appeal' => 'Where is my appeal now?', 'cand_required_documents' => 'What documents do I need for an appeal?', 'cand_if_expires' => 'What happens if my candidacy expires?',
                    'cand_when_how_appeal' => 'When and how do I appeal for an extension?', 'cand_reminders' => 'When do I get candidacy reminders?', 'cand_see_all_appeals' => 'How can I see the full appeal list?',
                ],
                'approver' => [
                    'cand_ap_pending' => 'How many appeals are waiting for me?', 'cand_ap_oldest' => 'Which appeal has waited the longest?', 'cand_approver_rules' => 'What can I do at my step?',
                    'cand_my_signature' => 'How do I upload my signature?', 'cand_return_effect' => 'What happens when I return an appeal?', 'cand_appeal_rules' => 'What are the appeal rules?',
                    'cand_how_decide' => 'How do I approve or return an appeal?', 'cand_see_all_appeals' => 'How can I see the full appeal list?', 'cand_waiting_colours' => 'What do the waiting-day colours mean?',
                ],
                default => [
                    'cand_cgs_expiring' => 'How many students expire within 3 months?', 'cand_cgs_no_action' => 'How many of them have taken no action?', 'cand_cgs_pending_by_step' => 'How many appeals are waiting at each step?',
                    'cand_cgs_waiting_10' => 'Which appeals have waited more than 10 days?', 'cand_cgs_dismissal_candidates' => 'How many dismissal candidates are there?', 'cand_cgs_export_registry' => 'How do I export the dismissal list?',
                    'cand_see_all_appeals' => 'How can I see the full appeal list?', 'cand_cgs_operations_guide' => 'How do I read the Operations page?', 'cand_cgs_registry_upload' => 'How do I upload the Registry\'s result?',
                ],
            };
        }

        return $out;
    }

    public static function findIdByExactText(string $typed, string $role): ?string
    {
        $needle = mb_strtolower(trim(preg_replace('/\s+/', ' ', $typed) ?? $typed));

        foreach (self::suggestions($role) as $id => $text) {
            if (mb_strtolower($text) === $needle) {
                return $id;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ catalogue entries

    /** @return array<int, array<string, mixed>> */
    public static function entries(): array
    {
        $entries = [];
        $suggested = [];

        foreach (['student', 'approver', 'cgs'] as $r) {
            $suggested += self::suggestions($r);
        }

        foreach (self::lookupSpecs() as $id => $spec) {
            $entries[] = [
                'id' => $id, 'kind' => 'lookup', 'domain' => self::DOMAIN, 'roles' => $spec['roles'], 'question' => $spec['question'], 'description' => $spec['description'],
                'examples' => $spec['examples'], 'parameters' => $spec['parameters'] ?? [], 'defaults' => $spec['defaults'] ?? [], 'link' => $spec['link'] ?? null, 'template' => CandidacyLookups::methods()[$id],
            ];
        }

        foreach (self::topicSpecs() as $id => $spec) {
            if (! isset(self::topicRoles()[$id])) {
                continue;
            }

            $entries[] = [
                'id' => $id, 'kind' => 'guidance', 'domain' => self::DOMAIN, 'roles' => self::topicRoles()[$id], 'question' => $suggested[$id] ?? $spec['question'], 'description' => $spec['description'],
                'examples' => $spec['examples'], 'parameters' => [], 'link' => null,
            ];
        }

        foreach (self::actionSpecs() as $id => $spec) {
            $entries[] = ['id' => $id, 'kind' => 'action', 'domain' => self::DOMAIN, 'roles' => $spec['roles'], 'question' => $spec['question'], 'description' => $spec['description'], 'examples' => $spec['examples'], 'parameters' => [], 'link' => $spec['link'], 'pattern' => $spec['pattern']];
        }

        return $entries;
    }

    /** @return array<string, array<string, mixed>> */
    protected static function lookupSpecs(): array
    {
        $S = ['student'];
        $A = ['approver'];
        $C = ['cgs'];
        $window = ['window' => ['type' => 'string', 'required' => false, 'hint' => 'optional: this_month, last_month or last_30_days']];

        return [
            'cand_my_expiry' => ['roles' => $S, 'question' => 'When does my candidacy expire?', 'description' => "The student's own candidacy expiry date and how many days are left.", 'link' => 'candidacy.status',
                'examples' => ['when does my candidacy end', 'my candidacy expiry date pls', 'how many days left on my candidacy', 'when will my candidature finish', 'candidacy deadline for me', 'expiry date of my study candidacy']],
            'cand_my_extension' => ['roles' => $S, 'question' => 'How many months of extension do I have left?', 'description' => "The student's own months of extension used and left, out of the maximum (12) across all appeals.", 'link' => 'candidacy.status',
                'examples' => ['how many extension months remain', 'extension months used so far', 'how much extension can i still get', 'months left to appeal for', 'my remaining extension', 'have i used up my extension']],
            'cand_can_appeal' => ['roles' => $S, 'question' => 'Can I still submit an appeal?', 'description' => "Whether the student may submit an appeal NOW, with the reason when not (same check as the New appeal button). Not how to appeal (that is cand_when_how_appeal).", 'link' => 'candidacy.appeals.new',
                'examples' => ['am i allowed to appeal now', 'can i apply for more time', 'is my appeal still possible', 'can i send in an appeal', 'eligible to appeal or not', 'why cant i start an appeal']],
            'cand_my_appeal' => ['roles' => $S, 'question' => 'Where is my appeal now?', 'description' => "The student's own latest appeal: its number, status, current step and who has it.", 'link' => 'candidacy.appeals.show',
                'examples' => ['what is the status of my appeal', 'who has my appeal', 'has my appeal been approved yet', 'my appeal progress', 'which step is my appeal at', 'where did my appeal get to']],
            'cand_my_return_reason' => ['roles' => $S, 'question' => 'Why was my appeal returned?', 'description' => "The reason an approver returned the student's own appeal, and what to do next.", 'link' => 'candidacy.appeals.show',
                'examples' => ['why did they send my appeal back', 'reason my appeal was returned', 'what do i need to fix on my appeal', 'appeal returned what now', 'what was wrong with my appeal']],
            'cand_next_reminder' => ['roles' => $S, 'question' => 'When will I get my next reminder?', 'description' => "When the student's next automatic candidacy expiry reminder will be sent, or why none will.", 'link' => null,
                'examples' => ['when is my next expiry reminder', 'will i get another reminder', 'next candidacy reminder date', 'are reminders still coming for me', 'when do reminders go out for me']],
            'cand_my_softbound' => ['roles' => $S, 'question' => 'Is my Softbound submission recorded?', 'description' => "Whether the student's own Softbound thesis submission is recorded, and when.", 'link' => null,
                'examples' => ['did you get my softbound', 'is my soft bound thesis recorded', 'softbound submission status for me', 'has my thesis softbound been registered', 'do you have my softbound date']],

            'cand_ap_pending' => ['roles' => $A, 'question' => 'How many appeals are waiting for me?', 'description' => 'How many appeals are waiting for the approver\'s own action (same as the Approval Portal "Pending my action" tab).', 'link' => 'candidacy.portal.queue',
                'examples' => ['how many appeals do i need to act on', 'appeals pending my action', 'what is waiting in my queue', 'number of appeals for me to review', 'do i have appeals waiting', 'pending appeals count for me']],
            'cand_ap_oldest' => ['roles' => $A, 'question' => 'Which appeal has waited the longest?', 'description' => "The approver's own oldest waiting appeal and how many days it has waited.", 'link' => 'candidacy.portal.queue',
                'examples' => ['oldest appeal in my queue', 'which appeal is the most overdue for me', 'longest waiting appeal at my step', 'what has been waiting the longest', 'my most delayed appeal']],
            'cand_ap_waiting_days' => ['roles' => $A, 'question' => 'How many of my appeals have waited 5 or 10 days or more?', 'description' => "How many of the approver's own waiting appeals have waited 5 days or more and 10 days or more.", 'link' => 'candidacy.portal.queue',
                'examples' => ['how many of mine are over 5 days', 'appeals waiting more than 10 days for me', 'count of my late appeals', 'which of my appeals are overdue', 'how many have i kept waiting 10 days']],
            'cand_ap_decided_month' => ['roles' => $A, 'question' => 'How many appeals did I approve or return this month?', 'description' => "How many appeals the approver themself approved and returned this month.", 'link' => 'candidacy.portal.queue',
                'examples' => ['how many appeals have i approved this month', 'my returns this month', 'number i decided this month', 'approved and returned by me this month', 'what did i get through this month']],
            'cand_ap_ongoing' => ['roles' => $A, 'question' => 'How many of my appeals are ongoing?', 'description' => "How many appeals the approver is named on that are ongoing and not waiting for them now.", 'link' => 'candidacy.portal.queue',
                'examples' => ['how many ongoing appeals do i have', 'appeals i am named on still open', 'ongoing appeals count', 'my appeals not waiting on me', 'open appeals under my name']],

            'cand_cgs_in_progress' => ['roles' => $C, 'question' => 'How many appeals are in progress?', 'description' => 'The total number of appeals in progress across all steps (the Operations "In progress" card).', 'link' => 'candidacy.cgs.operations',
                'examples' => ['total appeals in progress', 'how many appeals are open', 'appeals currently being processed', 'in progress appeal count', 'how many appeals are active']],
            'cand_cgs_pending_by_step' => ['roles' => $C, 'question' => 'How many appeals are waiting at each step?', 'description' => 'Appeals waiting at each step: Supervisor, Department Chair, CGS verification and Dean.', 'link' => 'candidacy.cgs.operations',
                'examples' => ['appeals per step', 'how many are with the dean', 'breakdown of appeals by stage', 'which step has the most appeals', 'pending appeals at supervisor chair cgs dean']],
            'cand_cgs_outcome_counts' => ['roles' => $C, 'question' => 'How many appeals are returned, approved, rejected or closed expired?', 'description' => 'Counts of appeals returned to students, approved, rejected (final) and closed expired.', 'link' => 'candidacy.cgs.operations',
                'examples' => ['how many appeals approved so far', 'number of rejected appeals', 'returned appeals count', 'closed expired appeals total', 'outcomes of appeals summary']],
            'cand_cgs_oldest' => ['roles' => $C, 'question' => 'Which are the longest-waiting appeals?', 'description' => 'The five longest-waiting appeals with their step and days, each linked.', 'link' => 'candidacy.cgs.operations',
                'examples' => ['top 5 oldest appeals', 'which appeals have waited the longest', 'most delayed appeals now', 'oldest pending appeals list', 'longest queue waits']],
            'cand_cgs_waiting_10' => ['roles' => $C, 'question' => 'Which appeals have waited more than 10 days?', 'description' => 'How many appeals have waited 10 days or more at their current step, with a link to the filtered Operations list.', 'link' => 'candidacy.cgs.operations',
                'examples' => ['appeals waiting over 10 days', 'how many appeals are late', 'overdue appeals count', 'appeals stuck for 10 days or more', 'appeals waiting longer than ten days']],
            'cand_cgs_avg_waiting' => ['roles' => $C, 'question' => 'What is the average waiting time at each step?', 'description' => 'The average number of days appeals have waited at each step right now.', 'link' => 'candidacy.cgs.operations',
                'examples' => ['average days waiting per step', 'how long do appeals wait on average', 'mean waiting time at the dean', 'average wait by stage', 'typical delay at each step']],
            'cand_cgs_expiring' => ['roles' => $C, 'question' => 'How many students expire within {months} months?', 'defaults' => ['months' => 3], 'description' => 'How many students\' candidacies expire within 1, 2 or 3 months, ALL students (the candidacy Insights numbers).', 'link' => 'candidacy.cgs.insights',
                'parameters' => ['months' => ['type' => 'integer', 'required' => false, 'hint' => 'optional 1, 2 or 3']],
                'examples' => ['how many candidacies expire soon', 'students expiring in the next 3 months', 'candidacy expiries within a month', 'number of expiring candidacies', 'who is about to expire count', 'how many expire in 2 months']],
            'cand_cgs_no_action' => ['roles' => $C, 'question' => 'How many of them have taken no action?', 'description' => 'Of the students expiring within 3 months, how many have taken no action yet (the high-risk count on Insights).', 'link' => 'candidacy.cgs.insights',
                'examples' => ['how many expiring students did nothing', 'high risk students count', 'expiring with no softbound or appeal', 'students who have not acted yet', 'no action yet number']],
            'cand_cgs_twelve_of_twelve' => ['roles' => $C, 'question' => 'How many students are at 12 of 12 months?', 'description' => 'How many students have used all 12 months of extension, so no further appeal is possible.', 'link' => 'candidacy.cgs.insights',
                'examples' => ['students who used the full extension', 'how many have no more appeal months', 'twelve months used count', 'maxed out extension students', 'who cannot appeal any more']],
            'cand_cgs_by_department' => ['roles' => $C, 'question' => 'How many appeals are there per department?', 'description' => 'Submitted appeals counted by department.', 'link' => 'candidacy.cgs.operations',
                'examples' => ['appeals by department', 'which department has the most appeals', 'appeal count per department', 'department breakdown of appeals', 'appeals split by faculty department']],
            'cand_cgs_reminders_sent' => ['roles' => $C, 'question' => 'How many candidacy reminders were sent this month and last month?', 'description' => 'Candidacy expiry reminders sent this month and last month (or the named window).', 'link' => 'candidacy.cgs.insights', 'parameters' => $window,
                'examples' => ['reminders sent this month', 'how many expiry reminders went out', 'number of reminders last month', 'reminder count for the last 30 days', 'how many reminder e-mails were sent']],
            'cand_cgs_reminder_response' => ['roles' => $C, 'question' => 'What is the reminder response rate?', 'description' => 'The share of reminders followed by a Softbound submission or an appeal within 30 days.', 'link' => 'candidacy.cgs.insights',
                'examples' => ['do reminders work', 'response rate to reminders', 'how many students act after a reminder', 'reminder effectiveness', 'percentage who respond to reminders']],
            'cand_cgs_dismissal_candidates' => ['roles' => $C, 'question' => 'How many dismissal candidates are there?', 'description' => 'Dismissal candidates today, how many are exported and how many are not yet exported.', 'link' => 'candidacy.cgs.dismissals',
                'examples' => ['size of the dismissal list', 'how many students are on the dismissal list', 'dismissal candidates not yet exported', 'candidates awaiting the registry', 'number of expired candidacies to dismiss']],
            'cand_cgs_dismissed' => ['roles' => $C, 'question' => 'How many students were dismissed {window}?', 'defaults' => ['window' => 'this_month'], 'description' => 'How many students were dismissed in the window (default this month).', 'link' => 'candidacy.cgs.dismissal-upload', 'parameters' => $window,
                'examples' => ['dismissed students this month', 'how many dismissals last month', 'number dismissed recently', 'dismissals in the last 30 days', 'how many did the registry dismiss']],
        ];
    }

    /** @return array<string, array{question:string,description:string,examples:array<int,string>}> */
    public static function topicSpecsPublic(): array
    {
        return self::topicSpecs();
    }

    /** @return array<string, array{question:string,description:string,examples:array<int,string>}> */
    protected static function topicSpecs(): array
    {
        return [
            'cand_what_is_candidacy' => ['question' => 'What is study candidacy and how long can it last?', 'description' => 'A GENERAL explanation of what candidacy is and the Handbook durations by programme (PhD, Master, coursework, MBA). Not a person\'s own expiry date or appeal (a role without those lookups gets out_of_scope).', 'examples' => ['what does candidacy mean', 'how long is my candidacy period', 'maximum candidacy duration', 'how many months do i have to finish', 'what is the candidature length for phd']],
            'cand_when_how_appeal' => ['question' => 'When and how do I appeal for an extension?', 'description' => 'When a student may appeal for more time and the steps to do it. Not whether the student can appeal now (that is cand_can_appeal).', 'examples' => ['how do i apply for an extension', 'steps to appeal for more time', 'when can i appeal', 'how to submit an extension request', 'what is the appeal process']],
            'cand_required_documents' => ['question' => 'What documents do I need for an appeal?', 'description' => 'The official appeal form and the supporting documents, file types and size limits.', 'examples' => ['what should i attach to my appeal', 'which files are needed for the appeal', 'appeal form and supporting documents', 'what file types can i upload', 'documents required for extension']],
            'cand_approval_route' => ['question' => 'Who approves an appeal and in what order?', 'description' => 'The approval route Student > Supervisor > Department Chair > CGS verification > Dean, and who can do what.', 'examples' => ['who decides my appeal', 'approval steps explained', 'what is the order of approvers', 'does the dean approve appeals', 'appeal route from supervisor to dean']],
            'cand_status_meanings' => ['question' => 'What does each appeal status mean?', 'description' => 'GENERAL definitions of every appeal status (draft, with Supervisor, Chair, CGS, Dean, returned, approved, rejected, closed expired) in one line each. Not where a specific person\'s appeal is now.', 'examples' => ['what does returned mean', 'explain appeal statuses', 'what is closed expired', 'status glossary for appeals', 'what does with cgs mean']],
            'cand_after_return_rejection' => ['question' => 'What happens after an appeal is returned or rejected?', 'description' => 'What follows a return (fix and resubmit to the same step) and a Dean rejection (final).', 'examples' => ['my appeal was returned what now', 'what if the dean rejects my appeal', 'can i appeal again after rejection', 'resubmit after return', 'is a rejection final']],
            'cand_twelve_month_limit' => ['question' => 'What is the 12-month extension limit?', 'description' => 'The maximum of 12 months of extension in total across all appeals.', 'examples' => ['how many months can i extend in total', 'is there a maximum extension', 'what is the 12 month rule', 'limit on extension months', 'can i extend more than a year']],
            'cand_deadline' => ['question' => 'What is the deadline to appeal?', 'description' => 'An appeal must be submitted on or before the expiry date; the expiry day itself is allowed.', 'examples' => ['last day to submit an appeal', 'can i appeal on my expiry date', 'appeal deadline', 'until when can i apply for extension', 'is the expiry day too late']],
            'cand_after_approval' => ['question' => 'What happens after my appeal is approved?', 'description' => 'After Dean approval: the expiry date is recalculated and the memo is e-mailed to the student.', 'examples' => ['what happens when the dean approves', 'where is my approval memo', 'will my expiry date change after approval', 'approved appeal what next', 'do i get a letter after approval']],
            'cand_reminders' => ['question' => 'When do I get candidacy reminders?', 'description' => 'The 3, 2 and 1 month reminders at 08:00 and what stops them.', 'examples' => ['how do expiry reminders work', 'what emails will i get about my candidacy', 'when are reminders sent', 'what stops the reminders', 'do i get reminders before expiry']],
            'cand_if_expires' => ['question' => 'What happens if my candidacy expires?', 'description' => 'What follows when the candidacy ends without a Softbound submission: the dismissal list, the Registry, and the right to appeal for reinstatement.', 'examples' => ['what if i miss my candidacy end date', 'will i be dismissed', 'candidacy expired what now', 'what happens at the end of my candidature', 'can i be reinstated after dismissal']],
            'cand_softbound_ends' => ['question' => 'Does submitting the Softbound end my candidacy?', 'description' => 'The soft-bound thesis must be submitted for viva voce before the end of candidature; the submission ends the candidacy period.', 'examples' => ['does softbound stop the candidacy clock', 'why submit softbound before expiry', 'what is the softbound for', 'softbound and candidacy end', 'when must i submit the soft bound thesis']],
            'cand_deferment_mode_programme' => ['question' => 'Do deferment, change of mode or change of programme affect my candidacy?', 'description' => 'How deferment, a change of study mode and a change of programme can change the candidacy period, and who approves them.', 'examples' => ['does deferment count in my study period', 'change from full time to part time effect', 'changing my programme and the candidacy period', 'medical deferment and candidacy', 'who approves a change of study mode']],
            'cand_my_signature' => ['question' => 'How do I upload my signature?', 'description' => 'How an approver uploads or removes their signature on My Signature.', 'examples' => ['where do i add my signature', 'upload signature for approvals', 'my signature page', 'how to change my signature', 'signature needed to approve']],
            'cand_approver_rules' => ['question' => 'What can I do at my step?', 'description' => 'What the Supervisor, Department Chair, CGS and Dean can do at their step (approve, return, and only the Dean rejects) and the rules for each.', 'examples' => ['what actions do i have as an approver', 'can i reject an appeal', 'what are my options on an appeal', 'rules for approvers', 'approve or return what is the difference']],
            'cand_return_effect' => ['question' => 'What happens when I return an appeal?', 'description' => 'What a return does: it goes to the student, then back to the step that returned it (after a Dean return CGS re-checks first).', 'examples' => ['what happens after i return an appeal', 'where does a returned appeal go', 'does the student get notified when i return', 'return reasons required', 'after a dean return who checks first']],
            'cand_appeal_rules' => ['question' => 'What are the appeal rules?', 'description' => 'The rules of an appeal: eligibility, the deadline, the 12-month limit, one appeal at a time, no withdrawal after submission and the final Dean rejection.', 'examples' => ['rules for extension appeals', 'appeal rules summary', 'what are the conditions to appeal', 'can an appeal be withdrawn', 'how many appeals can a student have']],
            'cand_faq' => ['question' => 'What are the most common candidacy questions?', 'description' => 'A short generic FAQ. Not personal data such as an expiry date or an appeal status.', 'examples' => ['candidacy faq', 'common questions about appeals', 'frequently asked candidacy questions', 'quick answers about candidacy', 'candidacy help']],
            'cand_outcome_prediction' => ['question' => 'Will my appeal be approved?', 'description' => 'A request to predict whether an appeal will be approved or rejected. Only the approvers and the Dean decide; the assistant explains the rules and the status instead.', 'examples' => ['will my appeal be approved', 'is the dean going to accept my appeal', 'chances of approval for my extension', 'will they reject my appeal', 'how likely is my appeal to pass']],
            'cand_cgs_operations_guide' => ['question' => 'How do I read the Operations page?', 'description' => 'How to read Candidacy Management Operations: the cards, tabs, filters and the appeal list.', 'examples' => ['how to use candidacy operations', 'what do the operations cards mean', 'filters on the appeals list', 'explain the operations page', 'how to find an appeal in operations']],
            'cand_cgs_insights_guide' => ['question' => 'How do I read the Candidacy Insights page?', 'description' => 'What each CANDIDACY Insights chart shows (expiry forecast, high risk, bands, reminders, dismissal pipeline), how it is calculated and that the forecast is a projection from known expiry dates.', 'examples' => ['how to read candidacy insights', 'what does the expiry forecast show', 'how is high risk defined', 'explain the insights charts', 'is the forecast a prediction']],
            'cand_cgs_dismissal_list_guide' => ['question' => 'Who is on the Dismissal List?', 'description' => 'The rule for who is on the Dismissal List (expired, no Softbound, no appeal in review and so on) and how to read it.', 'examples' => ['who appears on the dismissal list', 'why is a student on the dismissal list', 'dismissal list rule', 'who is excluded from the dismissal list', 'when does someone leave the dismissal list']],
            'cand_cgs_export_registry' => ['question' => 'How do I export the dismissal list?', 'description' => 'How to export the Dismissal List to Excel, send it to the Registry, and upload the Registry\'s result (preview, then confirm).', 'examples' => ['how to export dismissal candidates', 'sending the list to the registry', 'how do i upload the registry result', 'dismissal upload steps', 'what happens after the registry confirms']],
            'cand_see_all_appeals' => ['question' => 'How can I see the full appeal list?', 'description' => 'Where to see the whole list of appeals: a student their own My Appeals table, an approver the Approval Portal tabs, CGS the Operations page.', 'examples' => ['where do i see all appeals', 'full list of appeals', 'show me every appeal', 'view all the appeals', 'how to see the appeal list', 'where can i find the complete appeal list']],
            'cand_hb_extension' => ['question' => 'What does the Handbook say about extending candidacy?', 'description' => 'The Handbook rule for an extension: apply to CGS on the supervisor or Chair recommendation, before or on the day candidacy ends, decided by the University.', 'examples' => ['handbook extension of candidacy', 'who recommends an extension', 'when must the extension be applied for', 'does the university approve extensions', 'graduate studies academic committee extension']],
            'cand_hb_dismissal' => ['question' => 'What does the Handbook say about dismissal and reinstatement?', 'description' => 'The Handbook rule that exceeding the maximum candidacy duration leads to dismissal, and that a reinstatement appeal goes to the Registry.', 'examples' => ['handbook dismissal rule', 'who decides dismissal', 'how do i appeal for reinstatement', 'reinstatement after dismissal', 'senate dismissal discretion']],
            'cand_how_decide' => ['question' => 'How do I approve or return an appeal?', 'description' => 'How an approver acts on an appeal in the Approval Portal: Review, then Approve or Return to student (Supervisor, Department Chair), Verify or Return (CGS), Approve, Return or Reject (Dean).', 'examples' => ['how do i approve an appeal', 'how to return an appeal to the student', 'how does the dean reject', 'where is the approve button', 'steps to verify an appeal', 'how do i act on an appeal']],
            'cand_return_reasons' => ['question' => 'What are the return reasons?', 'description' => 'The reasons that can be given when an appeal is returned (or, for the Dean, rejected) and what they mean.', 'examples' => ['why do appeals get returned', 'list of return reasons', 'what reasons can i choose when returning', 'common reasons for return', 'reject reasons for the dean']],
            'cand_dean_rejection' => ['question' => 'What happens when the Dean rejects an appeal?', 'description' => 'What a Dean rejection means: it is final, the expiry date stays, no further appeal, and what follows.', 'examples' => ['what if the dean rejects', 'is a rejection final', 'can i appeal again after rejection', 'effects of a dean rejection', 'dean reject confirmation']],
            'cand_withdraw' => ['question' => 'Can I withdraw an appeal?', 'description' => 'Whether an appeal can be withdrawn once submitted (it cannot).', 'examples' => ['can i cancel my appeal', 'withdraw an appeal after submitting', 'undo a submitted appeal', 'take back my appeal', 'is withdrawal possible']],
            'cand_resubmit' => ['question' => 'How do I resubmit a returned appeal?', 'description' => 'How a returned appeal is corrected and resubmitted, and where it goes next.', 'examples' => ['how to resubmit an appeal', 'edit my returned appeal', 'fix and send the appeal again', 'where does a resubmitted appeal go', 'resubmit after return']],
            'cand_notifications' => ['question' => 'Who is notified when?', 'description' => 'Who gets an e-mail and bell notification at each appeal event.', 'examples' => ['will i get an email when it is approved', 'who is notified when an appeal is returned', 'notifications for appeals', 'do i get a bell notification', 'when do emails go out']],
            'cand_waiting_colours' => ['question' => 'What do the waiting-day colours mean?', 'description' => 'The waiting-days dot colours: grey under 5 days, amber 5 to 9, red 10 or more.', 'examples' => ['what do the dots mean', 'waiting days colours', 'red dot meaning', 'amber dot on an appeal', 'colour legend for waiting days']],
            'cand_portal_guide' => ['question' => 'How do I use the Approval Portal?', 'description' => 'The Approval Portal tabs and filters and how to open an appeal.', 'examples' => ['approval portal tabs', 'filters in the approval portal', 'what is pending my action', 'how to use the portal', 'difference between ongoing and history']],
            'cand_cgs_registry_upload' => ['question' => 'How do I upload the Registry\'s result?', 'description' => 'How CGS uploads the Registry\'s confirmed list on Dismissal Upload: preview, then confirm.', 'examples' => ['upload the registry file', 'dismissal upload preview', 'how to confirm dismissals', 'what does will be dismissed mean', 'registry result upload steps']],
            'cand_cgs_not_built' => ['question' => 'What is not available yet?', 'description' => 'Candidacy features CGS may expect that are not built yet: Excel export of Operations, reminder log page, urgent reminders, manual expiry change.', 'examples' => ['can i export the operations list', 'is there a reminder log', 'urgent reminders 3 days', 'change a students expiry date manually', 'features not available yet']],
            'cand_cgs_process_summary' => ['question' => 'What is the CGS process for appeals and dismissals end to end?', 'description' => 'The whole CGS process in order: appeals route, reminders, the dismissal list, export, the Registry and upload.', 'examples' => ['explain the whole candidacy process', 'cgs process from appeal to dismissal', 'end to end candidacy workflow', 'overview of the candidacy system for cgs', 'how does everything fit together']],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    protected static function actionSpecs(): array
    {
        return [
            'cand_action_decide' => ['roles' => ['student', 'approver', 'cgs'], 'question' => 'Approve, return or reject an appeal for me.', 'description' => 'An IMPERATIVE to approve, return or reject an appeal. The assistant never decides; it explains. Not how an approval works (that is cand_approval_route).',
                'examples' => ['approve the appeal of my student now', 'reject this appeal', 'return that appeal to the student', 'please approve my appeal', 'decline the extension appeal'], 'link' => 'candidacy.portal.queue',
                'pattern' => '/\b(approve|reject|decline|return|accept)\b.*\bappeals?\b|\bappeals?\b.*\b(approve|reject|decline|accept)\b/'],
            'cand_action_submit' => ['roles' => ['student'], 'question' => 'Submit my appeal for me.', 'description' => 'An IMPERATIVE from a student to submit, file or withdraw their appeal for them. Not how to appeal (that is cand_when_how_appeal).',
                'examples' => ['submit my appeal for me', 'please file my extension appeal', 'send my appeal to the supervisor now', 'withdraw my appeal', 'start my appeal for me'], 'link' => 'candidacy.appeals.new',
                'pattern' => '/\b(submit|file|withdraw|cancel|send)\b.*\b(my\s+)?(extension\s+)?appeals?\b/'],
            'cand_action_reminders' => ['roles' => ['cgs'], 'question' => 'Send the candidacy reminders now.', 'description' => 'An IMPERATIVE to send or resend candidacy expiry reminders. Reminders go out automatically at 08:00.',
                'examples' => ['send reminders now', 'resend the expiry reminders', 'trigger the candidacy reminder emails', 'send a reminder to everyone expiring', 'email the reminders today'], 'link' => 'candidacy.cgs.insights',
                'pattern' => '/\b(send|resend|trigger|email)\b.*\breminders?\b/'],
            'cand_action_export_upload' => ['roles' => ['cgs'], 'question' => 'Export the dismissal list or upload the Registry file for me.', 'description' => 'An IMPERATIVE to export the Dismissal List or upload the Registry\'s file. Not how to do it (that is cand_cgs_export_registry).',
                'examples' => ['export the dismissal list now', 'upload the registry file for me', 'download the dismissal candidates', 'upload this dismissal confirmation', 'send the dismissal list to the registry'], 'link' => 'candidacy.cgs.dismissals',
                'pattern' => '/\b(export|upload|download)\b.*\b(dismissal|registry|candidates?|list)\b/'],
            'cand_action_dismiss' => ['roles' => ['cgs'], 'question' => 'Dismiss a student.', 'description' => 'An IMPERATIVE to dismiss a student. Dismissal only happens when the Registry\'s confirmed list is uploaded and confirmed.',
                'examples' => ['dismiss this student', 'dismiss all the expired students', 'mark them as dismissed now', 'remove the student from candidacy', 'dismiss the candidates on the list'], 'link' => 'candidacy.cgs.dismissals',
                'pattern' => '/\bdismiss\b(?!al)/'],
        ];
    }
}
