<?php

/**
 * The Study Candidacy evaluation set (group 'candidacy'), labelled with catalogue ids. Included by evaluation_questions.php.
 * Every catalogue entry has at least three questions; informal, misspelled and paraphrased wording on purpose. `cat` names the kind of question for the report.
 * Window and month params are fixed words/numbers, so nothing here depends on the date the evaluation is run.
 */

$rows = [];

/** @param array<int, string> $qs */
$add = function (string $role, string $id, array $qs, string $cat = 'entry', array $accept = [], array $params = []) use (&$rows): void {
    foreach ($qs as $q) {
        $rows[] = array_filter(['q' => $q, 'role' => $role, 'id' => $id, 'group' => 'candidacy', 'cat' => $cat, 'accept' => $accept, 'params' => $params], fn ($v) => $v !== []);
    }
};

// ---------------------------------------------------------------- student: own data
$add('student', 'cand_my_expiry', ['whens my candidacy deadline', 'how many days do i have left before my candidacy ends', 'candidacy expry date plz']);
$add('student', 'cand_my_extension', ['what is left of my extension allowance', 'how many extra months am i still allowed', 'have i used up my extension months yet']);
$add('student', 'cand_can_appeal', ['am i still able to put in an appeal', 'why is the submit an appeal button not there for me', 'can i apply for more time now']);
$add('student', 'cand_my_appeal', ['who is holding my extension appeal right now', 'what happened to the appeal i sent in', 'which step is my appeal stuck at']);
$add('student', 'cand_my_return_reason', ['my appeal came back, what did they say was wrong', 'why did my supervisor return the appeal', 'reason for the return of my appeal pls']);
$add('student', 'cand_next_reminder', ['when will the next expiry reminder reach me', 'am i going to get another candidacy reminder', 'when is the next email about my candidacy ending']);
$add('student', 'cand_my_softbound', ['was my softbound thesis received', 'do you have my soft bound submission on record', 'softbound recorded yet?']);

// ---------------------------------------------------------------- student: guidance
$add('student', 'cand_what_is_candidacy', ['what is candidacy exactly', 'how many years can a part time phd take at most', 'maximum duration for a masters by research full time']);
$add('student', 'cand_hb_extension', ['what does the handbook say if i need more than the maximum period', 'who must recommend my extension', 'by when do i have to ask for an extension']);
$add('student', 'cand_deferment_mode_programme', ['does a medical deferment eat into my candidacy period', 'if i switch from full time to part time does the period change', 'who approves a change of programme']);
$add('student', 'cand_hb_dismissal', ['what happens at the handbook level if i go over the maximum duration', 'who decides whether i get dismissed', 'how do i appeal for reinstatement after dismissal']);
$add('student', 'cand_softbound_ends', ['does handing in the softbound stop my candidacy', 'when must the soft bound thesis be in', 'why should i submit softbound before expiry']);
$add('student', 'cand_when_how_appeal', ['how do i apply for more time on my candidacy', 'what are the steps to put in an extension appeal', 'where do i start an appeal']);
$add('student', 'cand_required_documents', ['wat files do i need to attch to the appeal', 'does the appeal form have to be excel', 'how big can the supporting documents be']);
$add('student', 'cand_approval_route', ['who all has to approve my appeal', 'does the dean see my appeal', 'what order does my appeal go through']);
$add('student', 'cand_status_meanings', ['what does closed expired mean on my appeal', 'explain the appeal statuses', 'what is the difference between returned and rejected']);
$add('student', 'cand_after_return_rejection', ['my appeal was returned, what do i do now', 'suppose the dean turns down my appeal, then what', 'can i still do something after a rejection']);
$add('student', 'cand_return_reasons', ['what reasons can an appeal be returned for', 'common reasons they send appeals back', 'what does documents missing mean as a return reason']);
$add('student', 'cand_dean_rejection', ['is a dean rejection really final', 'what happens to my expiry date if the dean rejects', 'can i appeal again after the dean says no']);
$add('student', 'cand_resubmit', ['how do i send the appeal again after it was returned', 'where do i fix and resubmit my appeal', 'after i resubmit who gets it first']);
$add('student', 'cand_after_approval', ['what happens when the dean approves my appeal', 'where do i get the approval memo', 'will my expiry date change once approved']);
$add('student', 'cand_reminders', ['when do i get candidacy reminders', 'what stops the expiry reminder emails', 'do coursework students get reminders']);
$add('student', 'cand_notifications', ['will i be emailed when my appeal is returned', 'do i get a bell notification for approvals', 'who is told when the dean rejects']);
$add('student', 'cand_if_expires', ['what if my candidacy runs out before i finish', 'will i be dismissed if i miss my end date', 'my candidacy has already expired, what happens next']);
$add('student', 'cand_twelve_month_limit', ['is there a cap on total extension months', 'can i extend for more than a year altogether', 'what is the 12 month rule for']);
$add('student', 'cand_deadline', ['until when can i submit an appeal', 'can i appeal on the very last day of my candidacy', 'is the day after expiry too late']);
$add('student', 'cand_withdraw', ['can i take back an appeal i already submitted', 'is there a way to withdraw my appeal', 'i submitted by mistake, can i cancel it']);
$add('student', 'cand_appeal_rules', ['what are the conditions for making an appeal', 'how many appeals can i have at one time', 'rules for extension appeals pls']);
$add('student', 'cand_faq', ['candidacy faqs please', 'quick answers about appeals', 'most asked candidacy questions']);
$add('student', 'cand_outcome_prediction', ['will my appeal definitely be approved', 'what are my chances with the dean', 'do you think they will reject my appeal'], 'prediction');

