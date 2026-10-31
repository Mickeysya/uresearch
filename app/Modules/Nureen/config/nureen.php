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

    ],

    'ga_extension' => [

        // field name => the label documents under it are attached with.
        // GaExtensionController::store() reads this to build validation
        // rules and attach each type; ga_extension/_detail.blade.php reads
        // the same list for the System checks panel, so a required type
        // cannot drift between what is enforced and what is shown.
        'required_documents' => [
            'ga_extension_form_documents' => 'GA Extension Form',
            'identity_document_documents' => 'IC or Passport/Visa Copy',
            'supervisor_letter_documents' => "Supervisor's Supporting Letter",
            'acceptance_or_paper_documents' => 'Acceptance Letter or Published Paper',
        ],

        // Provisional, empty until the real CGS online form is confirmed --
        // see GaExtensionDetail::additional_info. Each entry:
        // key => ['label' => ..., 'type' => 'text'|'textarea'|'date', 'required' => bool].
        // Adding a field later is one entry here; the form, the validation
        // and the detail panel all read this same list, so nothing else
        // needs to change.
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
