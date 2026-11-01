<?php

namespace App\Modules\Chloe\Assistant;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Services\WorkstationAllocator;
use App\Modules\Core\Support\Role;
use Illuminate\Support\Facades\File;

/**
 * PART 5. One markdown section per topic, under docs/knowledge/assistant/
 * {student,cgs}/<topic>.md -- loaded fresh on every call (never cached, so
 * an edit to a knowledge file takes effect immediately), with
 * {{placeholder}} tokens filled from the real code constants at load time
 * (PART 5 #2) so the text can never silently drift from what the system
 * actually does.
 *
 * guidance(topic): the model only ever picks a topic name from the
 * allowed list (see composeGuidance()'s own validation below); it is
 * never handed the source text of a topic it did not ask for, and never
 * the text of a CGS topic when the asking role is student.
 */
final class GuidanceLibrary
{
    public const STUDENT_TOPICS = [
        'select_seat', 'visibility_rules', 'confirmation', 'change_or_return',
        'locker_key_request', 'locker_key_collect_return', 'key_lost',
        'termination_vacate', 'eligibility_loan_period', 'contact_and_hours', 'terms_of_use',
        'student_return_reasons',
        // Round 3 PART B: the complete system picture, not just the Term of Use.
        'student_system_guide', 'student_status_glossary', 'student_timeline',
    ];

    public const CGS_TOPICS = [
        'cgs_reassign', 'cgs_release', 'cgs_assign', 'cgs_maintenance', 'cgs_release_hold',
        'cgs_keys', 'cgs_termination', 'cgs_search_export', 'system_rules', 'cgs_reasons',
        // Round 3 PART B.
        'cgs_system_guide', 'cgs_status_glossary', 'cgs_automation', 'cgs_insights_definitions', 'cgs_assistant_scope',
    ];

    /** CGS may also ask the student topics; a student must never reach a CGS one. */
    public static function topicsForRole(string $role): array
    {
        $topics = [];

        foreach (AssistantRoles::set($role) as $plain) {
            $topics = array_merge($topics, match ($plain) {
                Role::STUDENT => [...self::STUDENT_TOPICS, ...CandidacyAssistant::topicsFor('student')],
                AssistantRoles::APPROVER => CandidacyAssistant::topicsFor('approver'),
                default => [...self::STUDENT_TOPICS, ...self::CGS_TOPICS, ...CandidacyAssistant::topicsFor('cgs')],
            });
        }

        return array_values(array_unique($topics));
    }

    public static function isAllowed(string $topic, string $role): bool
    {
        return in_array($topic, self::topicsForRole($role), true);
    }

    /**
     * PART 1 #5: one short line per topic for the model's own tool
     * catalogue (see Pipeline::toolDefinitionsForRole()) -- bare topic
     * names alone were not enough for the free-tier model to tell
     * "How do I mark a key as collected?" (guidance) apart from an
     * actual command, or "my key got lost" (key_lost) from out_of_scope.
     * Kept to one line each so the whole catalogue stays short.
     *
     * @return array<string, string>
     */
    public static function topicDescriptions(): array
    {
        return [
            ...array_map(fn ($spec) => $spec['description'], CandidacyAssistant::topicSpecsPublic()),
            'select_seat' => 'How to browse and select a workstation seat.',
            'visibility_rules' => 'Why some rooms or seats are not shown to this student.',
            'confirmation' => 'What happens if a seat selection is not confirmed in time.',
            'change_or_return' => 'How to change or return an already-assigned workstation.',
            'locker_key_request' => 'How to request a locker key.',
            'locker_key_collect_return' => 'How a student collects or returns a locker key. E.g. "How do I mark a key as collected?" -- a student cannot mark it themselves; this explains that CGS does.',
            'key_lost' => 'What to do if a locker key is lost, missing, misplaced, or damaged. E.g. "my key got lost, what do I do?", "I lost my locker key".',
            'termination_vacate' => 'What a termination or vacate notice means and when to move out.',
            'eligibility_loan_period' => 'Eligibility rules and how long a workstation loan lasts.',
            'contact_and_hours' => 'CGS contact details and opening hours.',
            'terms_of_use' => 'The Postgraduate Workstation Term of Use rules.',
            'student_return_reasons' => 'Which reason to choose when a student returns their own workstation.',
            'student_system_guide' => 'The whole student journey in order: eligibility, browsing, selecting, confirming, the locker key, returning/changing, termination, and which emails are sent when.',
            'student_status_glossary' => 'Every seat status and every locker key status a student can see, with a one-line meaning and what can happen next.',
            'student_timeline' => 'Every time limit in the system in one place (confirmation minutes, key collection/return days, vacate working days, office/access hours).',

            'cgs_reassign' => 'How CGS reassigns a seat to a different student (the how-to, not the action itself).',
            'cgs_release' => 'How CGS releases a seat (the how-to). E.g. "How do I release a seat?" is guidance; "Release seat 252" is an action_request instead, because it names a seat to act on.',
            'cgs_assign' => 'How CGS assigns a student to a seat (the how-to, not the action itself).',
            'cgs_maintenance' => 'How CGS sets a seat to maintenance status (the how-to, not the action itself).',
            'cgs_release_hold' => 'How CGS releases a hold on a seat (the how-to, not the action itself).',
            'cgs_keys' => 'How CGS marks a locker key as collected or returned, and what CGS does when a student reports a key lost, missing, or damaged. E.g. "How do I mark a key as collected?", "a student says their key is lost".',
            'cgs_termination' => 'How the termination/vacate notice process works, from the CGS side.',
            'cgs_search_export' => 'How to search for a student or seat, or export results.',
            'system_rules' => 'The overall workstation system rules, from the CGS side.',
            'cgs_reasons' => 'Which reason to choose for Release, Reassign, Assign, Set Maintenance, Release Hold or Withdraw Termination.',
            'cgs_system_guide' => 'The whole CGS side in order: the landing page, every seat action, locker keys, History/exports, and Insights.',
            'cgs_status_glossary' => 'Every seat status and locker key status from the CGS side, and exactly which action moves a seat from one to the next.',
            'cgs_automation' => 'Exactly what the daily job does on its own (which termination triggers are automatic, which are manual-only), and the key reminder/cancellation schedule.',
            'cgs_insights_definitions' => 'How each number on the Insights page is calculated, and its assumptions (the forecast is a maximum, appeals extend candidacy, Awaiting Key Return is excluded, etc).',
            'cgs_assistant_scope' => 'What this assistant itself can and cannot do: read-only, no names in questions, links instead of actions.',
        ];
    }