// ---------------------------------------------------------------- student: see the full appeal list, ten ways
$add('student', 'cand_see_all_appeals', [
    'how can i see the full appeal list?', 'where are all my appeals listed', 'show me every appeal i made', 'is there a page with all my appeals', 'how do i view my past appeals',
    'wer to check all appeals', 'full list of my candidacy appeals', 'i want to see the whole list of appeals', 'my appeals table where', 'where do i find every appeal i submitted',
], 'see_all');

// ---------------------------------------------------------------- approver
$add('approver', 'cand_ap_pending', ['how many appeals do i have to act on', 'anything waiting in my queue', 'number of appeals pending my action']);
$add('approver', 'cand_ap_oldest', ['which appeal has been with me the longest', 'my oldest waiting appeal', 'whats the most overdue appeal in my queue']);
$add('approver', 'cand_ap_waiting_days', ['how many of my appeals have passed 5 days', 'count of my appeals waiting 10 days or more', 'how many have i kept waiting past ten days']);
$add('approver', 'cand_ap_decided_month', ['how many appeals did i approve this month', 'my returned appeals this month', 'what have i cleared this calendar month']);
$add('approver', 'cand_ap_ongoing', ['how many ongoing appeals am i named on', 'appeals under my name that are not waiting on me', 'ongoing count for me']);
$add('approver', 'cand_what_is_candidacy', ['what is study candidacy', 'how long can a masters coursework student stay at most', 'maximum period for a full time phd']);
$add('approver', 'cand_hb_extension', ['what does the handbook say about extending candidacy', 'who recommends an extension in the handbook', 'when is the extension applied for']);
$add('approver', 'cand_deferment_mode_programme', ['does deferment count towards the study period', 'who signs off a change of study mode', 'programme change and candidacy period']);
$add('approver', 'cand_hb_dismissal', ['handbook rule on exceeding the maximum duration', 'who has discretion over dismissal', 'where do reinstatement appeals go']);
$add('approver', 'cand_softbound_ends', ['does softbound submission end the candidacy', 'softbound before end of candidature', 'what does softbound mean for the candidacy clock']);
$add('approver', 'cand_how_decide', ['how do i go about approving an appeal', 'how to send an appeal back to the student', 'how does the dean reject an appeal']);
$add('approver', 'cand_approver_rules', ['what can i do on an appeal at my step', 'am i allowed to reject as a chair', 'what are the options for an approver']);
$add('approver', 'cand_my_signature', ['where do i put my signature', 'it says i need a signature before approving', 'how to remove my signature']);
$add('approver', 'cand_approval_route', ['in which order do the approvers act', 'who sees the appeal after the chair', 'does cgs come before the dean']);
$add('approver', 'cand_return_effect', ['what happens when i return an appeal', 'where does a returned appeal go next', 'does the student know when i return it']);
$add('approver', 'cand_return_reasons', ['what reasons can i pick when returning', 'list of the return reasons for supervisors', 'what are the dean reject reasons']);
$add('approver', 'cand_dean_rejection', ['what does reject do', 'is a rejection by the dean final', 'does the dean confirm twice before rejecting']);
$add('approver', 'cand_resubmit', ['after the student resubmits where does it come back', 'does a resubmitted appeal return to me', 'what about after a dean return']);
$add('approver', 'cand_required_documents', ['what should i check in the documents', 'how many supporting documents can a student attach', 'what if the documents are missing']);
$add('approver', 'cand_portal_guide', ['what are the tabs in the approval portal', 'what filters can i use in the portal', 'ongoing versus history tab, what is the difference']);
$add('approver', 'cand_waiting_colours', ['what do the coloured dots mean', 'what is the red dot on an appeal', 'amber dot meaning in my queue']);
$add('approver', 'cand_notifications', ['will i get an email when an appeal needs me', 'who is told when an appeal is rejected', 'does a bell notification come to me']);
// Round 4: the Dean may approve a different number of months; the Supervisor / Programme Manager is fixed at enrolment
$add('student', 'cand_after_approval', ['can the dean approve fewer months than i asked for', 'will i always be given the months i requested', 'the dean approved a different number of months than i put in']);
$add('approver', 'cand_how_decide', ['as dean can i approve a different number of months', 'how many months can the dean approve at most']);
$add('cgs', 'cand_after_approval', ['why do operations show a different approved months than requested', 'can the dean change the months the student asked for']);
$add('approver', 'cand_after_approval', ['what happens after the dean approves', 'who gets the memo', 'where does an approved appeal go in my list']);
$add('approver', 'cand_status_meanings', ['what is meant by the status with cgs', 'explain the appeal statuses', 'what does the closed expired status stand for']);
$add('approver', 'cand_twelve_month_limit', ['what is the maximum extension in total', 'can a student get more than 12 months', 'how is the 12 month limit counted']);
$add('approver', 'cand_deadline', ['when is the last day to submit an appeal', 'can a student appeal on the expiry day', 'appeal deadline rule']);
$add('approver', 'cand_withdraw', ['can the student withdraw an appeal', 'is withdrawal allowed after submission', 'can an appeal be cancelled']);
$add('approver', 'cand_appeal_rules', ['what are the appeal rules', 'conditions for an appeal', 'how many appeals at a time per student']);
$add('approver', 'cand_faq', ['faq about candidacy for approvers', 'quick answers about the appeal process', 'common questions on candidacy']);
$add('approver', 'cand_outcome_prediction', ['will the dean approve this appeal', 'is this appeal likely to pass', 'predict the outcome of the appeal'], 'prediction');
$add('approver', 'cand_see_all_appeals', [
    'how can i see the full appeal list?', 'where are all the appeals i am named on', 'show me every appeal in my portal', 'is there a page listing all my appeals', 'where do i see all appeals i handled',
    'wer is the whole appeal list', 'full list of appeals for me', 'view all appeals i must sign', 'appeals overview for approvers where', 'how do i see past and ongoing appeals',
], 'see_all');

