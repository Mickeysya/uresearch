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

    ],

    'ga_certification' => [

        // CGS/GACERT/<year>/<sequence>. See Support\CertificateReferenceGenerator.
        // Purely cosmetic -- module_type and Stage::key are what is
        // permanent; this prefix is safe to reword the day the real CGS
        // sample letter arrives.
        'reference_prefix' => 'CGS/GACERT',

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

];