    public static function sourceText(string $topic): ?string
    {
        if (CandidacyAssistant::isTopic($topic)) {
            return CandidacyAssistant::sourceText($topic);
        }

        $directory = in_array($topic, self::CGS_TOPICS, true) ? 'cgs' : 'student';
        $path = base_path("docs/knowledge/assistant/{$directory}/{$topic}.md");

        if (! File::exists($path)) {
            return null;
        }

        return strtr(File::get($path), self::placeholderValues());
    }

    /**
     * PART 5 #2: every number the knowledge text can cite, pulled from the
     * real constants/config -- add a row here and a {{token}} in the
     * markdown, never a number typed directly into a .md file.
     *
     * @return array<string, string>
     */
    /** Public wrapper around placeholderValues() -- PART C's static suggested answers use the exact same {{tokens}} as a knowledge topic, so they substitute through the one source of truth too. */
    public static function applyPlaceholders(string $text): string
    {
        return strtr($text, self::placeholderValues());
    }

    protected static function placeholderValues(): array
    {
        return [
            '{{confirmation_minutes}}' => (string) WorkstationAllocator::PENDING_SELECTION_MINUTES,
            '{{key_collection_days}}' => (string) LockerKey::COLLECTION_PERIOD_DAYS,
            '{{key_collection_reminder_days}}' => (string) LockerKey::GRACE_DAYS,
            '{{return_period_days}}' => (string) WorkstationAllocator::RETURN_PERIOD_DAYS,
            '{{return_reminder_days_before}}' => (string) LockerKey::RETURN_REMINDER_DAYS_BEFORE,
            '{{vacate_working_days}}' => (string) WorkstationAllocator::TERMINATION_NOTICE_WORKING_DAYS,
            '{{max_appeal_months}}' => (string) StudyCandidacy::MAX_APPEAL_MONTHS,
            // Round 8 PART 5.4: added when the cgs_insights_definitions
            // topic started naming the almost-full threshold -- found
            // live, verifying the new knowledge text against the code,
            // that a placeholder with no matching entry here renders as
            // the literal string "{{almost_full_percent}}".
            '{{almost_full_percent}}' => (string) config('assistant.almost_full_percent', 90),
            // Study Candidacy topics
            '{{cand_max_extension_months}}' => (string) config('candidacy.max_extension_months', 12),
            '{{cand_max_supporting}}' => (string) \App\Modules\Chloe\Services\CandidacyDocumentService::MAX_SUPPORTING,
            '{{cand_max_file_mb}}' => (string) (\App\Modules\Chloe\Services\CandidacyDocumentService::MAX_BYTES / 1048576),
            '{{cand_reminder_months}}' => implode(', ', (array) config('candidacy.reminders.milestones', [3, 2, 1])),
        ];
    }

    /** Every digit-run in the model's reply must also appear somewhere in the source -- otherwise it is inventing a number, and the source text is returned instead. Public: Pipeline's new single-call guidance answer is validated the same way, against the knowledge text it was actually given plus the question itself. */
    public static function everyNumberAppearsIn(string $text, string $source): bool
    {
        preg_match_all('/\d+/', $text, $inAnswer);
        preg_match_all('/\d+/', $source, $inSource);
        $sourceNumbers = array_flip($inSource[0]);

        foreach ($inAnswer[0] as $number) {
            if (! isset($sourceNumbers[$number])) {
                return false;
            }
        }

        return true;
    }