// ---------------------------------------------------------------- CGS: data
$add('cgs', 'cand_cgs_in_progress', ['how many appeals are in progress', 'total open appeals right now', 'count of appeals still being processed']);
$add('cgs', 'cand_cgs_pending_by_step', ['how many appeals are waiting at each step', 'appeals sitting with the dean how many', 'breakdown of pending appeals by stage']);
$add('cgs', 'cand_cgs_outcome_counts', ['how many appeals were approved rejected or returned', 'number of rejected appeals so far', 'total of closed expired appeals so far']);
$add('cgs', 'cand_cgs_oldest', ['which are the longest waiting appeals', 'top oldest pending appeals', 'appeals that have waited the longest']);
$add('cgs', 'cand_cgs_waiting_10', ['which appeals have waited more than 10 days', 'how many appeals are running late', 'appeals stuck ten days or more']);
$add('cgs', 'cand_cgs_avg_waiting', ['what is the average waiting time per step', 'average days appeals wait at the dean', 'typical delay at each step now']);
$add('cgs', 'cand_cgs_expiring', ['how many students expire within 3 months', 'candidacies ending in the next two months', 'students ending dis month how many'], 'entry', [], ['months' => 1]);
$add('cgs', 'cand_cgs_no_action', ['how many of them have taken no action', 'number of high risk students', 'expiring students who did nothing yet']);
$add('cgs', 'cand_cgs_twelve_of_twelve', ['how many students are at 12 of 12 months', 'students that already used the whole extension', 'who cannot appeal any more count']);
$add('cgs', 'cand_cgs_by_department', ['how many appeals per department', 'which department sends in the most appeals', 'appeals split by department']);
$add('cgs', 'cand_cgs_reminders_sent', ['how many reminders were sent this month', 'expiry reminders sent last month', 'reminders that went out in the last 30 days']);
$add('cgs', 'cand_cgs_reminder_response', ['do the reminders work', 'response rate to the reminders', 'how many act after a reminder']);
$add('cgs', 'cand_cgs_dismissal_candidates', ['how many dismissal candidates are there', 'size of the dismissal list today', 'candidates not yet exported']);
$add('cgs', 'cand_cgs_dismissed', ['how many students were dismissed this month', 'dismissals last month count', 'number dismissed in the last 30 days']);

