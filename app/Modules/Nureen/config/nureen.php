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

        // How CGS takes control of a Main or Co-Supervisor appointment,
        // confirmed against CGS's UResearch process workflow (0 Client
        // Charter days for this stage). See SupervisionWorkflow::stages()
        // and SupervisionDetail::cgs_stage_snapshot -- an in-flight
        // application always runs the chain it was submitted under.
        //   'automatic' DEFAULT. Chair of Department approves and the
        //               system immediately issues the appointment letter --
        //               no separate CGS decision stage. CGS is notified of
        //               every issued letter and can revoke it afterwards,
        //               with a mandatory reason.
        //   'manual'    The cgs_review stage still exists after the Chair:
        //               Non-Executive CGS makes its own approve/reject
        //               decision, and the letter is generated only then.
        'cgs_stage' => env('NUREEN_SUPERVISION_CGS_STAGE', 'automatic'),

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
        // project. key => ['label' => ..., 'type' => 'text', 'required' => bool].
        // Same convention as ga_extension.additional_fields: one list,
        // read everywhere a field needs to appear.
        'fields' => [
            'GA' => [
                'course_code' => ['label' => 'Course Code', 'type' => 'text', 'required' => true],
                'course_name' => ['label' => 'Course Name', 'type' => 'text', 'required' => true],
                'course_owner_name' => ['label' => "Course Owner's Name", 'type' => 'text', 'required' => true],
                'semester' => ['label' => 'Semester', 'type' => 'text', 'required' => true],
            ],
            'GRA' => [
                'project_title' => ['label' => 'Research Project Title', 'type' => 'text', 'required' => true],
                'project_leader_name' => ['label' => "Project Leader's Name", 'type' => 'text', 'required' => true],
                'semester' => ['label' => 'Semester', 'type' => 'text', 'required' => true],
                'grant_code' => ['label' => 'Grant/Project Code', 'type' => 'text', 'required' => false],
            ],
        ],

        // The required supporting document's label depends on the type --
        // same upload field, different wording for who has to have signed
        // or approved it.
        'supporting_document_label' => [
            'GA' => "Evidence of Work Done (signed by Course Owner)",
            'GRA' => "Evidence of Work Done (signed by Project Leader)",
        ],

    ],

    // How many days a pending application may sit at one stage with no
    // decision before the current approver is reminded, and (Supervision
    // only) after how many reminders CGS Staff is looped in. Read by
    // Support\StalledApplicationReminder, the three remind-stalled
    // commands, and Support\ProcessingTimeAnalytics (an "overdue"
    // application on the analytics page is exactly one that would trigger
    // a reminder -- one number per module, not two to keep in sync).
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
    // in CGS's UResearch process workflow diagrams. Not read by anything
    // yet -- reminders and analytics keep using 'reminders' above until a
    // later task wires this in. Keyed by the real Stage::key so there is
    // no separate vocabulary to keep in sync with the workflow classes.
    // A stage only used in one config mode (ga_extension.senior_director,
    // supervision.cgs_review) is listed anyway, so the figure is on record
    // even while that mode is not the default; null means "not timed" for
    // a mode where the stage is entirely skipped.
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

];
