<?php

namespace App\Modules\Chloe\Assistant;

/**
 * Part 2.2: ~25 invented few-shot examples for the router prompt, as
 * contrast groups -- pairs/triples that share words but not meaning:
 *  - a named month/quarter/year release question -> release_forecast,
 *    never the period-less seat_release_outlook, never an action;
 *  - an imperative naming a seat/ID -> an action entry, never the
 *    lookup or the how-to topic for the same verb;
 *  - "how do I ..." -> the guidance topic, even with an action verb;
 *  - follow-ups resolved against the earlier questions.
 *
 * The four texts Part 2.2 itself names ("how many seat will be release
 * in november 2026", "release seat 252", "can u help me to mark <ID1>
 * as key collected", "how do i release a seat") are used verbatim; every
 * other one is newly invented and deliberately NOT a copy of any
 * evaluation or live-check question (CatalogueTest checks that), so a
 * measured result is never just the model repeating an example back.
 * Every ID is a placeholder, never a real one.
 *
 * Periods are absolute (never "next month") so the examples do not go
 * stale as today's date moves on; "recent" is the follow-up context.
 */
final class RouterFewShots
{
    /** @return array<int, array{question: string, id: string, params?: array<string, mixed>, recent?: array<int, string>}> */
    public static function all(): array
    {
        return [
            // release_forecast vs seat_release_outlook vs releases_this_week vs action_release.
            ['question' => 'how many seat will be release in november 2026', 'id' => 'release_forecast', 'params' => ['period' => '2026-11']],
            ['question' => 'how many seats could free up in the second quarter of 2027', 'id' => 'release_forecast', 'params' => ['period' => '2027-04..2027-06']],
            ['question' => 'seats to be released in 2027 total', 'id' => 'release_forecast', 'params' => ['period' => '2027-01..2027-12']],
            ['question' => 'anything likely to free up in the coming months', 'id' => 'seat_release_outlook'],
            ['question' => 'how many got released over the past week', 'id' => 'releases_this_week'],
            ['question' => 'release seat 252', 'id' => 'action_release', 'params' => ['seat_number' => 252]],

            // Imperative vs how-to, same verb.
            ['question' => 'can u help me to mark <ID1> as key collected', 'id' => 'action_mark_key_collected', 'params' => ['student_id' => '<ID1>']],
            ['question' => 'how do i mark a key as collected', 'id' => 'cgs_keys'],
            ['question' => 'how do i release a seat', 'id' => 'cgs_release'],
            ['question' => 'put <ID1> into seat 42 thanks', 'id' => 'action_assign', 'params' => ['student_id' => '<ID1>', 'seat_number' => 42]],
            ['question' => 'whats the process to assign someone a seat', 'id' => 'cgs_assign'],
            ['question' => 'seat 77 aircond broken, set it maintenance', 'id' => 'action_set_maintenance', 'params' => ['seat_number' => 77]],

            // One block vs every block vs N2-versus-J2.
            ['question' => 'n2 still have empty seat?', 'id' => 'seat_availability', 'params' => ['block' => 'N2']],
            ['question' => 'status counts for every block side by side', 'id' => 'seat_status_by_block'],
            ['question' => 'between the two blocks which one is more full', 'id' => 'block_comparison'],

            // Single N-month count vs the whole distribution.
            ['question' => 'how many candidacy ending within 6 months', 'id' => 'candidacy_expiring', 'params' => ['months' => 6]],
            ['question' => 'spread of candidacy expiry across all holders', 'id' => 'candidacy_expiry_distribution'],

            // Lost key: the CGS how-to vs the student's own guidance.
            ['question' => 'a student told me he lost his locker key, what now', 'id' => 'cgs_keys'],
            ['question' => 'i think my locker key is gone what do i do', 'id' => 'key_lost'],

            // Follow-ups, resolved against the earlier questions.
            ['question' => 'what about april?', 'id' => 'release_forecast', 'params' => ['period' => '2027-04'], 'recent' => ['how many seats may be released in march 2027']],
            ['question' => 'and for J2?', 'id' => 'seat_availability', 'params' => ['block' => 'J2'], 'recent' => ['how many free seats in N2']],
            ['question' => 'ok then september', 'id' => 'release_forecast', 'params' => ['period' => '2027-09'], 'recent' => ['seats released in july 2027', 'what about august']],

            // Student side.
            ['question' => 'when must i collect the key ah', 'id' => 'my_locker_key'],
            ['question' => 'when my loan finish', 'id' => 'my_workstation'],

            // Out of scope.
            ['question' => 'whats for lunch today', 'id' => 'out_of_scope'],
            ['question' => 'can you write my thesis abstract', 'id' => 'out_of_scope'],
        ];
    }
}
