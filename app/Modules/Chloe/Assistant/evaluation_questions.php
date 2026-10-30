<?php

/**
 * `assistant:evaluate`'s question set, labelled with CATALOGUE ids
 * (Catalogue::all()). Each row:
 *   q       the question as typed
 *   role    'student' | 'cgs'
 *   id      the expected catalogue id
 *   accept  other ids that also count as correct ("with accepted
 *           alternatives" accuracy -- never merged into the strict one)
 *   params  expected parameters, normalised the way the router must
 *           return them (period "YYYY-MM" / "YYYY-MM..YYYY-MM", relative
 *           periods resolved against the labelling date below)
 *   group   original | contrast | messy | standard | followup
 *   recent  earlier questions, for a follow-up
 *
 * The router is not trained on any of this: no question here is copied
 * into the catalogue paraphrases or the few-shots (CatalogueTest checks
 * it), except the four texts Part 2.2 itself names as few-shots, which
 * appear here only in the 'contrast' group so the report can show them.
 *
 * Relative periods ("next month", "dec") are labelled for 2026-10-30 (AssistantEvaluate::LABELLED_FOR);
 * assistant:evaluate warns when it is run on another month.
 */


$q = fn (string $q, string $role, string $id, string $group, array $params = [], array $accept = [], array $recent = []) => array_filter(
    compact('q', 'role', 'id', 'group', 'params', 'accept', 'recent'),
    fn ($v) => $v !== []
);

