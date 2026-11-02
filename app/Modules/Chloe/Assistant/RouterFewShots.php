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
    public static function all(string $domain = 'workstation'): array
    {
        return $domain === 'candidacy' ? self::candidacy() : self::workstation();
    }

    /** @return array<int, array{question: string, id: string, params?: array<string, mixed>, recent?: array<int, string>}> */
    protected static function workstation(): array
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

    /**
     * The Study Candidacy router's own examples: invented, catalogue ids only, never a copy of an evaluation question or a catalogue paraphrase.
     *
     * @return array<int, array{question: string, id: string, params?: array<string, mixed>, recent?: array<int, string>}>
     */
    protected static function candidacy(): array
    {
        return [
            // own data vs the general explanation
            ['question' => 'what date does my study period stop', 'id' => 'cand_my_expiry'],
            ['question' => 'how long is a phd allowed to take in total', 'id' => 'cand_what_is_candidacy'],
            ['question' => 'am i past the limit for extensions', 'id' => 'cand_my_extension'],
            ['question' => 'what is the 12 month extension rule about', 'id' => 'cand_twelve_month_limit'],
            ['question' => 'whose desk is my appeal on at the moment', 'id' => 'cand_my_appeal'],
            ['question' => 'what does the status returned mean', 'id' => 'cand_status_meanings'],
            // imperative vs how-to
            ['question' => 'just approve the extension for that student', 'id' => 'cand_action_decide'],
            ['question' => 'how does an approver decide an appeal', 'id' => 'cand_approver_rules'],
            ['question' => 'upload the registry list for me', 'id' => 'cand_action_export_upload'],
            ['question' => 'what are the steps to upload what the registry sent back', 'id' => 'cand_cgs_export_registry'],
            ['question' => 'tell me if my appeal will go through', 'id' => 'cand_outcome_prediction'],
            // CGS aggregates: windows and counts
            ['question' => 'how many students finish within the next month', 'id' => 'cand_cgs_expiring', 'params' => ['months' => 1]],
            ['question' => 'reminders that went out in the last thirty days', 'id' => 'cand_cgs_reminders_sent', 'params' => ['window' => 'last_30_days']],
            ['question' => 'and the month before that?', 'id' => 'cand_cgs_reminders_sent', 'params' => ['window' => 'last_month'], 'recent' => ['how many reminders went out this month']],
            ['question' => 'students dismissed during the previous month', 'id' => 'cand_cgs_dismissed', 'params' => ['window' => 'last_month']],
            ['question' => 'who is waiting longest across all the steps', 'id' => 'cand_cgs_oldest'],
            ['question' => 'how many are sitting with the department chair', 'id' => 'cand_cgs_pending_by_step'],
            // approver
            ['question' => 'is there anything in my inbox from students', 'id' => 'cand_ap_pending'],
            ['question' => 'what have i cleared during this calendar month', 'id' => 'cand_ap_decided_month'],
            // informal, typed-fast and misspelled, per role (a shot is only shown to a role whose catalogue has its id)
            ['question' => 'wer can i see all my appeals', 'id' => 'cand_see_all_appeals'],
            ['question' => 'my appeal got sent back, y?', 'id' => 'cand_my_return_reason'],
            ['question' => 'can i cancel the appeal i already sent', 'id' => 'cand_withdraw'],
            ['question' => 'how to redo the appeal after they returned it', 'id' => 'cand_resubmit'],
            ['question' => 'wat docs to attch for the extension', 'id' => 'cand_required_documents'],
            ['question' => 'how i approve this thing', 'id' => 'cand_how_decide'],
            ['question' => 'which page got all the appeals i need to sign', 'id' => 'cand_see_all_appeals'],
            ['question' => 'what the red dot mean on my queue', 'id' => 'cand_waiting_colours'],
            ['question' => 'it says i need a signature before i can approve', 'id' => 'cand_my_signature'],
            ['question' => 'wher the whole list of appeals for everyone', 'id' => 'cand_see_all_appeals'],
            ['question' => 'registry sent the file back, what now', 'id' => 'cand_cgs_registry_upload'],
            ['question' => 'can i get the operations list in excel', 'id' => 'cand_cgs_not_built'],
            ['question' => 'students ending dis month how many', 'id' => 'cand_cgs_expiring', 'params' => ['months' => 1]],
            // out of scope, including the other domain and the other Handbook topics
            ['question' => 'am i allowed to change my supervisor', 'id' => 'out_of_scope'],
            ['question' => 'when is my proposal defence', 'id' => 'out_of_scope'],
            ['question' => 'which seat is free in n2 today', 'id' => 'out_of_scope'],
            ['question' => 'how do i collect my locker key', 'id' => 'out_of_scope'],
            ['question' => 'recommend a good laptop for coding', 'id' => 'out_of_scope'],
        ];
    }
}