    /**
     * PART B.1: every topic's own source text, concatenated, for the one
     * model call that now answers a typed question directly from context
     * (replacing the old two-step chooseTool()-then-composeGuidance()
     * flow entirely). Capped at config('assistant.max_knowledge_chars');
     * when the full text would not fit, each topic is scored by how many
     * of the question's own significant words its text shares, and only
     * the highest-scoring topics are kept, in that order, until the cap
     * is reached -- simple keyword relevance, not the model's own
     * judgement, so this stays a cheap, deterministic, pre-model step.
     *
     * @return array{text: string, fullChars: int, usedChars: int, topicsIncluded: array<int, string>}
     */
    public static function knowledgeTextForRole(string $role, string $maskedQuestion = ''): array
    {
        $topics = self::topicsForRole($role);
        $sections = [];

        foreach ($topics as $topic) {
            // a candidacy topic is shown as written (never composed by the model), so it is not part of the model's knowledge text
            if (CandidacyAssistant::isTopic($topic)) {
                continue;
            }

            $source = self::sourceText($topic);

            if ($source !== null) {
                $sections[$topic] = "### {$topic}\n{$source}";
            }
        }

        $full = implode("\n\n", $sections);
        $cap = (int) config('assistant.max_knowledge_chars', 8000);

        if (mb_strlen($full) <= $cap) {
            return ['text' => $full, 'fullChars' => mb_strlen($full), 'usedChars' => mb_strlen($full), 'topicsIncluded' => array_keys($sections)];
        }

        $questionWords = array_unique(array_filter(preg_split('/\W+/', mb_strtolower($maskedQuestion)), fn ($w) => mb_strlen($w) >= 4));

        $scored = [];
        foreach ($sections as $topic => $text) {
            $textLower = mb_strtolower($text);
            $score = 0;
            foreach ($questionWords as $word) {
                if (str_contains($textLower, $word)) {
                    $score++;
                }
            }
            $scored[$topic] = $score;
        }

        arsort($scored);

        // Round 3 PART B.3: the glossary and timeline topics answer so
        // many different questions (any status, any number) that they go
        // in FIRST, always, regardless of score -- only the remaining
        // budget is filled by keyword relevance.
        $always = array_values(array_intersect(self::alwaysIncludedTopics($role), array_keys($sections)));
        $ordered = [...$always, ...array_diff(array_keys($scored), $always)];

        $kept = [];
        $usedChars = 0;
        foreach ($ordered as $topic) {
            $len = mb_strlen($sections[$topic]);

            if ($usedChars + $len > $cap && $kept !== []) {
                continue;
            }

            $kept[$topic] = $sections[$topic];
            $usedChars += $len;
        }

        return ['text' => implode("\n\n", $kept), 'fullChars' => mb_strlen($full), 'usedChars' => $usedChars, 'topicsIncluded' => array_keys($kept)];
    }

    /** @return array<int, string> topic ids this role sees that are always kept, never dropped by the keyword-scoring cap -- each one answers too wide a range of questions (any status, any number) to risk losing to a low keyword score. */
    protected static function alwaysIncludedTopics(string $role): array
    {
        return $role === Role::STUDENT
            ? ['student_status_glossary', 'student_timeline']
            : ['cgs_status_glossary', 'student_status_glossary', 'student_timeline'];
    }

    /**
     * PART B.3: the model never writes a link itself -- this maps the
     * sources it CITED to real, existing routes, so a link can never be a
     * hallucinated URL. One topic can resolve to more than one link (the
     * Term of Use topic also offers the PDF).
     *
     * @param  array<int, string>  $topics
     * @return array<int, array{label: string, url: string}>
     */
    public static function linksForTopics(array $topics): array
    {
        $links = [];

        foreach ($topics as $topic) {
            if (CandidacyAssistant::isTopic($topic)) {
                continue; // a candidacy topic's links are CandidacyAssistant::linksForTopic()
            }

            if (! in_array($topic, [...self::STUDENT_TOPICS, ...self::CGS_TOPICS], true)) {
                continue;
            }

            if ($topic === 'terms_of_use') {
                $links[] = ['label' => 'Postgraduate Workstation Term of Use', 'url' => route('workstation.terms')];
                $links[] = ['label' => 'Download the original PDF', 'url' => route('workstation.terms.pdf')];

                continue;
            }

            if (in_array($topic, self::CGS_TOPICS, true)) {
                $links[] = ['label' => 'Workstation Management', 'url' => route('workstation.cgs.operations')];
            } else {
                $links[] = ['label' => 'Workstation page', 'url' => route('workstation.select')];
            }
        }

        // One link per target page, not one per topic that happened to resolve to the same page.
        return collect($links)->unique('url')->values()->all();
    }
}