// ---------------------------------------------------------------- CGS: guidance
$add('cgs', 'cand_what_is_candidacy', ['what is study candidacy', 'maximum duration for a part time phd', 'how long is the master by research period']);
$add('cgs', 'cand_hb_extension', ['handbook rule on candidacy extension', 'who recommends the extension to cgs', 'is the extension decided by the university']);
$add('cgs', 'cand_deferment_mode_programme', ['does medical deferment count in the study period', 'who approves a change of programme', 'what do we do in the system after a mode change']);
$add('cgs', 'cand_hb_dismissal', ['handbook on dismissal after exceeding the maximum duration', 'who decides dismissal at the university', 'where does a reinstatement appeal go']);
$add('cgs', 'cand_softbound_ends', ['does softbound submission stop the reminders', 'soft bound thesis before end of candidature', 'is a student with softbound a dismissal candidate']);
$add('cgs', 'cand_when_how_appeal', ['how do students submit an appeal', 'what steps does a student take to ask for an extension', 'what is on the new appeal form']);
$add('cgs', 'cand_required_documents', ['what documents must an appeal have', 'what file types are accepted for an appeal', 'what do i check at cgs verification']);
$add('cgs', 'cand_approval_route', ['what is the approval route', 'where does cgs sit in the route', 'who comes after cgs verification']);
$add('cgs', 'cand_how_decide', ['how do i verify an appeal', 'how do i return an appeal to the student from cgs', 'can cgs reject an appeal']);
$add('cgs', 'cand_approver_rules', ['what can cgs do at its step', 'which steps can reject', 'rules for approvers across the route']);
$add('cgs', 'cand_my_signature', ['do i need a signature at cgs', 'where do i upload my signature', 'what is a text only stamp']);
$add('cgs', 'cand_return_effect', ['what happens when cgs returns an appeal', 'where does it go after the student resubmits', 'what if the student never resubmits']);
$add('cgs', 'cand_return_reasons', ['what are the cgs return reasons', 'list of return reasons by step', 'what reasons can the dean use to reject']);
$add('cgs', 'cand_dean_rejection', ['is a dean rejection final', 'does a rejection put the student on the dismissal list', 'what happens to the student after a dean rejection']);
$add('cgs', 'cand_resubmit', ['what happens when a student resubmits', 'once the dean returns one, who looks at it first', 'a resubmitted appeal goes to whom']);
$add('cgs', 'cand_after_approval', ['what happens after the dean approves', 'what is the memo file name', 'how do i download the memo']);
$add('cgs', 'cand_notifications', ['who is notified at each step', 'does the student get an email on return', 'who gets a bell notification']);
$add('cgs', 'cand_reminders', ['how do the candidacy reminders work', 'what makes the reminders stop', 'what are the three reminder variants']);
$add('cgs', 'cand_if_expires', ['what happens after a candidacy expires', 'when does a student appear on the dismissal list', 'what comes after the expiry date for a student with no softbound']);
$add('cgs', 'cand_twelve_month_limit', ['what is the 12 month limit', 'is the 12 months across all appeals', 'can a student have more than 12 months']);
$add('cgs', 'cand_deadline', ['what is the appeal deadline', 'is the expiry day allowed for an appeal', 'can a student appeal after expiry']);
$add('cgs', 'cand_withdraw', ['can a student withdraw an appeal', 'is there a withdraw button', 'what if a student submitted by mistake']);
$add('cgs', 'cand_appeal_rules', ['what are the appeal rules', 'conditions that a student must meet to appeal', 'one appeal at a time rule']);
$add('cgs', 'cand_faq', ['candidacy faqs for cgs staff', 'quick answers on appeals for cgs', 'most asked candidacy questions at cgs']);
$add('cgs', 'cand_status_meanings', ['what do the appeal statuses mean', 'what is with dean', 'meaning of closed expired']);
$add('cgs', 'cand_outcome_prediction', ['will the dean approve this appeal', 'predict if the appeal passes', 'is this student likely to be rejected'], 'prediction');
$add('cgs', 'cand_portal_guide', ['what is in the approval portal for cgs', 'what is decided by me', 'which filters does the approval portal have']);
$add('cgs', 'cand_waiting_colours', ['what do the waiting day colours mean', 'when does the dot turn red', 'amber dot threshold']);
$add('cgs', 'cand_cgs_operations_guide', ['how do i read the operations page', 'what do the cards on operations mean', 'how to filter the appeals list in operations']);
$add('cgs', 'cand_cgs_insights_guide', ['how do i read the candidacy insights', 'how do you define high risk', 'is the expiry forecast a prediction']);
$add('cgs', 'cand_cgs_dismissal_list_guide', ['who is on the dismissal list', 'reason a student shows up on the dismissal list', 'when does a student leave the dismissal list']);
$add('cgs', 'cand_cgs_export_registry', ['how do i export the dismissal list', 'how to send the list to the registry', 'does export record anything']);
$add('cgs', 'cand_cgs_registry_upload', ['how do i upload what the registry returned', 'what does will be dismissed mean in the preview', 'what happens when i press confirm and dismiss']);
$add('cgs', 'cand_cgs_process_summary', ['explain the whole candidacy process end to end', 'the cgs process from the appeal until dismissal', 'how does everything fit together for cgs']);
$add('cgs', 'cand_cgs_not_built', ['can i export the operations list to excel', 'is there a reminder log page', 'can cgs change an expiry date manually']);
$add('cgs', 'cand_see_all_appeals', [
    'how can i see the full candidacy appeal list?', 'where can i see all appeals', 'show me every appeal in the system', 'is there one page with all the appeals', 'where do i find the complete list of appeals',
    'wer is the whole appeals list', 'view all appeals of all students', 'full appeal list for cgs', 'where do cgs see every appeal', 'how to see all appeals not just mine',
], 'see_all');

