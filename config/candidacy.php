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

    // Roles that may see and act on an appeal while it is at the CGS stage ("cgs" is kept for a future role of that name).
    'cgs_stage_roles' => ['cgs', 'non_exec_cgs'],

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
            'reject' => [
                'justification_insufficient' => 'Justification insufficient',
                'progress_not_satisfactory' => 'Research progress not satisfactory',
                'policy_not_met' => 'Policy requirement not met',
                'other' => 'Other',
            ],
        ],
    ],

    // E-mail wording. Placeholders: {{student_name}} {{appeal_no}} {{stage}} {{decision}} {{reasons}} {{remarks}}
    // {{requested_months}} {{approved_months}} {{new_expiry_date}} {{expiry_date}} {{link}}. The subject always starts with the appeal number.
    'mail' => [
        'turn' => [
            'subject' => '{{appeal_no}}: appeal waiting for your action ({{stage}})',
            'title' => 'Appeal {{appeal_no}} is waiting for your action at the {{stage}} step.',
            'lines' => [
                'Dear colleague,',
                'The Study Candidacy Appeal {{appeal_no}} of {{student_name}} requests an extension of {{requested_months}} month(s) and is now waiting for your action ({{stage}}).',
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
            'title' => 'Appeal {{appeal_no}} was rejected by the Dean (final).',
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
            'title' => 'Your appeal {{appeal_no}} was rejected by the Dean (final).',
            'lines' => [
                'Dear {{student_name}},',
                'Your Study Candidacy Appeal {{appeal_no}} was rejected by the Dean of Postgraduate and Research. This decision is final: no further appeal can be submitted for this candidacy.',
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
