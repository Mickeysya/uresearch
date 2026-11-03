<?php

return [

    'attendance' => [

        // Which AttendanceSource `attendance:sync` pulls from. One of:
        // inbox, api, simulated. See Support\AttendanceSourceFactory.
        'source' => env('NUREEN_ATTENDANCE_SOURCE', 'inbox'),

        // Where a Power Automate export (or anything standing in for one)
        // drops files for InboxFolderSource. Processed/failed files move
        // into subfolders here, never deleted.
        'inbox_path' => storage_path('app/attendance-inbox'),

        // Shared secret AttendanceIngestController checks the X-Api-Key
        // header against. Null means the endpoint refuses every request --
        // there is no "accept anything" fallback for a credential.
        'ingest_key' => env('NUREEN_ATTENDANCE_INGEST_KEY'),

        // ---- End-of-semester projection (Support\AttendanceProjector) ----
        // A period is whatever one attendance_records row covers -- the
        // module does not fix it. 14 matches the automated sync (one row per
        // week, period ending Friday; see AttendanceSources\SimulatedSource)
        // and UTP's 14-week semester. If CGS uploads MONTHLY periods instead,
        // set this to 4: with monthly data the projection can only start at
        // month 3 of 4, so it adds little warning beyond the current rule.
        // The 80% requirement itself is AttendanceRiskEvaluator::THRESHOLD,
        // not repeated here.
        'semester_periods' => (int) env('NUREEN_ATTENDANCE_SEMESTER_PERIODS', 14),

        // First day of the current semester (Y-m-d). Only periods ending on
        // or after it count towards the semester's figures. CGS sets this
        // each semester (NUREEN_ATTENDANCE_SEMESTER_START). The default is
        // the DEMO semester: Monday 5 October 2026, so four weekly periods
        // (weeks ending 9-30 Oct) are complete by 1 November -- early in a
        // 14-week semester, which is when a projection is worth having.
        // See Database\Seeders\NureenAttendanceDemoSeeder. Null = every
        // period on file.
        'semester_start' => env('NUREEN_ATTENDANCE_SEMESTER_START', '2026-10-05'),

        // How sessions excused by an APPROVED attendance appeal are counted
        // -- pending confirmation from CGS. See AttendanceRecord::countedAttended().
        //   'count_as_attended'  DEFAULT. Excused sessions count as attended:
        //                        (attended + excused) / total.
        //   'exclude_from_total' Excused sessions are taken out of the
        //                        period: attended / (total - excused).
        'excused_sessions' => env('NUREEN_ATTENDANCE_EXCUSED_SESSIONS', 'count_as_attended'),

        // Fewer usable periods than this (periods with zero sessions do not
        // count) and there is no projection at all, rather than a line
        // through two points.
        'projection_min_periods' => 3,

        // When the projection may FLAG a student (it is still computed and
        // shown to CGS outside this window; the original rule always
        // applies). Tuned on the backtest (`attendance:backtest --compare`):
        // a line through the first three periods is too noisy to act on,
        // and late in the semester the rule already sees what the
        // projection would. Shared by the live check and the backtest via
        // AttendanceRiskEvaluator::projectionMayFlag().
        //   projection_min_periods_to_flag: at least this many periods so far
        //   projection_active_until: up to this share of the semester
        //     (0.6 of 14 periods = up to period 8); 1.0 = all semester
        'projection_min_periods_to_flag' => (int) env('NUREEN_ATTENDANCE_PROJECTION_MIN_PERIODS_TO_FLAG', 4),
        'projection_active_until' => (float) env('NUREEN_ATTENDANCE_PROJECTION_ACTIVE_UNTIL', 0.6),

        // A student already alerted is emailed again only if their projected
        // end-of-semester % has fallen by MORE than this many percentage
        // points since that alert. See Support\AttendanceEarlyWarning.
        'realert_margin' => 5.0,

    ],

    'attendance_appeal' => [

        // CGS's requirement for an attendance appeal: field => the label the
        // files are attached under. AttendanceAppealController::store()
        // builds its validation from this list (DocumentStore::manyRules(),
        // the same multi-file helper GA Extension uses), the form renders
        // one upload per entry, and the approver's detail view checks each
        // is present -- one list, so the three cannot drift.
        'required_documents' => [
            'justification_letter_documents' => 'Justification Letter',
            'activity_breakdown_documents' => 'Monthly Activity Breakdown',
        ],

    ],

    'ga_extension' => [

        // field name => the label documents under it are attached with.
        // GaExtensionController::store() reads this to build validation
        // rules and attach each type; ga_extension/_detail.blade.php reads
        // the same list for the System checks panel, so a required type
        // cannot drift between what is enforced and what is shown.
        //
        // The GA Extension Form itself is no longer uploaded: the system
        // generates it from what the student enters (LetterRenderer::
        // gaExtensionForm()). Applications submitted before that still
        // carry their uploaded 'GA Extension Form' -- see legacy_documents.
        // Items 1-3 of the form's Section A checklist.
        'required_documents' => [
            'identity_document_documents' => 'IC or Passport/Visa Copy',
            'admission_letter_documents' => 'Admission Letter & Offer Letter',
            'supervisor_letter_documents' => "Supervisor's Supporting Letter",
        ],

        // Section A item 4: the proof for the student's stage gate level,
        // optional at submission (a Level 1 student has no paper yet).
        // GaExtensionRules checks the right one is attached for the level
        // worked out from rules.stage_gates, and warns if not.
        'stage_gate_documents' => [
            'acceptance_proof_documents' => 'Proof of Journal Article Acceptance',
            'published_article_documents' => 'Published Journal Article (front page)',
            'softbound_proof_documents' => 'Proof of Soft Bound Submission',
        ],

        // Document types only applications submitted before the form was
        // generated can have. Never asked for again; still shown wherever
        // an older application's documents are, and the combined proof
        // still counts towards Section A item 4.
        'legacy_documents' => [
            'form' => 'GA Extension Form',
            'stage_gate_proof' => 'Acceptance Letter or Published Paper',
        ],

        // Section B of UTP/CGS/001-A2. Name, Student ID, Programme and
        // Supervisor come from the student's profile and are never read
        // from the request. Each entry:
        //   label, type (select|radio|date|text|tel|email|yesno), required,
        //   options (select/radio), max (text/tel/email), placeholder,
        //   step: which step of the form it sits on ('details' or 'identity'),
        //   bank: only asked while collect_bank_details is true,
        //   sensitive: stored with the `encrypted` cast, masked everywhere
        //              but the CGS stage, never shown back on the form,
        //   required_if: required only when that yesno field is "yes".
        // Each key is a column on ga_extension_details.
        'applicant_fields' => [
            'mode_of_study' => ['label' => 'Mode of study', 'type' => 'select', 'required' => true, 'step' => 'details',
                'options' => ['full_time' => 'Full-time', 'full_time_odl' => 'Full-time ODL', 'part_time' => 'Part-time']],
            'admission_date' => ['label' => 'Admission date', 'type' => 'date', 'required' => true, 'step' => 'details'],
            'candidature_period' => ['label' => 'Period of candidature', 'type' => 'text', 'max' => 60, 'required' => true, 'step' => 'details',
                'placeholder' => 'e.g. Sep 2025 – Aug 2028'],
            'level_of_study' => ['label' => 'Level of study', 'type' => 'radio', 'required' => true, 'step' => 'details',
                'options' => ['msc' => 'MSc', 'phd' => 'PhD']],
            'contact_no' => ['label' => 'Contact no.', 'type' => 'tel', 'max' => 20, 'required' => true, 'step' => 'details',
                'placeholder' => 'e.g. +60 12-345 6789'],
            'contact_email' => ['label' => 'Email', 'type' => 'email', 'max' => 150, 'required' => true, 'step' => 'details'],
            'bank_name' => ['label' => 'Name of bank', 'type' => 'text', 'max' => 80, 'required' => true, 'step' => 'identity', 'bank' => true],
            'bank_account_no' => ['label' => 'Account no.', 'type' => 'text', 'max' => 30, 'required' => true, 'step' => 'identity', 'bank' => true, 'sensitive' => true],
            'identity_number' => ['label' => 'IC no. or passport no.', 'type' => 'text', 'max' => 30, 'required' => true, 'step' => 'identity', 'sensitive' => true],
            'holds_visa' => ['label' => 'Do you hold a student visa?', 'type' => 'yesno', 'required' => true, 'step' => 'identity'],
            'visa_expiry_date' => ['label' => 'Visa expiry date', 'type' => 'date', 'required_if' => 'holds_visa', 'step' => 'identity'],
        ],

        // Name of bank and Account no. -- pending CGS confirming whether
        // they already hold these. When false they are not asked, not
        // validated, not changed, and left blank on the generated form.
        'collect_bank_details' => (bool) env('NUREEN_GA_EXTENSION_COLLECT_BANK_DETAILS', true),

        // Section C: one row per publication.
        'publications' => [
            'max_rows' => 10,
            'types' => ['journal' => 'Journal', 'conference' => 'Conference', 'book' => 'Book'],
            'databases' => ['scopus' => 'Scopus', 'wos' => 'Web of Science', 'era' => 'ERA', 'other' => 'Other'],
            'quartiles' => ['Q1' => 'Q1', 'Q2' => 'Q2', 'Q3' => 'Q3', 'Q4' => 'Q4'],
        ],

        // Section D, word for word from the form.
        'declaration' => 'I hereby certify that the statement and information in the application form are true and correct. '
            .'I also understand and agree that falsification or material omission of information provided herein may result '
            .'in my GA contract to be terminated.',

        // Section J, "Centre for Graduate Studies (CGS) use remarks": filled
        // by CGS staff at the cgs_verify stage, printed on the form.
        'cgs_remarks_fields' => [
            'reporting_duty_date' => ['label' => 'Date of Reporting Duty', 'type' => 'date'],
            'registration_status' => ['label' => 'Current Semester Registration status', 'type' => 'text'],
            'softbound_submission' => ['label' => 'Softbound submission', 'type' => 'text'],
            'rcs_viva_status' => ['label' => 'RCS / VIVA status', 'type' => 'text'],
            'previous_result' => ['label' => 'Result: a. Previous result', 'type' => 'text'],
            'rpd_rm_result' => ['label' => 'Result: b. RPD & RM result', 'type' => 'text'],
            'ga_duration' => ['label' => 'GA Status: a. Duration of GA', 'type' => 'text'],
            'total_ta_hours' => ['label' => 'GA Status: b. Total TA hours', 'type' => 'text'],
        ],

        // The "GA Scheme Terms and Conditions" page of the form, as checks
        // on the approver's System checks panel (Support\GaExtensionRules).
        // They warn; only a backdated start blocks submission ("Backdated
        // appointment ... is not allowed"). CGS can change any number here.
        'rules' => [
            // (iii) Maximum GA duration, counted from the admission date.
            'max_months' => ['msc' => 24, 'phd' => 42],
            // (iii) "at least three (3) months and twelve (12) months".
            'extension_months' => ['min' => 3, 'max' => 12],
            // (iii) Appointments start on the 1st or 16th, end on the 15th
            // or the last day of the month ('last').
            'start_days' => [1, 16],
            'end_days' => [15, 'last'],
            // (iii) "submitted at least one (1) month before".
            'notice_months' => 1,
            // (vii) MSc and (viii) PhD stage gates. 'from'/'to' are the
            // "Timeframe for completion" months. The level is the highest
            // whose 'from' is at or below the months completed since
            // admission at the extension's start: 18 months completed is
            // month 19, so MSc Level 3. 'proof' is the stage_gate_documents
            // field that shows it; null means nothing to attach (RPD).
            'stage_gates' => [
                'msc' => [
                    1 => ['from' => 3, 'to' => 6, 'requirement' => 'RPD completion', 'proof' => null],
                    2 => ['from' => 12, 'to' => 18, 'requirement' => 'One article accepted in a WoS/ERA/Scopus-indexed journal', 'proof' => 'acceptance_proof_documents'],
                    3 => ['from' => 18, 'to' => 24, 'requirement' => 'One article published in a WoS/ERA/Scopus-indexed journal', 'proof' => 'published_article_documents'],
                ],
                'phd' => [
                    1 => ['from' => 6, 'to' => 9, 'requirement' => 'RPD completion', 'proof' => null],
                    2 => ['from' => 18, 'to' => 30, 'requirement' => 'One article accepted in a WoS/ERA or Scopus-indexed journal', 'proof' => 'acceptance_proof_documents'],
                    3 => ['from' => 30, 'to' => 36, 'requirement' => 'One article published in a Q1/Q2 WoS journal, or one accepted and one published (WoS/ERA/Scopus)', 'proof' => 'published_article_documents'],
                    4 => ['from' => 36, 'to' => 42, 'requirement' => 'Soft bound submission for RCS', 'proof' => 'softbound_proof_documents'],
                ],
            ],
        ],

        // Provisional, empty -- the official form's fields are now
        // applicant_fields above; this stays for anything CGS adds later
        // that has no column (stored in GaExtensionDetail::additional_info).
        // Each entry:
        // key => ['label' => ..., 'type' => 'text'|'textarea'|'date', 'required' => bool].
        'additional_fields' => [
            // 'funding_source' => ['label' => 'Funding Source', 'type' => 'text', 'required' => false],
        ],

        // Who gives the final word, confirmed with CGS against their
        // UResearch process workflow (5-day Client Charter: Supervisor 3,
        // CGS 2). See GaExtensionWorkflow::stages() and
        // GaExtensionDetail::final_approver_snapshot -- an in-flight
        // application always runs the chain it was submitted under, never
        // whatever this config currently says.
        //   'cgs'             DEFAULT. Supervisor endorses, Non-Executive CGS
        //                     decides and the application ends there. The
        //                     Senior Director is emailed every outcome for
        //                     information only -- no action, no stage.
        //   'senior_director' The old chain: Supervisor, then CGS Staff
        //                     (an intermediate review), then the Senior
        //                     Director gives the final approval.
        'final_approver' => env('NUREEN_GA_EXTENSION_FINAL_APPROVER', 'cgs'),

    ],

    'supervision' => [

        // How CGS takes control of a Main or Co-Supervisor appointment
        // (0 Client Charter days for the CGS stage: same working day). See
        // SupervisionWorkflow::stages() and SupervisionDetail::
        // cgs_stage_snapshot -- an in-flight application always runs the
        // chain it was submitted under.
        //   'endorse'   DEFAULT. After the Chair of Department approves,
        //               Non-Executive CGS previews the letter (DRAFT) and
        //               endorses it; endorsing assigns the reference,
        //               issues the letter under the endorser's own name and
        //               approves the application. CGS can also reject, with
        //               a reason. An official letter only ever names an
        //               endorser who actually endorsed it.
        //   'automatic' The Chair's approval is final and the letter is
        //               issued at once. It names no CGS endorser: it states
        //               that it was issued by the Centre for Graduate
        //               Studies following approval by the Chair (named from
        //               approval_history). CGS can revoke it afterwards.
        //   'manual'    Non-Executive CGS makes an eligibility approve/
        //               reject decision after the Chair; the letter is
        //               issued on that approval, naming that CGS user.
        'cgs_stage' => env('NUREEN_SUPERVISION_CGS_STAGE', 'endorse'),

        // CGS/SVAPPT/<year>/<sequence>. See Support\AppointmentReferenceGenerator,
        // the same race-safe pattern as CertificateReferenceGenerator.
        'reference_prefix' => 'CGS/SVAPPT',

    ],

    'ga_certification' => [

        // CGS/GACERT/<year>/<sequence>. See Support\CertificateReferenceGenerator.
        // Purely cosmetic -- module_type and Stage::key are what is
        // permanent; this prefix is safe to reword the day the real CGS
        // sample letter arrives.
        'reference_prefix' => 'CGS/GACERT',

        // "Before CGS staff review, the system automatically checks the
        // student's GA/GRA appointment" -- NOT part of CGS's confirmed
        // process, so off by default. See Support\AppointmentVerifier and
        // CertificationController::queue(); when this is false the check
        // is not run and nothing about it is shown anywhere.
        'appointment_check_enabled' => env('NUREEN_GA_CERTIFICATION_APPOINTMENT_CHECK', false),

        // Which fields the form, validation, the approver's detail view
        // and the generated letter show for each certification type -- a
        // GA certifies against a course, a GRA against a research
        // project. key => ['label' => ..., 'type' => 'text', 'required' => bool,
        // 'max' => characters]. Same convention as
        // ga_extension.additional_fields: one list, read everywhere a field
        // needs to appear.
        //
        // 'max' is what keeps the certification letter on ONE A4 page:
        // every one of these is printed on it. CertificationController::
        // store() validates against it, the form sets maxlength and shows a
        // counter from it, and LettersTest renders the letter with every
        // field at exactly its max at once and asserts a single page. Raise
        // one only together with that test.
        'fields' => [
            'GA' => [
                'course_code' => ['label' => 'Course Code', 'type' => 'text', 'required' => true, 'max' => 20],
                'course_name' => ['label' => 'Course Name', 'type' => 'text', 'required' => true, 'max' => 120],
                'course_owner_name' => ['label' => "Course Owner's Name", 'type' => 'text', 'required' => true, 'max' => 80],
                'semester' => ['label' => 'Semester', 'type' => 'text', 'required' => true, 'max' => 30],
            ],
            'GRA' => [
                'project_title' => ['label' => 'Research Project Title', 'type' => 'text', 'required' => true, 'max' => 150],
                'project_leader_name' => ['label' => "Project Leader's Name", 'type' => 'text', 'required' => true, 'max' => 80],
                'semester' => ['label' => 'Semester', 'type' => 'text', 'required' => true, 'max' => 30],
                'grant_code' => ['label' => 'Grant/Project Code', 'type' => 'text', 'required' => false, 'max' => 40],
            ],
        ],

        // Printed on the letter too ("issued at your request for the
        // following purpose: ..."), so limited for the same reason.
        'purpose_max' => 120,

        // The required supporting document's label depends on the type --
        // same upload field, different wording for who has to have signed
        // or approved it.
        'supporting_document_label' => [
            'GA' => "Evidence of Work Done (signed by Course Owner)",
            'GRA' => "Evidence of Work Done (signed by Project Leader)",
        ],

        // CGS's official online form, sections 1-5 (see the migration
        // 2026_11_05_000100). One list per choice, read by the form, the
        // validation, the approver's detail view and the letter, so none
        // can drift. Keys are what is stored; labels are display-only.
        'options' => [
            'study_level' => [
                'coursework' => 'Coursework',
                'research' => 'Research',
            ],
            'level_of_study' => [
                'master' => 'Master',
                'phd' => 'PhD',
            ],
            'mode_of_study' => [
                'full_time' => 'Full-time',
                'full_time_odl' => 'Full-time ODL',
                'part_time' => 'Part-time',
            ],
        ],

        // The online form does not ask for the appointment period; this
        // portal always has. Pending CGS's answer: when false the two date
        // fields are hidden, not validated, and the letter does not print a
        // period. Stored values on older applications are left alone.
        'ask_period' => (bool) env('NUREEN_GA_CERTIFICATION_ASK_PERIOD', true),

        // Section 5 of the online form, shown beside the checkbox and
        // recorded (declaration_accepted_at) when ticked.
        'declaration' => 'I confirm that the information given in this application is true and complete. '
            .'I understand that UTP may reject an application that is false or incomplete.',

        // The supporting document is the course owner's signed attachment,
        // as a PDF only (CGS's online form).
        'supporting_document_types' => ['pdf'],

    ],

    // The Client Charter ('charter_days' below) now decides when a stage's
    // reviewer is reminded. 'stalled_days' is only the FALLBACK, in
    // working days, for a stage whose charter is null -- today that is
    // just GA Extension's 'senior_director' stage in 'senior_director'
    // mode. 'escalate_after_reminders' (Supervision only) is unchanged:
    // after that many reminders at one stage, CGS Staff is looped in. All
    // of it goes through Support\ClientCharter, so an "overdue"
    // application on the analytics page is exactly one that would trigger
    // a reminder.
    'reminders' => [
        'supervision' => [
            'stalled_days' => 3,
            'escalate_after_reminders' => 2,
        ],
        'ga_extension' => [
            'stalled_days' => 3,
        ],
        'ga_certification' => [
            'stalled_days' => 3,
        ],
    ],

    // Official Client Charter working days per module and stage, as given
    // in CGS's UResearch process workflow diagrams. Read by
    // Support\ClientCharter, which is the one place that turns these into
    // stage due dates, the student's expected decision date, the reminder
    // threshold and the compliance report -- see 'charter' below for the
    // calendar they are counted on. Keyed by the real Stage::key so there
    // is no separate vocabulary to keep in sync with the workflow classes.
    // A stage only used in one config mode (ga_extension.senior_director,
    // supervision.cgs_review) is listed anyway, so the figure is on record
    // even while that mode is not the default. null means CGS has given no
    // charter figure for that stage: it gets no due date and no expected
    // decision date (nothing is invented), and its reminders fall back to
    // 'reminders.<module>.stalled_days' above. 0 means "same day" and adds
    // nothing to an expected decision date.
    'charter_days' => [
        'ga_extension' => [
            'supervisor' => 3,
            'cgs_verify' => 2,
            'senior_director' => null,
        ],
        'supervision' => [
            'supervisor' => 3,
            'chair' => 3,
            'cgs_review' => 0,
        ],
        'ga_certification' => [
            'supervisor' => 3,
            'cgs_verify' => 2,
            'senior_director' => 2,
        ],
    ],

    // The shared letter layout (Resources/views/letters/layout.blade.php)
    // both the Supervision appointment letter and the GA/GRA certification
    // letter extend. Everything CGS may want to change without touching
    // code lives here. See Support\LetterRenderer.
    'letters' => [

        'institution' => 'Universiti Teknologi PETRONAS',
        'centre' => 'Centre for Graduate Studies (CGS)',
        'address' => '32610 Seri Iskandar, Perak Darul Ridzuan, Malaysia',

        // PLACEHOLDERS until CGS confirms its official contact details.
        'phone' => env('NUREEN_LETTER_PHONE', '+60 5-xxx xxxx'),
        'email' => env('NUREEN_LETTER_EMAIL', 'cgs@utp.edu.my'),
        'website' => env('NUREEN_LETTER_WEBSITE', 'www.utp.edu.my'),

        // A local image file. If it is missing or unreadable the letterhead
        // falls back to text only; it is never fetched from the internet.
        // Downscaled in memory before embedding (see LetterRenderer::logo()),
        // so a large source file does not bloat every letter.
        'logo_path' => env('NUREEN_LETTER_LOGO', public_path('images/UTP_logo.png')),

        // The one accent colour: the rule under the letterhead.
        'accent' => '#1F3A68',

        // The title printed under a signatory's name, by role. The line
        // below it always reads "Centre for Graduate Studies". Provisional
        // until CGS confirms the wording for each post.
        'signatory_titles' => [
            'non_exec_cgs' => 'Non-Executive',
            'senior_director_cgs' => 'Senior Director',
        ],

    ],

    'charter' => [

        // Dates (Y-m-d) that are not working days even though they fall on
        // a weekday. Empty by default. CGS can list Malaysian national and
        // Perak state public holidays here (e.g. '2026-12-25'); every due
        // date, expected decision date, reminder and compliance figure is
        // counted on Support\WorkingDayCalendar, which reads this one list.
        'holidays' => [],

    ],

];