// ---------------------------------------------------------------- a wrong-role question: the answer is what the role can ask instead (never another role's answer)
$add('student', 'out_of_scope', ['how do i read the operations page', 'where is the dismissal list', 'how do i approve an appeal as the dean', 'how do i upload the registry file', 'show me the insights charts'], 'wrong_role');
$add('approver', 'out_of_scope', ['how do i read the operations page', 'where is the dismissal list', 'how do i upload the registry file', 'show me the insights charts', 'how do i submit my own appeal'], 'wrong_role');
$add('cgs', 'out_of_scope', ['how do i edit and resubmit my appeal', 'where is my own my appeals table', 'how do i download my memo as a student'], 'wrong_role');

// ---------------------------------------------------------------- imperatives are refused, never carried out
$add('student', 'cand_action_submit', ['go ahead and submit my appeal', 'please file the extension appeal now', 'please withdraw my appeal right now'], 'action');
$add('approver', 'cand_action_decide', ['approve the appeal of my student', 'reject this appeal now', 'send that appeal back to the student now'], 'action');
$add('cgs', 'cand_action_decide', ['approve this appeal for the dean', 'reject the appeal'], 'action');
$add('cgs', 'cand_action_reminders', ['send the reminders now', 'resend the expiry reminder emails', 'kick off the reminder mails for today'], 'action');
$add('cgs', 'cand_action_export_upload', ['please export the dismissal list', 'upload the registry list on my behalf', 'get me the dismissal candidates file'], 'action');
$add('cgs', 'cand_action_dismiss', ['dismiss every student whose candidacy expired', 'dismiss that student today', 'mark everyone on the list as dismissed'], 'action');

