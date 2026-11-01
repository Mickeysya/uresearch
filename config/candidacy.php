<?php

/*
 * Study Candidacy Appeal settings (module: Chloe). The reason lists, waiting-day markers and notification
 * wording are added to this file by the phases that use them.
 */
return [
    // The extension allowance of a candidacy, in months, for ALL appeals together. The database also enforces it (CHECK 0..12).
    'max_extension_months' => 12,

    // The dean who signs an appeal whose department has no dean of its own: a candidacy_staff_profiles id, or null to
    // use the profile of the first user with role dean_pgr.
    'default_dean_profile' => env('CANDIDACY_DEFAULT_DEAN_PROFILE_ID') ? (int) env('CANDIDACY_DEFAULT_DEAN_PROFILE_ID') : null,

    // The nine departments, with their faculty (display names are exact; codes are stored). Keyed by code.
    'departments' => [
        'CHE' => ['Chemical Engineering', 'Faculty of Engineering'],
        'CEE' => ['Civil & Environmental Engineering', 'Faculty of Engineering'],
        'EEE' => ['Electrical & Electronic Engineering', 'Faculty of Engineering'],
        'MEC' => ['Mechanical Engineering', 'Faculty of Engineering'],
        'PET' => ['Petroleum Engineering', 'Faculty of Engineering'],
        'GEO' => ['Geoscience', 'Faculty of Engineering'],
        'APS' => ['Fundamental & Applied Sciences', 'Faculty of Science, Management & Computing'],
        'CIS' => ['Computer & Information Sciences', 'Faculty of Science, Management & Computing'],
        'MHU' => ['Management & Humanities', 'Faculty of Science, Management & Computing'],
    ],

    // The programme catalogue: the single source for programme names, their level and their department. `level` is coursework | master_research | phd.
    // Demo programme text comes from the demo seeders using this list; real students keep the text they have and use the keyword fallback below.
    'programmes' => [
        ['MSc in Process Safety', 'coursework', 'CHE'],
        ['MSc in Process Integration', 'coursework', 'CHE'],
        ['Master of Science in Chemical Engineering', 'master_research', 'CHE'],
        ['PhD in Chemical Engineering', 'phd', 'CHE'],
        ['MSc in Offshore Engineering', 'coursework', 'CEE'],
        ['MSc in Industrial Environmental Engineering', 'coursework', 'CEE'],
        ['MSc in Asset Management & Maintenance', 'coursework', 'CEE'],
        ['Master of Science in Civil Engineering', 'master_research', 'CEE'],
        ['PhD in Civil Engineering', 'phd', 'CEE'],
        ['Master of Science in Electrical and Electronics Engineering', 'master_research', 'EEE'],
        ['PhD in Electrical and Electronics Engineering', 'phd', 'EEE'],
        ['Master of Science in Mechanical Engineering', 'master_research', 'MEC'],
        ['PhD in Mechanical Engineering', 'phd', 'MEC'],
        ['Master of Science in Petroleum Engineering', 'master_research', 'PET'],
        ['PhD in Petroleum Engineering', 'phd', 'PET'],
        ['Master of Science in Petroleum Geoscience', 'master_research', 'GEO'],
        ['PhD in Petroleum Geoscience', 'phd', 'GEO'],
        ['MSc in Data Science', 'coursework', 'CIS'],
        ['Master of Science in Predictive Analytics', 'coursework', 'CIS'],
        ['MSc in Applied Computing', 'coursework', 'CIS'],
        ['Master of Science in Information Technology', 'master_research', 'CIS'],
        ['Master of Science in Information Systems', 'master_research', 'CIS'],
        ['PhD in Information Technology', 'phd', 'CIS'],
        ['PhD in Information Systems', 'phd', 'CIS'],
        ['Master of Science (Applied Science)', 'master_research', 'APS'],
        ['PhD in Applied Science', 'phd', 'APS'],
        ['Master of Philosophy in Management', 'master_research', 'MHU'],
        ['Master of Science in Social Science & Humanities', 'master_research', 'MHU'],
        ['PhD in Management', 'phd', 'MHU'],
        ['PhD in Social Science & Humanities', 'phd', 'MHU'],
        ['Doctor of Philosophy (General/Interdisciplinary)', 'phd', 'MHU'],
    ],

    // Department auto-fill (fallback for text that is not in the catalogue): the first department whose pattern matches the programme text (case-insensitive) wins. A text that matches
    // none is placed deterministically (hash of the student ID), so the result is stable. Order matters.
    'programme_department_patterns' => [
        'CHE' => ['/chemical/i'],
        'CEE' => ['/civil/i', '/environmental/i'],
        'EEE' => ['/electrical/i', '/electronic/i'],
        'MEC' => ['/mechanical/i'],
        'PET' => ['/petroleum/i'],
        'GEO' => ['/\\bgeo/i'],
        'CIS' => ['/computer/i', '/information/i', '/\\bIT\\b/', '/software/i'],
        'APS' => ['/applied science/i', '/chemistry/i', '/physics/i', '/mathematics/i'],
        'MHU' => ['/management/i', '/\\bMBA\\b/', '/humanities/i'],
    ],

    // These login accounts always belong to Computer & Information Sciences (the live demo accounts).
    'cis_demo_accounts' => ['student@utp.edu.my', 'chloe_26005007@utp.edu.my'],

    // Where the student's Softbound / completed / inactive status is read from (Support\StudentStatusReader). The shared Softbound module's table is
    // NOT known yet, so only `demo` is meaningful today:
    //   demo                       our labelled demo overlay only (until the modules are merged)
    //   shared_with_demo_fallback  the shared table first; the overlay only for demo students the shared source has no record of (recommended after merge)
    //   shared                     production: the shared table only, overlay off
    'status_source' => 'demo',

    // The shared source. To be filled in once the module owner gives the table name and fields; nothing is read while `table` is null.
    'status_source_table' => [
        'table' => null,              // e.g. the Softbound submissions table
        'student_id_column' => null,  // the student ID (matric number) column
        'submitted_at_column' => null,
        'status_column' => null,
        'submitted_values' => [],     // status values meaning "Softbound submitted"
        'completed_values' => [],
        'inactive_values' => [],      // inactive / deferred
    ],

    // Automatic expiry reminders (Support\CandidacyReminderEngine, command candidacy:send-reminders, daily 08:00 Asia/Kuala_Lumpur).
    'reminders' => [
        // Programme types that get reminders. "research" = Full-Time and Part-Time research; Coursework is excluded.
        'programme_types' => ['research'],
        // Variant B (no further appeal possible: 12 months used, or the last appeal was rejected by the Dean): still send the Softbound reminder?
        'send_when_appeal_blocked' => true,
        // Milestones in months before the expiry date.
        'milestones' => [3, 2, 1],
        // Our demo students (Workstation demo profiles) get reminders too, so the feature can be shown; switch off where demo students share a real mail system.
        'demo_students' => env('CANDIDACY_REMINDERS_DEMO_STUDENTS', env('APP_ENV') !== 'production'),
    ],

    // The CGS roles: the four CGS-team roles the Workstation module's CGS routes accept (Role::cgsTeam()), plus a role named "cgs".
    // They may open every appeal read-only and act at the CGS verification stage.
    'cgs_roles' => ['cgs', 'non_exec_cgs', 'senior_exec_cgs', 'manager_cgs', 'senior_director_cgs'],

    // The staff roles that sign an appeal, by login role. A CGS user's signature is optional (text-only stamp).
    'signature_required_stages' => ['supervisor', 'chair', 'dean'],

    // Calm waiting markers on the queue: no reminders, no escalation.
    'waiting_marker' => ['gold_days' => 5, 'red_days' => 10],

    // Signature images: PNG or JPEG, at most 1 MB, decoded to prove they are images, re-encoded as PNG, resized into 600x200.
    'signature' => ['max_bytes' => 1048576, 'min_width' => 200, 'min_height' => 60, 'max_width' => 600, 'max_height' => 200],

    // "Return to student" / "Reject" reason lists, per stage and action. The key is stored; the label is shown.
    'reasons' => [
        'supervisor' => ['return' => [
            'justification_unclear' => 'Unclear or insufficient justification',
            'progress_not_demonstrated' => 'Research progress not demonstrated',
            'publications_incomplete' => 'Publication list incomplete',
            'rcs_missing' => 'RCS status or date missing',
            'duration_not_justified' => 'Requested duration not justified',
            'wrong_supervisor_or_department' => 'Wrong supervisor or department',
            'documents_missing' => 'Supporting documents missing',
            'other' => 'Other',
        ]],
        'chair' => ['return' => [
            'justification_unclear' => 'Unclear or insufficient justification',
            'progress_not_demonstrated' => 'Research progress not demonstrated',
            'publications_incomplete' => 'Publication list incomplete',
            'rcs_missing' => 'RCS status or date missing',
            'duration_not_justified' => 'Requested duration not justified',
            'wrong_supervisor_or_department' => 'Wrong supervisor or department',
            'documents_missing' => 'Supporting documents missing',
            'other' => 'Other',
        ]],
        'cgs' => ['return' => [
            'form_missing_or_wrong' => 'Appeal form missing or wrong version',
            'documents_missing' => 'Required documents missing',
            'documents_unclear' => 'Documents unclear',
            'inconsistent_with_records' => 'Information inconsistent with records',
            'other' => 'Other',
        ]],
        'dean' => [
            'return' => [
                'clarification_needed' => 'Clarification needed',
                'documents_insufficient' => 'Supporting documents insufficient',
                'justification_insufficient' => 'Justification insufficient',
                'other' => 'Other',
            ],
            // Starting list (CGS will confirm it later). Keys are stored with the decision, so do not rename them; the labels are display-only.
            'reject' => [
                'progress_insufficient' => 'Research progress is insufficient to justify an extension',
                'plan_not_realistic' => 'Completion plan is not realistic within the requested period',
                'documents_insufficient' => 'Supporting documents are insufficient or invalid',
                'delay_reason_not_acceptable' => 'Reason for the delay is not acceptable',
                'previous_extension_not_used' => 'Previous extension was not used as planned',
                'other' => 'Other (remark required)',
            ],
        ],
    ],

    // Reason keys that were stored before the list above was replaced: still shown with their old wording, never an error.
    'legacy_reject_reasons' => [
        'justification_insufficient' => 'Justification insufficient',
        'progress_not_satisfactory' => 'Research progress not satisfactory',
        'policy_not_met' => 'Policy requirement not met',
    ],

    // E-mail wording. Placeholders: {{student_name}} {{appeal_no}} {{stage}} {{decision}} {{reasons}} {{remarks}}
    // {{requested_months}} {{approved_months}} {{new_expiry_date}} {{expiry_date}} {{link}}. The subject always starts with the appeal number.
    'mail' => [
        'turn' => [
            'subject' => '{{appeal_no}}: appeal pending your action ({{stage}})',
            'title' => 'Appeal {{appeal_no}} ({{department}}) is pending your action at the {{stage}} step.',
            'lines' => [
                'Dear colleague,',
                'The Study Candidacy Appeal {{appeal_no}} of {{student_name}} ({{department}}) requests an extension of {{requested_months}} month(s) and is now pending your action ({{stage}}).',
                'Please open the appeal to review it: {{link}}',
            ],
        ],
        'approved_student' => [
            'subject' => '{{appeal_no}}: your appeal was {{decision}} at the {{stage}} step',
            'title' => 'Your appeal {{appeal_no}} was {{decision}} at the {{stage}} step.',
            'lines' => [
                'Dear {{student_name}},',
                'Your Study Candidacy Appeal {{appeal_no}} was {{decision}} at the {{stage}} step and has moved on to the next step.',
                'You can follow its progress here: {{link}}',
            ],
        ],
        'returned_student' => [
            'subject' => '{{appeal_no}}: your appeal was returned for revision ({{stage}})',
            'title' => 'Your appeal {{appeal_no}} was returned for revision by the {{stage}} step.',
            'lines' => [
                'Dear {{student_name}},',
                'Your Study Candidacy Appeal {{appeal_no}} was returned for revision at the {{stage}} step.',
                'Reasons: {{reasons}}',
                'Remarks: {{remarks}}',
                'Please revise the appeal and resubmit it before {{expiry_date}}: {{link}}',
            ],
        ],
        'returned_info' => [
            'subject' => '{{appeal_no}}: the appeal was returned to the student ({{stage}})',
            'title' => 'Appeal {{appeal_no}} was returned to the student by the {{stage}} step.',
            'lines' => [
                'Dear colleague,',
                'The Study Candidacy Appeal {{appeal_no}} of {{student_name}} was returned to the student at the {{stage}} step.',
                'Reasons: {{reasons}}',
                'Remarks: {{remarks}}',
                'Details: {{link}}',
            ],
        ],
        'rejected' => [
            'subject' => '{{appeal_no}}: the appeal was rejected (final)',
            'title' => 'Appeal {{appeal_no}} was rejected by the Dean (final). Reasons: {{reasons}}',
            'lines' => [
                'Dear colleague,',
                'The Study Candidacy Appeal {{appeal_no}} of {{student_name}} was rejected by the Dean of Postgraduate and Research. This decision is final: no further appeal can be submitted for this candidacy.',
                'Reasons: {{reasons}}',
                'Remarks: {{remarks}}',
                'Details: {{link}}',
            ],
        ],
        'rejected_student' => [
            'subject' => '{{appeal_no}}: your appeal was rejected (final)',
            'title' => 'Your appeal {{appeal_no}} was rejected by the Dean (final). Reasons: {{reasons}}',
            'lines' => [
                'Dear {{student_name}},',
                'Your Study Candidacy Appeal {{appeal_no}} was rejected by the Dean of Postgraduate and Research. This decision is final: no further appeal can be submitted for this candidacy. Your expiry date stays at {{expiry_date}}.',
                'You can still submit your Softbound thesis before {{expiry_date}}. If no Softbound is submitted by then, you become a dismissal candidate for the Centre for Graduate Studies to review.',
                'Reasons: {{reasons}}',
                'Remarks: {{remarks}}',
                'Details: {{link}}',
            ],
        ],
        'final_student' => [
            'subject' => '{{appeal_no}}: your appeal was approved',
            'title' => 'Your appeal {{appeal_no}} was approved: {{approved_months}} month(s), new expiry {{new_expiry_date}}.',
            'lines' => [
                'Dear {{student_name}},',
                'Your Study Candidacy Appeal {{appeal_no}} has been approved by the Dean of Postgraduate and Research.',
                'Approved extension: **{{approved_months}} month(s)**',
                'New candidacy expiry date: **{{new_expiry_date}}**',
                'The approved memo is attached to this e-mail and is available from the appeal page: {{link}}',
            ],
        ],
        'final_info' => [
            'subject' => '{{appeal_no}}: the appeal was approved',
            'title' => 'Appeal {{appeal_no}} was approved: {{approved_months}} month(s), new expiry {{new_expiry_date}}.',
            'lines' => [
                'Dear colleague,',
                'The Study Candidacy Appeal {{appeal_no}} of {{student_name}} was approved by the Dean: {{approved_months}} month(s), new expiry date {{new_expiry_date}}.',
                'Details: {{link}}',
            ],
        ],
        'closed_expired' => [
            'subject' => '{{appeal_no}}: the appeal was closed because it was not resubmitted',
            'title' => 'Your appeal {{appeal_no}} was closed: it was not resubmitted before the candidacy expired.',
            'lines' => [
                'Dear {{student_name}},',
                'Your Study Candidacy Appeal {{appeal_no}} was returned to you and was not resubmitted before your candidacy expired on {{expiry_date}}, so it has been closed.',
                'Please contact the Centre for Graduate Studies if you need advice.',
                'Details: {{link}}',
            ],
        ],
    ],
];
