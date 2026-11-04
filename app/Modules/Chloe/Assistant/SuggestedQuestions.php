<?php

namespace App\Modules\Chloe\Assistant;

use App\Modules\Core\Support\Role;

/**
 * PART C: twelve fixed questions with PREWRITTEN answers (six per role) --
 * answering one (POST .../suggestion) never touches the model or the
 * keyword matcher, so picking one never spends quota. 'static' answers
 * come from docs/knowledge/workstation/suggested_answers.md
 * (SuggestedAnswers::staticText()); 'dynamic' ones are built live from the
 * database through SuggestedAnswers::dynamic(), using the same lookups/
 * functions the chat's own lookups and the Insights page use. Fixed by
 * role, never built from config, since the id is the contract the chat
 * widget is written against.
 */
final class SuggestedQuestions
{
    /** @return array<string, array{text: string, type: 'static'|'dynamic', target: string, catalogue_id: string}> */
    public static function forRole(string $role): array
    {
        return match (true) {
            $role === Role::STUDENT => self::student(),
            $role === AssistantRoles::APPROVER => [],
            default => self::cgs(),
        };
    }

    /** The six questions of this role IN this domain: id => ['text' => ..., 'type' => ..., 'target' => ..., 'catalogue_id' => ...]. Candidacy ones are answered by catalogue id, no model call. */
    public static function forDomain(string $role, string $domain): array
    {
        if ($domain === 'candidacy') {
            $out = [];

            foreach (CandidacyAssistant::suggestions($role) as $id => $text) {
                $out[$id] = ['text' => $text, 'type' => 'catalogue', 'target' => $id, 'catalogue_id' => $id];
            }

            return $out;
        }

        return self::forRole($role);
    }

    public static function find(string $id, string $role): ?array
    {
        return self::forRole($role)[$id] ?? null;
    }

    /**
     * Part 2.1: a TYPED question whose text matches a suggested
     * question's own wording EXACTLY (case/whitespace-insensitive) is
     * answered the same way clicking the chip would -- no model call,
     * no quota spent -- before the router ever sees it.
     */
    public static function findIdByExactText(string $typedText, string $role): ?string
    {
        $needle = self::normalise($typedText);

        foreach (self::forRole($role) as $id => $suggestion) {
            if (self::normalise($suggestion['text']) === $needle) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Part 1.2: each suggested question IS a catalogue entry's canonical
     * question -- catalogue id => this exact chip text, for Catalogue to
     * use verbatim (never a second wording of the same question).
     *
     * @return array<string, string>
     */
    public static function textsByCatalogueId(): array
    {
        return collect([...self::student(), ...self::cgs()])
            ->mapWithKeys(fn (array $s) => [$s['catalogue_id'] => $s['text']])
            ->all();
    }

    protected static function normalise(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? $text));
    }

    /** @return array<string, array{text: string, type: 'static'|'dynamic', target: string, catalogue_id: string}> */
    protected static function student(): array
    {
        return [
            's1_rules' => ['text' => 'What are the workstation rules I must follow?', 'type' => 'static', 'target' => 's1_rules', 'catalogue_id' => 'terms_of_use'],
            's2_loan_end' => ['text' => 'When does my workstation loan end, and what happens then?', 'type' => 'dynamic', 'target' => 's2_loan_end', 'catalogue_id' => 'my_workstation'],
            's3_locker_key' => ['text' => 'What is my locker key status and deadline?', 'type' => 'dynamic', 'target' => 's3_locker_key', 'catalogue_id' => 'my_locker_key'],
            's4_change_workstation' => ['text' => 'Can I change to another workstation?', 'type' => 'static', 'target' => 's4_change_workstation', 'catalogue_id' => 'change_or_return'],
            's5_lost_key' => ['text' => 'What should I do if I lose my locker key?', 'type' => 'static', 'target' => 's5_lost_key', 'catalogue_id' => 'key_lost'],
            's6_get_key' => ['text' => 'Where can I get my locker key?', 'type' => 'static', 'target' => 's6_get_key', 'catalogue_id' => 'locker_key_request'],
        ];
    }

    /** @return array<string, array{text: string, type: 'static'|'dynamic', target: string, catalogue_id: string}> */
    protected static function cgs(): array
    {
        return [
            'c1_release_outlook' => ['text' => 'Which seats are likely to become free in the next 3 months?', 'type' => 'dynamic', 'target' => 'c1_release_outlook', 'catalogue_id' => 'seat_release_outlook'],
            'c2_almost_full' => ['text' => 'Which rooms are almost full?', 'type' => 'dynamic', 'target' => 'c2_almost_full', 'catalogue_id' => 'almost_full_rooms'],
            'c3_termination_detailed' => ['text' => 'How many students are in a termination notice period?', 'type' => 'dynamic', 'target' => 'c3_termination_detailed', 'catalogue_id' => 'termination_notices'],
            'c4_candidacy_expiry' => ['text' => "What happens when a student's candidacy expires?", 'type' => 'static', 'target' => 'c4_candidacy_expiry', 'catalogue_id' => 'cgs_termination'],
            'c5_reassign_release' => ['text' => 'How do I reassign or release a student?', 'type' => 'static', 'target' => 'c5_reassign_release', 'catalogue_id' => 'cgs_reassign'],
            'c6_key_lost_cgs_side' => ['text' => 'What should I do when a locker key is lost?', 'type' => 'static', 'target' => 'c6_key_lost_cgs_side', 'catalogue_id' => 'cgs_keys'],
        ];
    }
}
