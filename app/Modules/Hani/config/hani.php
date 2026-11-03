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
        ],
    ],

];
