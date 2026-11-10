<?php

/*
| Hani's module settings: Examiner Matching & Pool, Viva Voce, Re-viva.
|
| Loaded by Hani\ModuleProvider::register() as config('hani.*'), the same way
| Nureen's module loads config('nureen.*'). Every value CGS has not confirmed
| is marked "Provisional": it is a reasonable default for the FYP, not a rule
| anyone at CGS has signed off, and it lives here so that changing it never
| means editing code.
*/

return [

    'appointments' => [

        // CONFIRMED by CGS: appointment letters go to the two MAIN examiners
        // only, so release ties up the two mains and the backups stay
        // Available on standby. true would send letters to, and tie up, all
        // four seats.
        'letters_to_backups' => false,

        // How long release ties an examiner up, absent a completed
        // evaluation (Examiner::DEFAULT_ASSIGNMENT_DAYS). Confirmed: 180.
        'assignment_days' => 180,
    ],

    'departments' => [

        // Names that appear in CGS's spreadsheets (and older accounts) mapped
        // to the canonical name on Core's departments list. Keys are compared
        // after Support\Departments::normalise() -- lower case, "&" read as
        // "and", punctuation and repeated spaces dropped -- so one entry
        // covers "Computer & Information Sciences" and "Computer and
        // Information Sciences" alike.
        //
        // Provisional: mirrors DatabaseSeeder::RENAMED_DEPARTMENTS as of
        // 2026-11-03. CGS has not confirmed how their sheet names map.
        'aliases' => [
            'Computer & Information Science' => 'Computing',
            'Computer & Information Sciences' => 'Computing',
            'Information Technology' => 'Computing',
            'Electrical & Electronic Engineering' => 'Electrical & Electronics Engineering',
            'Civil Engineering' => 'Civil & Environmental Engineering',
            'Applied Science' => 'Applied Sciences',
            'Fundamental & Applied Science' => 'Applied Sciences',
            'Geoscience' => 'Geosciences',
            'Petroleum Geoscience' => 'Geosciences',
            'Management & Humanities' => 'Management',
            // In CGS's internal sheet, not in the seeder's list.
            'Fundamental & Applied Sciences' => 'Applied Sciences',
        ],
    ],

    'names' => [

        // Stripped before two names are compared (Support\NameNormaliser).
        // Compared as whole words after punctuation is removed, so "Assoc.
        // Prof." and "Assoc Prof" are the same. "tan" is deliberately NOT a
        // title on its own -- it is a common surname; "Tan Sri" is handled as
        // a phrase. Provisional: extend as CGS's sheets show more forms.
        'titles' => [
            'prof', 'professor', 'assoc', 'associate', 'asst', 'assistant', 'madya',
            'ap', 'dr', 'ir', 'ts', 'ar', 'sr', 'emeritus',
            'datuk', 'dato', 'datin', 'datuk seri', 'dato sri', 'tan sri', 'puan sri', 'toh puan',
            'haji', 'hj', 'hajah', 'hjh',
            'phd', 'ph d', 'dphil', 'mr', 'mrs', 'ms', 'miss', 'puan', 'pn', 'encik', 'en', 'cik',
        ],

        // Patronymic connectors: "bin", "binti", "a/l", "a/p". Dropped so
        // "Hassan bin Omar" and "Hassan Omar" compare equal.
        'connectors' => ['bin', 'binti', 'bte', 'bt', 'binte', 'al', 'ap', 'so', 'do'],
    ],

    'duplicates' => [
        // Two names at or above this similarity (0-100) are flagged as a
        // possible duplicate. Flagged, never blocked. Provisional.
        'threshold' => 85,
    ],

    'coi' => [
        // An external examiner who left UTP (staff or student) less than
        // this many years ago is flagged. From the UTP/CGS/026 declaration as
        // described to us; provisional until CGS confirms the figure.
        'former_utp_years' => 5,
    ],

    'matcher' => [

        // How many "Suggested examiners" per seat type.
        'suggestions' => 5,

        // How many matched terms each suggestion names.
        'explain_terms' => 4,

        // A two-word phrase ("anomaly detection") counts this much more than
        // a single word. Provisional.
        'phrase_boost' => 1.5,

        // Ordering. Expertise similarity (0-100) dominates; the rest break
        // near-ties towards spreading the load across the pool. Provisional:
        // CGS has not said how they weigh these today.
        'weights' => [
            'similarity' => 1.0,
            'recent_examination' => 3.0,     // points off per main-seat examination in the last 12 months
            'per_year_since_last' => 1.5,    // points per year since the last examination...
            'gap_years_cap' => 3,            // ...up to this many years
            'per_year_experience' => 0.2,    // external only: points per year of experience...
            'experience_cap' => 25,          // ...up to this many years
        ],
    ],

    'eligibility' => [
        // Items the system checks itself instead of taking the student's
        // tick (Support\Eligibility\EligibilitySource). The rest stay
        // self-declared: RCS results, registration and fees are stored nowhere
        // in UResearch.
        'sources' => [
            \App\Modules\Hani\Support\Eligibility\CandidacyPeriodSource::class,
        ],

        // Maximum candidacy, in months from the candidature start date, shown
        // on the checklist. The system check itself uses the expiry date in
        // Study Candidacy (CandidacyPeriodSource). Provisional: from the
        // Handbook as described to us, not re-confirmed with CGS.
        'candidacy_months' => [
            'MSC' => ['full_time' => 48, 'part_time' => 72],
            'PHD' => ['full_time' => 96, 'part_time' => 120],
        ],
    ],

    'notice' => [
        // The abstract and keywords on the Notice of Thesis Submission, which
        // feed the examiner suggestions. Provisional: our own bounds, not a
        // Handbook figure.
        'abstract_min_words' => 100,
        'abstract_max_words' => 500,
        'keywords_min' => 3,
        'keywords_max' => 8,
    ],

    'similarity' => [
        // Where the similarity figures come from (Support\Similarity):
        // uploaded (read the student's Turnitin report PDF) | turnitin_api
        // (stub, needs a UTP licence) | simulated (demo figures, labelled).
        'source' => 'uploaded',

        // Where students get the report. CONFIRMED: students download it
        // from ULearn. The address itself is provisional.
        'ulearn_url' => 'https://ulearn.utp.edu.my',

        // Flag any single primary source above this percentage. Provisional.
        'single_source_warn' => 5,

        // Flag a declared figure this many percentage points away from the
        // one read from the report. Provisional.
        'mismatch_points' => 1.0,

        // Flag a report older than this, when a Digital Receipt gives its
        // date. Provisional.
        'max_report_age_days' => 30,

        // TurnitinApiSource, once UTP licenses it. Keys proposed for
        // .env.example in SHARED_CHANGES.md.
        'turnitin_api' => [
            'key' => env('TURNITIN_API_KEY'),
            'url' => env('TURNITIN_API_URL'),
        ],
    ],

    'softbound_checks' => [
        // The PDF's word count is approximate (front matter, references and
        // tables count too), so only a gap bigger than this share of the
        // declared count is flagged. Provisional.
        'word_tolerance' => 0.15,

        // How much of the notice title's words must appear on the thesis's
        // first pages (or in the report title). Provisional.
        'title_overlap' => 0.8,

        // How many opening pages are searched for the title.
        'title_pages' => 2,
    ],

    'examiner_progress' => [
        // Chase the AE when a letter went out this many days ago and the
        // examiner has neither accepted nor rejected. Provisional.
        'ack_chase_days' => 14,

        // The examiner's report is due this many weeks after they
        // acknowledge receipt of the THESIS. CONFIRMED (4 Master / 6 PhD).
        'report_weeks' => ['MSC' => 4, 'PHD' => 6],

        // Optional 1-5 ratings on the examiner's report form. Provisional
        // wording: CGS's form has 8 general criteria and 12 thesis sections;
        // these names are our paraphrase until we have the form itself.
        'criteria' => [
            'originality' => 'Originality',
            'contribution' => 'Contribution to knowledge',
            'literature' => 'Grasp of the literature',
            'methodology' => 'Soundness of the methodology',
            'analysis' => 'Analysis and interpretation',
            'presentation' => 'Organisation and presentation',
            'language' => 'Language and style',
            'publication' => 'Publication potential',
        ],
        'sections' => [
            'title' => 'Title', 'abstract' => 'Abstract', 'introduction' => 'Introduction',
            'literature_review' => 'Literature review', 'methodology' => 'Methodology',
            'results' => 'Results', 'discussion' => 'Discussion', 'conclusion' => 'Conclusion',
            'recommendations' => 'Recommendations', 'references' => 'References',
            'appendices' => 'Appendices', 'overall' => 'Overall presentation',
        ],
    ],

    'scheduling' => [
        // Two vivas closer than this (in hours) that share an examiner, the
        // chairman, the supervisor or the venue are a clash. Matches the
        // 2-hour calendar event. Provisional.
        'clash_hours' => 2,

        // Start times offered as free slots, and how far ahead to look.
        // Provisional.
        'slot_times' => ['09:00', '14:00'],
        'suggestions' => 3,
        'lookahead_days' => 60,

        // Malaysian public holidays (federal, plus Perak, where UTP is).
        // PROVISIONAL: Islamic, Chinese and Hindu dates follow the published
        // calendars as we understand them and can move by a day; check them
        // against the official gazette each year.
        'public_holidays' => [
            '2026-01-01' => 'New Year\'s Day',
            '2026-02-01' => 'Thaipusam',
            '2026-02-17' => 'Chinese New Year',
            '2026-02-18' => 'Chinese New Year (second day)',
            '2026-03-07' => 'Nuzul Al-Quran',
            '2026-03-21' => 'Hari Raya Aidilfitri',
            '2026-03-22' => 'Hari Raya Aidilfitri (second day)',
            '2026-05-01' => 'Labour Day',
            '2026-05-27' => 'Hari Raya Haji',
            '2026-05-31' => 'Wesak Day',
            '2026-06-01' => 'Birthday of the Yang di-Pertuan Agong',
            '2026-06-17' => 'Awal Muharram',
            '2026-08-26' => 'Maulidur Rasul',
            '2026-08-31' => 'National Day',
            '2026-09-16' => 'Malaysia Day',
            '2026-11-06' => 'Birthday of the Sultan of Perak',
            '2026-11-08' => 'Deepavali',
            '2026-12-25' => 'Christmas Day',
            '2027-01-01' => 'New Year\'s Day',
            '2027-01-22' => 'Thaipusam',
            '2027-02-06' => 'Chinese New Year',
            '2027-02-07' => 'Chinese New Year (second day)',
            '2027-02-24' => 'Nuzul Al-Quran',
            '2027-03-10' => 'Hari Raya Aidilfitri',
            '2027-03-11' => 'Hari Raya Aidilfitri (second day)',
            '2027-05-01' => 'Labour Day',
            '2027-05-17' => 'Hari Raya Haji',
            '2027-05-20' => 'Wesak Day',
            '2027-06-06' => 'Awal Muharram',
            '2027-06-07' => 'Birthday of the Yang di-Pertuan Agong',
            '2027-08-15' => 'Maulidur Rasul',
            '2027-08-31' => 'National Day',
            '2027-09-16' => 'Malaysia Day',
            '2027-10-28' => 'Deepavali',
            '2027-11-05' => 'Birthday of the Sultan of Perak',
            '2027-12-25' => 'Christmas Day',
        ],
    ],

    'result' => [
        // Months from the viva date to the corrected final thesis, by
        // result category. Provisional (from the AE's guideline as described).
        'correction_months' => [1 => 1, 2 => 3, 3 => 6],

        // Category 4: the re-examination window, in months from the viva.
        // Provisional.
        'resubmission_opens_months' => 6,
        'resubmission_closes_months' => 12,

        // Remind the student this many days before the corrections are due.
        // Provisional.
        'reminder_days' => [30, 7],
    ],

    'reviva' => [
        // Round N+1 (after a level-4 outcome): the corrected thesis is due
        // between these many months after the outcome date. Provisional
        // (agreed for the FYP 2026-11-04, not a CGS figure).
        'next_round_window_months' => [0, 6],

        // Months from the re-viva outcome date to the final thesis, by level
        // (UTP/CGS/024: within 1 month, up to 3, 3 to 6). Provisional.
        'final_deadline_months' => [1 => 1, 2 => 3, 3 => 6],

        // Logging a re-viva keeps the two main examiners Assigned until at
        // most this many months later; the outcome releases them sooner.
        // Provisional (the original 12-month hardbound horizon).
        'hold_months' => 12,

        // CONFIRMED (the real letter): "examiners are given a maximum of
        // twenty (20) working days". The letter prints the date this falls on.
        'panel_working_days' => 20,

        // Senior Executive's document check (client charter 2 to 5 days): one reminder after this many working days.
        'doc_check_sla_days' => 5,

        // Examiner reminders, in working days before the deadline. Senior
        // Executives can override this on Re-viva Settings (re_viva_settings).
        'external_reminders' => [10, 3],

        // Over this total, an examiner's email carries the letter and the
        // report form, and a signed link for the student's documents.
        'attachment_limit_mb' => 20,

        // PDF size limit for the student's three uploads and examiner returns. Provisional.
        'upload_max_mb' => 20,

        // Re-viva Cycles' deadline risk score (Support\ReVivaRisk), 0-100.
        // Rule-based and explained factor by factor. ALL PROVISIONAL: our
        // weights and limits for the FYP, not CGS figures.
        'risk' => [
            'weights' => ['time_used' => 35, 'stage_time' => 30, 'divergence' => 20, 'previous_rounds' => 15],
            // Working days a round is expected to take, logging to outcome.
            'expected_round_working_days' => 45,
            // Working days each stage should take; 20 is the panel's limit.
            'stage_limits' => [
                'student_submitted' => 5, 'pack_preparation' => 5, 'dean_approval' => 5, 'ready_to_send' => 3,
                'under_panel_review' => 20, 'reports_received' => 5, 'cgs_review' => 5, 'meeting_scheduled' => 10, 'outcome' => 5,
            ],
            // This many earlier rounds count the full "previous rounds" weight.
            'rounds_for_full' => 2,
            // Bands.
            'high' => 70,
            'medium' => 40,
        ],

        // When `re-viva:monitor` runs each day. Provisional.
        'monitor_daily_at' => '08:00',

        // How long the examiner's signed link to the corrected thesis and the
        // Summary of Corrections works. Provisional: the 20 working days plus
        // slack for a late report.
        'signed_link_days' => 35,

        // The examiner's sign-in to that link: how long the emailed code
        // works, and how long the sign-in lasts in that browser.
        'panel_code_minutes' => 10,
        'panel_session_minutes' => 60,

        // The "Correction of Master's/Doctoral Thesis" letter. Phone numbers
        // and emails are PROVISIONAL demo values; correct them before go-live.
        'letter' => [
            'reference_prefix' => 'UTP/PGS/AD/',
            'institution' => 'Universiti Teknologi PETRONAS',
            'address' => ['Bandar Seri Iskandar', '31750 Tronoh Perak', 'Darul Ridzuan'],
        ],
        'signatory' => ['name' => 'Prof. Ts. Dr. M Fadzil Hassan', 'title' => ['Dean,', 'Postgraduate & Research']],
        'return_contacts' => [
            ['name' => 'Waheda Rahayu Bt Rashid', 'role' => 'Senior Executive CGS', 'email' => 'waheda.cgs@utp.edu.my', 'tel' => '+605 000 0001', 'mobile' => '+6012 000 0001'],
            ['name' => 'M Syahmi Ifwat M Jafri', 'role' => 'Non-Executive CGS', 'email' => 'syahmi.cgs@utp.edu.my', 'tel' => '+605 000 0002', 'mobile' => '+6012 000 0002'],
        ],

        // Programme Learning Outcomes on UTP/CGS/024. PLACEHOLDER wording: correct it here.
        'plo_texts' => [
            'Engineering' => [1 => 'Apply advanced engineering knowledge.', 2 => 'Investigate complex engineering problems.', 6 => 'Communicate research effectively.', 7 => 'Uphold professional and ethical practice.'],
            'Science' => [1 => 'Apply advanced scientific knowledge.', 2 => 'Investigate complex scientific problems.', 6 => 'Communicate research effectively.', 7 => 'Uphold professional and ethical practice.'],
            'Computing' => [1 => 'Apply advanced computing knowledge.', 2 => 'Investigate complex computing problems.', 3 => 'Design and evaluate computing solutions.', 6 => 'Uphold professional and ethical practice.'],
            'Business' => [2 => 'Apply appropriate research methods.', 4 => 'Analyse and interpret findings.', 5 => 'Contribute to practice and scholarship.', 6 => 'Demonstrate advanced business knowledge.'],
        ],
    ],

    'reminders' => [
        // When `viva:reminders` runs each day. Provisional.
        'daily_at' => '07:45',

        // Panel reminders (with the calendar file) this many days before
        // the viva. Provisional.
        'panel_days' => [7, 1],

        // Nudge the student once when the expected softbound date is this
        // close and nothing is filed. Provisional.
        'softbound_nudge_days' => 14,
    ],

    'sla' => [
        // When `hani:sla-reminders` runs each day. Provisional.
        'daily_at' => '08:15',

        // How long an application may wait at each stage before its
        // approver is reminded (first figure) and reminded again with the
        // Senior Executive CGS copied (second). Counted from the decision
        // that brought it to the stage. working_days: Monday to Friday less
        // hani.scheduling.public_holidays. All Provisional: CGS has not set
        // service levels for these stages.
        'stages' => [
            'examiner_nomination' => [
                'academic_exec' => ['days' => [5, 10], 'working_days' => true],
                'cgs_compile' => ['days' => [5, 10], 'working_days' => true],
                'senior_director' => ['days' => [5, 10], 'working_days' => true],
                'dean' => ['days' => [5, 10], 'working_days' => true],
                'cgs_final' => ['days' => [3, 6], 'working_days' => true],
                'cgs_release' => ['days' => [3, 6], 'working_days' => true],
            ],
            'viva_voce' => [
                'notice_received' => ['days' => [5, 10], 'working_days' => true],
            ],
            'viva_softbound' => [
                'softbound_check' => ['days' => [5, 10], 'working_days' => true],
            ],
            're_viva' => [
                'pack_preparation' => ['days' => [5, 10], 'working_days' => true],
                'dean_approval' => ['days' => [5, 10], 'working_days' => true],
                'ready_to_send' => ['days' => [3, 5], 'working_days' => true],
                'reports_received' => ['days' => [5, 10], 'working_days' => true],
                'cgs_review' => ['days' => [5, 10], 'working_days' => true],
                'meeting_scheduled' => ['days' => [10, 15], 'working_days' => true],
            ],
        ],

        // Stages another command already chases, with the reason. Never
        // reminded by `hani:sla-reminders`, and left out of "overdue now".
        'skip' => [
            're_viva.student_submitted' => 're-viva:monitor reminds the Senior Executive (hani.reviva.doc_check_sla_days).',
            're_viva.under_panel_review' => 're-viva:monitor reminds the examiners and reports overdue panels to the Senior Executive.',
        ],
    ],

    'sweep' => [
        // When `examiners:sweep` runs each day. Provisional.
        'daily_at' => '07:30',

        // After the viva, remind the Academic Executive to mark the
        // evaluation complete, then again every this many days while it stays
        // open. Provisional.
        'remind_every_days' => 7,
    ],

    'dashboard' => [
        // "Returning to Available" looks this many days ahead.
        'returning_days' => 30,

        // Expertise gaps: how many thesis topics to show, and fewer than how
        // many examiners covering a topic counts as a gap. Provisional.
        'gap_topics' => 8,
        'gap_threshold' => 2,
    ],

    'analytics' => [
        // Process Analytics period filter, in months, and the default.
        'periods' => [3, 6, 12],
        'default_period' => 12,
    ],

    'examiner_contact' => [
        // An email nobody has added, changed or confirmed for this many
        // months is listed as "not confirmed" in the pool and on the
        // dashboard. A prompt to check it, not a block. Provisional.
        'stale_months' => 12,
    ],

    'cgs' => [
        // Who an Academic Executive emails to add or remove an examiner,
        // listed at the top of their read-only Examiner Pool.
        'contacts' => [
            ['name' => 'Encik Syahmi', 'role' => 'Non-Executive CGS', 'email' => 'syahmi.cgs@utp.edu.my'],
            ['name' => 'Puan Waheeda', 'role' => 'Senior Executive CGS', 'email' => 'waheda.cgs@utp.edu.my'],
        ],
    ],

    'import' => [
        // Largest pool file accepted, in KB. Provisional.
        'max_kb' => 5120,

        // An uploaded file is parsed into a preview and kept only as rows in
        // examiner_import_batches until it is confirmed. Previews nobody
        // confirmed are purged after this many hours, on the next upload and
        // by `examiners:sweep`. Provisional.
        'batch_ttl_hours' => 24,
    ],

];
