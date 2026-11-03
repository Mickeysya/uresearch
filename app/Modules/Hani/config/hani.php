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
