<?php

/**
 * Each team member's modules have one Non-Executive CGS officer of their own.
 * Travel, Claims and Publication are Norshahira's: at the CGS stage, the
 * "awaiting your action" email goes to her alone. Every CGS officer can
 * still see and act on them, in the queue, the lists and the dashboard.
 *
 * Read through Support\CgsOfficer. If no account has this address (a
 * teammate who has not loaded database/sql/cgs_officers.sql yet, or the test
 * database), every Non-Executive CGS is emailed instead.
 */
return [
    'officer' => env('NORHANIS_CGS_OFFICER', 'norshahira.cgs@utp.edu.my'),

    /*
     * The "CGS & Grant - Split Memo" email a student gets when a journal
     * publication on that cost centre is finally approved (Support\SplitMemo).
     *
     * contact:   where the student sends the signed memo.
     * templates: the memo template to attach, tried in order; the first
     *            file that exists is sent. A relative path is under
     *            storage/app. If none exists the email still goes, without
     *            the attachment, and a warning is logged.
     */
    'split_memo' => [
        'contact' => env('NORHANIS_SPLIT_MEMO_CONTACT', 'norshahira@utp.edu.my'),
        'templates' => array_values(array_filter([
            env('NORHANIS_SPLIT_MEMO_TEMPLATE'),
            'templates/split-memo-template.docx',
            'templates/split-memo-template.pdf',
        ])),
    ],
];