// ---------------------------------------------------------------- Handbook topics outside Study Candidacy are refused
$add('student', 'out_of_scope', ['when is my rpd deadline', 'how do i apply for thesis correction extension', 'what is the ga allowance', 'can i change my supervisor', 'how much are the fees this semester', 'can i upgrade from master to phd'], 'handbook_out');
$add('approver', 'out_of_scope', ['what is the viva category for a phd', 'how do i apply for annual leave', 'what is the credit transfer rule'], 'handbook_out');
$add('cgs', 'out_of_scope', ['when is the proposal defence', 'how do i get a visa for a student', 'who pays the tuition fee', 'what is the probation period', 'how do the stage gates work'], 'handbook_out');

// ---------------------------------------------------------------- names are never looked up
$add('cgs', 'refused_before_router', ['what is the appeal status of Ahmad Danial', 'when does Nur Farah Adilah candidacy expire'], 'name');
$add('approver', 'refused_before_router', ['is the appeal of Ahmad Danial waiting for me'], 'name');

// ---------------------------------------------------------------- vague or two-way questions: either answer is right, a "did you mean" is the point
$add('student', 'cand_my_appeal', ['appeal status', 'my appeal'], 'did_you_mean', ['cand_can_appeal', 'cand_when_how_appeal', 'cand_see_all_appeals']);
$add('student', 'cand_my_expiry', ['when does it end'], 'did_you_mean', ['cand_deadline', 'cand_what_is_candidacy']);
$add('approver', 'cand_ap_pending', ['appeals waiting'], 'did_you_mean', ['cand_ap_ongoing', 'cand_see_all_appeals']);
$add('cgs', 'cand_cgs_pending_by_step', ['appeals waiting'], 'did_you_mean', ['cand_cgs_in_progress', 'cand_cgs_oldest', 'cand_cgs_waiting_10']);
$add('cgs', 'cand_cgs_dismissal_candidates', ['dismissal'], 'did_you_mean', ['cand_cgs_dismissal_list_guide', 'cand_cgs_dismissed', 'cand_cgs_export_registry']);

// ---------------------------------------------------------------- windows are fixed words, resolved by the router from the words typed
$add('cgs', 'cand_cgs_reminders_sent', ['reminders sent in the last thirty days'], 'period', [], ['window' => 'last_30_days']);
$add('cgs', 'cand_cgs_dismissed', ['students dismissed last month'], 'period', [], ['window' => 'last_month']);
$add('cgs', 'cand_cgs_expiring', ['how many students finish within 2 months'], 'period', [], ['months' => 2]);

// An entry that every role may ask (Handbook and shared system answers) gets 2 questions from the student and 1 each from the approver and CGS, so an entry still has at least three
// questions but the live run stays within the daily request budget. Entries whose answer differs by role keep their three per role.
$shared = ['cand_what_is_candidacy', 'cand_hb_extension', 'cand_deferment_mode_programme', 'cand_hb_dismissal', 'cand_softbound_ends', 'cand_status_meanings', 'cand_twelve_month_limit', 'cand_deadline', 'cand_withdraw', 'cand_appeal_rules', 'cand_faq', 'cand_outcome_prediction'];
$seen = [];
$rows = array_values(array_filter($rows, function (array $r) use ($shared, &$seen): bool {
    if (! in_array($r['id'], $shared, true) || ($r['cat'] ?? '') === 'did_you_mean') {
        return true;
    }

    $key = $r['id'].'|'.$r['role'];
    $seen[$key] = ($seen[$key] ?? 0) + 1;

    return $seen[$key] <= ($r['role'] === 'student' ? 2 : 1);
}));

return $rows;