return [
    // ---------------------------------------------------------------
    // The two original failing questions.
    // ---------------------------------------------------------------
    $q('how many seat will be release in November 2026?', 'cgs', 'release_forecast', 'original', ['period' => '2026-11']),
    $q('can u help me to mark 21010121 as key collected?', 'cgs', 'action_mark_key_collected', 'original', ['student_id' => '<ID1>']),

    // ---------------------------------------------------------------
    // Contrast groups: same words, different meaning.
    // ---------------------------------------------------------------
    // forecast vs outlook vs past releases vs action vs how-to
    $q('how many seat will be release in november 2026', 'cgs', 'release_forecast', 'contrast', ['period' => '2026-11']),
    $q('release seat 252', 'cgs', 'action_release', 'contrast', ['seat_number' => 252]),
    $q('can u help me to mark 21010121 as key collected', 'cgs', 'action_mark_key_collected', 'contrast', ['student_id' => '<ID1>']),
    $q('how do i release a seat', 'cgs', 'cgs_release', 'contrast'),
    $q('how many seats gonna be released in december 2026', 'cgs', 'release_forecast', 'contrast', ['period' => '2026-12']),
    $q('which seats are going to free up soon, no particular month', 'cgs', 'seat_release_outlook', 'contrast'),
    $q('how many seats did we release in the last 7 days', 'cgs', 'releases_this_week', 'contrast'),
    $q('release the seat number 140 now', 'cgs', 'action_release', 'contrast', ['seat_number' => 140]),
    $q('whats the procedure for releasing a student from a seat', 'cgs', 'cgs_release', 'contrast'),
    $q('expected releases in q3 2027', 'cgs', 'release_forecast', 'contrast', ['period' => '2027-07..2027-09']),
    // key marking: action vs how-to vs data
    $q('pls mark key returned for 21010122', 'cgs', 'action_mark_key_returned', 'contrast', ['student_id' => '<ID1>']),
    $q('how to mark a locker key as returned', 'cgs', 'cgs_keys', 'contrast'),
    $q('how many keys still not returned', 'cgs', 'keys_pending_return', 'contrast', [], ['return_outlook']),
    $q('how many keys waiting to be collected now', 'cgs', 'keys_pending_collection', 'contrast'),
    // maintenance: action vs how-to vs data
    $q('set seat 88 to maintenance please', 'cgs', 'action_set_maintenance', 'contrast', ['seat_number' => 88]),
    $q('how do i put a seat into maintenance', 'cgs', 'cgs_maintenance', 'contrast'),
    $q('which seats are currently under maintenance', 'cgs', 'maintenance_seats', 'contrast'),
    // assign vs reassign vs how-to
    $q('assign 21010123 to seat 45', 'cgs', 'action_assign', 'contrast', ['student_id' => '<ID1>', 'seat_number' => 45]),
    $q('reassign seat 45 to another student', 'cgs', 'action_reassign', 'contrast', ['seat_number' => 45]),
    $q('how do i reassign someone to a different seat', 'cgs', 'cgs_reassign', 'contrast'),
    $q('how does assigning a student work', 'cgs', 'cgs_assign', 'contrast'),
    // one block vs every block vs comparison
    $q('how many free seats in J2', 'cgs', 'seat_availability', 'contrast', ['block' => 'J2']),
    $q('give me occupied available maintenance counts for each block', 'cgs', 'seat_status_by_block', 'contrast'),
    $q('is N2 or J2 busier right now', 'cgs', 'block_comparison', 'contrast'),
    // single N vs distribution
    $q('how many candidacies expire in the next 3 months', 'cgs', 'candidacy_expiring', 'contrast', ['months' => 3]),
    $q('show the candidacy expiry buckets for seat holders', 'cgs', 'candidacy_expiry_distribution', 'contrast'),
    // occupancy vs almost full
    $q('occupancy for every room', 'cgs', 'room_occupancy', 'contrast'),
    $q('which rooms are close to full', 'cgs', 'almost_full_rooms', 'contrast'),
    // termination: action vs how-to vs data
    $q('withdraw the termination for seat 61', 'cgs', 'action_terminate_or_withdraw', 'contrast', ['seat_number' => 61]),
    $q('how does the vacate notice process work for cgs', 'cgs', 'cgs_termination', 'contrast'),
    $q('how many seats have a termination notice running', 'cgs', 'termination_notices', 'contrast'),
    // lost key: student vs CGS
    $q('my locker key hilang, what should i do', 'student', 'key_lost', 'contrast'),
    $q('student lost the key, what do i do in the system', 'cgs', 'cgs_keys', 'contrast'),
    // student: own data vs how-to vs action
    $q('whats my key status', 'student', 'my_locker_key', 'contrast'),
    $q('how do i request a key', 'student', 'locker_key_request', 'contrast'),
    $q('cancel my key request pls', 'student', 'action_cancel_request', 'contrast'),
    $q('how do i give back my workstation', 'student', 'change_or_return', 'contrast'),
    $q('which seat am i in', 'student', 'my_workstation', 'contrast'),
    $q('how many seats free for me', 'student', 'available_seats', 'contrast'),

    // ---------------------------------------------------------------
    // Messy / informal (the measured target set while there is no
    // held-out file).
    // ---------------------------------------------------------------
    // CGS
    $q('hw many seat free up nxt month', 'cgs', 'release_forecast', 'messy', ['period' => '2026-11']),
    $q('seat release forecast jan 2027 how many', 'cgs', 'release_forecast', 'messy', ['period' => '2027-01']),
    $q('how many gonna vacate in 2027', 'cgs', 'release_forecast', 'messy', ['period' => '2027-01..2027-12']),
    $q('next quarter got how many seat release', 'cgs', 'release_forecast', 'messy', ['period' => '2027-01..2027-03']),
    $q('any seat freeing up dis month', 'cgs', 'release_forecast', 'messy', ['period' => '2026-10'], ['seat_release_outlook']),
    $q('wat seats likely become empty soon', 'cgs', 'seat_release_outlook', 'messy'),
    $q('how many release past week ah', 'cgs', 'releases_this_week', 'messy'),
    $q('pls free up seat 19', 'cgs', 'action_release', 'messy', ['seat_number' => 19]),
    $q('kick 21010124 out of his seat', 'cgs', 'action_release', 'messy', ['student_id' => '<ID1>']),
    $q('can u release 21010125 seat for me', 'cgs', 'action_release', 'messy', ['student_id' => '<ID1>']),
    $q('help me tick seat 33 key as returned', 'cgs', 'action_mark_key_returned', 'messy', ['seat_number' => 33]),
    $q('mark seat 210 key collected ya', 'cgs', 'action_mark_key_collected', 'messy', ['seat_number' => 210]),
    $q('put seat 12 maintenance, chair broken', 'cgs', 'action_set_maintenance', 'messy', ['seat_number' => 12]),
    $q('seat 12 fixed already, bring it back', 'cgs', 'action_restore', 'messy', ['seat_number' => 12]),
    $q('clear hold seat 77 pls', 'cgs', 'action_release_hold', 'messy', ['seat_number' => 77]),
    $q('give seat 300 to 21010126', 'cgs', 'action_assign', 'messy', ['seat_number' => 300, 'student_id' => '<ID1>']),
    $q('move 21010127 to another seat can', 'cgs', 'action_reassign', 'messy', ['student_id' => '<ID1>']),
    $q('terminate 21010128 loan now', 'cgs', 'action_terminate_or_withdraw', 'messy', ['student_id' => '<ID1>']),
    $q('delete seat 5 from system', 'cgs', 'action_other', 'messy', ['seat_number' => 5]),
    $q('approve the pending thing for me', 'cgs', 'action_other', 'messy'),
    $q('n2 got empty seat?', 'cgs', 'seat_availability', 'messy', ['block' => 'N2']),
    $q('j2 still hv space or full', 'cgs', 'seat_availability', 'messy', ['block' => 'J2'], ['block_comparison']),
    $q('room N2-01-03 how many free', 'cgs', 'seat_availability', 'messy', ['room_code' => 'N2-01-03']),
    $q('which block more full one', 'cgs', 'block_comparison', 'messy'),
    $q('total seat numbers pls', 'cgs', 'overall_stats', 'messy'),
    $q('overall how many seat occupied', 'cgs', 'overall_stats', 'messy', [], ['seat_availability']),
    $q('who sitting at seat 87', 'cgs', 'seat_lookup', 'messy', ['seat_number' => 87]),
    $q('seat 140 status wat', 'cgs', 'seat_lookup', 'messy', ['seat_number' => 140]),
    $q('21010129 got seat or not', 'cgs', 'student_lookup', 'messy', ['student_id' => '<ID1>']),
    $q('check student 21010130 key status', 'cgs', 'student_lookup', 'messy', ['student_id' => '<ID1>']),
    $q('keys not collected yet how many ah', 'cgs', 'keys_pending_collection', 'messy'),
    $q('how many key overdue lah', 'cgs', 'keys_pending_return', 'messy', [], ['return_outlook']),
    $q('how many seat stuck waiting key', 'cgs', 'awaiting_key_return_seats', 'messy'),
    $q('how many ppl kena termination notice now', 'cgs', 'termination_notices', 'messy'),
    $q('maintenance seats list pls', 'cgs', 'maintenance_seats', 'messy'),
    $q('room by room how full', 'cgs', 'room_occupancy', 'messy'),
    $q('candidacy ending within 2 months how many', 'cgs', 'candidacy_expiring', 'messy', ['months' => 2]),
    $q('which room almost penuh', 'cgs', 'almost_full_rooms', 'messy'),
    $q('insights summary pls', 'cgs', 'insights_summary', 'messy'),
    $q('which programme most students sit here', 'cgs', 'programme_breakdown', 'messy'),
    $q('male female rooms which fuller', 'cgs', 'gender_comparison', 'messy'),
    $q('how many students never request key', 'cgs', 'key_status_overview', 'messy'),
    $q('candidacy expiry spread for everyone', 'cgs', 'candidacy_expiry_distribution', 'messy'),
    $q('how long ppl been sitting in their seat', 'cgs', 'length_of_stay_distribution', 'messy'),
    $q('status breakdown block by block', 'cgs', 'seat_status_by_block', 'messy'),
    $q('keys due back this week or overdue', 'cgs', 'return_outlook', 'messy', [], ['keys_pending_return']),
    $q('how to reassign ah', 'cgs', 'cgs_reassign', 'messy'),
    $q('steps to release seat pls', 'cgs', 'cgs_release', 'messy'),
    $q('how i assign new student', 'cgs', 'cgs_assign', 'messy'),
    $q('how set seat under maintenance one', 'cgs', 'cgs_maintenance', 'messy'),
    $q('wat is release hold for', 'cgs', 'cgs_release_hold', 'messy'),
    $q('student say key missing, i do wat', 'cgs', 'cgs_keys', 'messy'),
    $q('termination process how it works ah', 'cgs', 'cgs_termination', 'messy'),
    $q('how to export the seat list to excel', 'cgs', 'cgs_search_export', 'messy'),
    $q('which reason i choose for release when student graduate', 'cgs', 'cgs_reasons', 'messy'),
    $q('wat the daily job do automatically', 'cgs', 'cgs_automation', 'messy'),
    $q('how the forecast number calculate', 'cgs', 'cgs_insights_definitions', 'messy'),
    $q('can this bot do changes for me', 'cgs', 'cgs_assistant_scope', 'messy'),
    $q('awaiting key return means wat', 'cgs', 'cgs_status_glossary', 'messy', [], ['student_status_glossary']),
    $q('explain whole cgs side flow pls', 'cgs', 'cgs_system_guide', 'messy'),
    $q('system rules for cgs side summary', 'cgs', 'system_rules', 'messy'),
    $q('wat time cgs office open', 'cgs', 'contact_and_hours', 'messy'),
    $q('whats the weather like in tronoh', 'cgs', 'out_of_scope', 'messy'),
    $q('book me a meeting room for tmr', 'cgs', 'out_of_scope', 'messy'),
    $q('translate this email to malay for me', 'cgs', 'out_of_scope', 'messy'),
    // Student
    $q('wat seat i got', 'student', 'my_workstation', 'messy'),
    $q('my loan finish when ah', 'student', 'my_workstation', 'messy', [], ['eligibility_loan_period']),
    $q('my key how now', 'student', 'my_locker_key', 'messy'),
    $q('wen i must return key', 'student', 'my_locker_key', 'messy', [], ['locker_key_collect_return']),
    $q('got seat available for me or not', 'student', 'available_seats', 'messy'),
    $q('n2 vs j2 which got more space', 'student', 'block_comparison', 'messy'),
    $q('how i choose seat', 'student', 'select_seat', 'messy'),
    $q('y i cant see j2 rooms', 'student', 'visibility_rules', 'messy'),
    $q('i forgot to click confirm, still got my seat?', 'student', 'confirmation', 'messy'),
    $q('can change to other room ah', 'student', 'change_or_return', 'messy'),
    $q('where to get the locker key', 'student', 'locker_key_request', 'messy', [], ['locker_key_collect_return']),
    $q('where i return my key after finish', 'student', 'locker_key_collect_return', 'messy'),
    $q('key gone already, help', 'student', 'key_lost', 'messy'),
    $q('got notice to vacate, wat does it mean', 'student', 'termination_vacate', 'messy'),
    $q('can part time student get seat', 'student', 'eligibility_loan_period', 'messy'),
    $q('cgs open what time', 'student', 'contact_and_hours', 'messy'),
    $q('can sleep in the workstation or not', 'student', 'terms_of_use', 'messy'),
    $q('which reason to pick when giving back seat', 'student', 'student_return_reasons', 'messy'),
    $q('explain the whole process from start pls', 'student', 'student_system_guide', 'messy'),
    $q('pending collection means wat', 'student', 'student_status_glossary', 'messy'),
    $q('how many days i got to collect the key', 'student', 'student_timeline', 'messy', [], ['locker_key_request', 'locker_key_collect_return']),
    $q('pls cancel my locker key request', 'student', 'action_cancel_request', 'messy'),
    $q('update my phone number for me', 'student', 'action_other', 'messy'),
    $q('who won the badminton match yesterday', 'student', 'out_of_scope', 'messy'),
    $q('help me write my thesis intro', 'student', 'out_of_scope', 'messy'),
    $q('wifi password for the library', 'student', 'out_of_scope', 'messy'),
    $q('my seat number wat ah', 'student', 'my_workstation', 'messy'),
    $q('need to collect key by when', 'student', 'my_locker_key', 'messy', [], ['locker_key_collect_return', 'student_timeline']),
    $q('any empty seat in my rooms now', 'student', 'available_seats', 'messy'),
    $q('how book a workstation', 'student', 'select_seat', 'messy'),
    $q('some room cannot see one, why', 'student', 'visibility_rules', 'messy'),
    $q('confirm email never come, how', 'student', 'confirmation', 'messy'),
    $q('i want to give up my seat how', 'student', 'change_or_return', 'messy'),
    $q('key i broke it accidentally', 'student', 'key_lost', 'messy'),
    $q('how long can keep the workstation', 'student', 'eligibility_loan_period', 'messy'),
    $q('can bring my rice cooker to the room', 'student', 'terms_of_use', 'messy'),
    $q('cgs phone number pls', 'student', 'contact_and_hours', 'messy'),
    $q('how many seat will be release next month', 'student', 'out_of_scope', 'messy'),

    // ---------------------------------------------------------------
    // Standard phrasing.
    // ---------------------------------------------------------------
    // Student guidance
    $q('How do I select a workstation?', 'student', 'select_seat', 'standard'),
    $q('Why can\'t I see some rooms?', 'student', 'visibility_rules', 'standard'),
    $q('What happens if I do not confirm in time?', 'student', 'confirmation', 'standard'),
    $q('How do I return my workstation?', 'student', 'change_or_return', 'standard'),
    $q('How do I request a locker key?', 'student', 'locker_key_request', 'standard'),
    $q('How do I collect my locker key?', 'student', 'locker_key_collect_return', 'standard'),
    $q('I lost my locker key, what do I do?', 'student', 'key_lost', 'standard'),
    $q('What does a termination notice mean?', 'student', 'termination_vacate', 'standard'),
    $q('Am I eligible for a workstation?', 'student', 'eligibility_loan_period', 'standard'),
    $q('What are the opening hours?', 'student', 'contact_and_hours', 'standard'),
    $q('What is the term of use?', 'student', 'terms_of_use', 'standard'),
    $q('What reasons can I give when I return my workstation?', 'student', 'student_return_reasons', 'standard'),
    $q('Walk me through how the system works for students.', 'student', 'student_system_guide', 'standard'),
    $q('What do the key statuses mean?', 'student', 'student_status_glossary', 'standard'),
    $q('What deadlines are there in the system?', 'student', 'student_timeline', 'standard'),
    $q('What are the rules for using the workstation?', 'student', 'terms_of_use', 'standard'),
    $q('Where do I pick up my key and until when?', 'student', 'locker_key_collect_return', 'standard', [], ['locker_key_request']),
    $q('What time can I use my workstation?', 'student', 'contact_and_hours', 'standard', [], ['terms_of_use']),
    $q("What happens to my seat if I don't confirm?", 'student', 'confirmation', 'standard'),
    $q('Is there a deadline to pick up the key?', 'student', 'locker_key_request', 'standard', [], ['student_timeline', 'locker_key_collect_return']),
    $q('How do I know if I can hold a workstation?', 'student', 'eligibility_loan_period', 'standard'),
    $q('Why can I not request a new key?', 'student', 'locker_key_collect_return', 'standard', [], ['locker_key_request']),
    $q('What happens to my seat when my loan ends?', 'student', 'termination_vacate', 'standard', [], ['my_workstation']),
    $q('I have a damaged key.', 'student', 'key_lost', 'standard'),
    $q('How can I get my key marked as collected?', 'student', 'locker_key_collect_return', 'standard'),
    $q('Can I swap seats?', 'student', 'change_or_return', 'standard'),
    // Student lookups
    $q('What is my workstation?', 'student', 'my_workstation', 'standard'),
    $q('What is my locker key status?', 'student', 'my_locker_key', 'standard'),
    $q('How many seats are available?', 'student', 'available_seats', 'standard'),
    $q('Which block is more crowded, N2 or J2?', 'student', 'block_comparison', 'standard'),
    $q('Do I have a termination notice on my seat?', 'student', 'my_workstation', 'standard', [], ['termination_vacate']),
    // Student out of scope / actions
    $q('What is the capital of France?', 'student', 'out_of_scope', 'standard'),
    $q('Tell me a funny story.', 'student', 'out_of_scope', 'standard'),
    $q('Please cancel the key request I made yesterday.', 'student', 'action_cancel_request', 'standard'),
    // CGS lookups
    $q('How many seats are available overall?', 'cgs', 'overall_stats', 'standard', [], ['seat_availability']),
    $q('How many free seats are there in block N2?', 'cgs', 'seat_availability', 'standard', ['block' => 'N2']),
    $q('What are the overall stats?', 'cgs', 'overall_stats', 'standard'),
    $q('Who is in seat 252?', 'cgs', 'seat_lookup', 'standard', ['seat_number' => 252]),
    $q('Status of seat 10', 'cgs', 'seat_lookup', 'standard', ['seat_number' => 10]),
    $q('Look up student 22010001.', 'cgs', 'student_lookup', 'standard', ['student_id' => '<ID1>']),
    $q('How many keys are pending collection?', 'cgs', 'keys_pending_collection', 'standard'),
    $q('How many keys are overdue for return?', 'cgs', 'keys_pending_return', 'standard', [], ['return_outlook']),
    $q('How many seats are awaiting key return?', 'cgs', 'awaiting_key_return_seats', 'standard'),
    $q('How many seats have a termination notice?', 'cgs', 'termination_notices', 'standard'),
    $q('Which seats are in maintenance?', 'cgs', 'maintenance_seats', 'standard'),
    $q('What is the room occupancy?', 'cgs', 'room_occupancy', 'standard'),
    $q('How many students have candidacy expiring soon?', 'cgs', 'candidacy_expiring', 'standard', [], ['candidacy_expiry_distribution']),
    $q('How many candidacies expire within 6 months?', 'cgs', 'candidacy_expiring', 'standard', ['months' => 6]),
    $q('How many seats were released this week?', 'cgs', 'releases_this_week', 'standard'),
    $q('Which rooms are almost full?', 'cgs', 'almost_full_rooms', 'standard'),
    $q('Give me the key highlights for today.', 'cgs', 'insights_summary', 'standard'),
    $q('Show me the insights.', 'cgs', 'insights_summary', 'standard'),
    $q('Which seats might open up soon?', 'cgs', 'seat_release_outlook', 'standard'),
    $q('How many rooms are nearly fully booked?', 'cgs', 'almost_full_rooms', 'standard'),
    $q('Which block is fuller?', 'cgs', 'block_comparison', 'standard'),
    $q('Compare N2 and J2.', 'cgs', 'block_comparison', 'standard'),
    $q('N2 vs J2', 'cgs', 'block_comparison', 'standard'),
    $q('How crowded is block N2 compared with J2?', 'cgs', 'block_comparison', 'standard'),
    $q('Which programmes use workstations most?', 'cgs', 'programme_breakdown', 'standard'),
    $q('What are the most common programmes among current seat holders?', 'cgs', 'programme_breakdown', 'standard'),
    $q('Male versus female occupancy', 'cgs', 'gender_comparison', 'standard'),
    $q('How does occupancy compare between male and female rooms in J2?', 'cgs', 'gender_comparison', 'standard', ['block' => 'J2']),
    $q('How many keys are not requested?', 'cgs', 'key_status_overview', 'standard'),
    $q('Give me a breakdown of locker key status for current seat holders.', 'cgs', 'key_status_overview', 'standard'),
    $q('When do candidacies expire?', 'cgs', 'candidacy_expiry_distribution', 'standard'),
    $q('How long have students been in their seats?', 'cgs', 'length_of_stay_distribution', 'standard'),
    $q('What is the length of stay distribution for current seat holders in N2?', 'cgs', 'length_of_stay_distribution', 'standard', ['block' => 'N2']),
    $q('Break down occupied, available and maintenance seats for each block.', 'cgs', 'seat_status_by_block', 'standard'),
    $q('Which locker keys are due back soon?', 'cgs', 'return_outlook', 'standard'),
    $q('Which programmes dominate the female rooms?', 'cgs', 'programme_breakdown', 'standard', ['gender' => 'female']),
    $q('How many seats may be released in March 2027?', 'cgs', 'release_forecast', 'standard', ['period' => '2027-03']),
    $q('What is the release forecast for the first quarter of 2027?', 'cgs', 'release_forecast', 'standard', ['period' => '2027-01..2027-03']),
    $q('How many seats will become free next month?', 'cgs', 'release_forecast', 'standard', ['period' => '2026-11']),
    $q('How many seats are forecast to be released in 2027?', 'cgs', 'release_forecast', 'standard', ['period' => '2027-01..2027-12']),
    // CGS guidance
    $q('How do I reassign a student?', 'cgs', 'cgs_reassign', 'standard'),
    $q('How do I assign a student?', 'cgs', 'cgs_assign', 'standard'),
    $q('How do I set maintenance?', 'cgs', 'cgs_maintenance', 'standard'),
    $q('How do I release a hold?', 'cgs', 'cgs_release_hold', 'standard'),
    $q('How do I record that a key was collected?', 'cgs', 'cgs_keys', 'standard'),
    $q('How does release now work on a terminated seat?', 'cgs', 'cgs_termination', 'standard'),
    $q('How do I export results?', 'cgs', 'cgs_search_export', 'standard'),
    $q('What are the workstation system rules?', 'cgs', 'system_rules', 'standard', [], ['terms_of_use']),
    $q('Which reason should I pick when a student has left the programme?', 'cgs', 'cgs_reasons', 'standard'),
    $q('What do I do if a student lost their key?', 'cgs', 'cgs_keys', 'standard'),
    $q('Can I choose another seat for a student when the previous one is not released?', 'cgs', 'cgs_reassign', 'standard', [], ['cgs_assign']),
    $q('What should I pick for releasing a seat because of a technical fault?', 'cgs', 'cgs_reasons', 'standard'),
    $q('How do I find a student by their email?', 'cgs', 'cgs_search_export', 'standard'),
    $q('What should I choose when the lock was changed?', 'cgs', 'cgs_reasons', 'standard'),
    $q('Is cleaning a valid reason for maintenance?', 'cgs', 'cgs_reasons', 'standard', [], ['cgs_maintenance']),
    $q('What should I tell a student whose loan just expired?', 'cgs', 'cgs_termination', 'standard'),
    $q('How does the daily automation work?', 'cgs', 'cgs_automation', 'standard'),
    $q('How is the occupancy percentage on Insights calculated?', 'cgs', 'cgs_insights_definitions', 'standard'),
    $q('What can the assistant not do?', 'cgs', 'cgs_assistant_scope', 'standard'),
    $q('Explain each seat status and what moves a seat between them.', 'cgs', 'cgs_status_glossary', 'standard'),
    $q('Give me an overview of the whole CGS workflow.', 'cgs', 'cgs_system_guide', 'standard'),
    $q('A student reports a broken key.', 'cgs', 'cgs_keys', 'standard'),
    $q('When do I have to vacate?', 'cgs', 'cgs_termination', 'standard', [], ['termination_vacate']),
    $q('What happens when a student\'s candidacy runs out?', 'cgs', 'cgs_termination', 'standard', [], ['cgs_automation']),
    $q('How do I handle a student who wants a different seat?', 'cgs', 'cgs_reassign', 'standard'),
    // CGS actions
    $q('Please release seat 252.', 'cgs', 'action_release', 'standard', ['seat_number' => 252]),
    $q('How do I release seat 252?', 'cgs', 'action_release', 'standard', ['seat_number' => 252], ['cgs_release']),
    $q('Restore seat 101 to available.', 'cgs', 'action_restore', 'standard', ['seat_number' => 101]),
    $q('Release the hold on seat 9.', 'cgs', 'action_release_hold', 'standard', ['seat_number' => 9]),
    $q('Start a termination notice for seat 70.', 'cgs', 'action_terminate_or_withdraw', 'standard', ['seat_number' => 70]),
    $q('Change the room of seat 11.', 'cgs', 'action_other', 'standard', ['seat_number' => 11], ['action_reassign']),
    // CGS out of scope
    $q('What is the capital of Japan?', 'cgs', 'out_of_scope', 'standard'),
    $q('Recommend a good laptop for research.', 'cgs', 'out_of_scope', 'standard'),
    $q('How do I apply for a conference travel grant?', 'cgs', 'out_of_scope', 'standard'),

    // ---------------------------------------------------------------
    // Follow-ups, resolved against the earlier questions.
    // ---------------------------------------------------------------
    $q('and february?', 'cgs', 'release_forecast', 'followup', ['period' => '2027-02'], [], ['how many seats may be released in january 2027']),
    $q('how about Q2?', 'cgs', 'release_forecast', 'followup', ['period' => '2027-04..2027-06'], [], ['release forecast for q1 2027']),
    $q('what about N2', 'cgs', 'seat_availability', 'followup', ['block' => 'N2'], [], ['how many free seats in J2']),
    $q('and seat 90?', 'cgs', 'seat_lookup', 'followup', ['seat_number' => 90], [], ['who is in seat 89']),
    $q('same for 9 months', 'cgs', 'candidacy_expiring', 'followup', ['months' => 9], [], ['how many candidacies expire in 6 months']),
    $q('and for female rooms?', 'cgs', 'programme_breakdown', 'followup', ['gender' => 'female'], [], ['which programmes use workstations most']),
    $q('and in J2?', 'cgs', 'length_of_stay_distribution', 'followup', ['block' => 'J2'], [], ['how long have students been in their seats in N2']),
    $q('how about the key?', 'student', 'my_locker_key', 'followup', [], ['locker_key_request'], ['what is my workstation']),
];
