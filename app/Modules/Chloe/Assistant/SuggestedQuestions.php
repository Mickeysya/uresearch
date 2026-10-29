<?php

namespace App\Modules\Chloe\Assistant;

use App\Modules\Core\Support\Role;

/**
 * PART C: twelve fixed questions with PREWRITTEN answers (six per role) --
 * answering one (POST .../suggestion) never touches the model or the
 * keyword matcher, so picking one never spends quota. 'static' answers
 * come from docs/knowledge/assistant/suggested_answers.md
 * (SuggestedAnswers::staticText()); 'dynamic' ones are built live from the
 * database through SuggestedAnswers::dynamic(), using the same lookups/
 * functions the chat's own lookups and the Insights page use. Fixed by
 * role, never built from config, since the id is the contract the chat
 * widget is written against.
 */
final class SuggestedQuestions
{
    /** @return array<string, array{text: string, type: 'static'|'dynamic', target: string}> */
    public static function forRole(string $role): array
    {
        return $role === Role::STUDENT ? self::student() : self::cgs();
    }

    public static function find(string $id, string $role): ?array
    {
        return self::forRole($role)[$id] ?? null;
    }

    /** @return array<string, array{text: string, type: 'static'|'dynamic', target: string}> */
    protected static function student(): array
    {
        return [
            's1_rules' => ['text' => 'What are the workstation rules I must follow?', 'type' => 'static', 'target' => 's1_rules'],
            's2_loan_end' => ['text' => 'When does my workstation loan end, and what happens then?', 'type' => 'dynamic', 'target' => 's2_loan_end'],
            's3_locker_key' => ['text' => 'What is my locker key status and deadline?', 'type' => 'dynamic', 'target' => 's3_locker_key'],
            's4_change_workstation' => ['text' => 'Can I change to another workstation?', 'type' => 'static', 'target' => 's4_change_workstation'],
            's5_lost_key' => ['text' => 'What should I do if I lose my locker key?', 'type' => 'static', 'target' => 's5_lost_key'],
            's6_get_key' => ['text' => 'Where can I get my locker key?', 'type' => 'static', 'target' => 's6_get_key'],
        ];
    }

    /** @return array<string, array{text: string, type: 'static'|'dynamic', target: string}> */
    protected static function cgs(): array
    {
        return [
            'c1_release_outlook' => ['text' => 'Which seats are likely to become free in the next 3 months?', 'type' => 'dynamic', 'target' => 'c1_release_outlook'],
            'c2_almost_full' => ['text' => 'Which rooms are almost full?', 'type' => 'dynamic', 'target' => 'c2_almost_full'],
            'c3_termination_detailed' => ['text' => 'How many students are in a termination notice period?', 'type' => 'dynamic', 'target' => 'c3_termination_detailed'],
            'c4_candidacy_expiry' => ['text' => "What happens when a student's candidacy expires?", 'type' => 'static', 'target' => 'c4_candidacy_expiry'],
            'c5_reassign_release' => ['text' => 'How do I reassign or release a student?', 'type' => 'static', 'target' => 'c5_reassign_release'],
            'c6_key_lost_cgs_side' => ['text' => 'What should I do when a locker key is lost?', 'type' => 'static', 'target' => 'c6_key_lost_cgs_side'],
        ];
    }
}
