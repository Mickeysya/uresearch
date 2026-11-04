<?php

return [

    /*
    | Track My Applications: the tabs.
    |
    | A module puts itself in a tab with ONE line on its workflow class:
    |
    |     public const CATEGORY = 'assistantships';
    |
    | (and, optionally, an icon from core::dashboard.partials.icon:
    |  public const ICON = 'certificate';)
    |
    | This file only gives each category KEY its display label, a fixed tone
    | (the same colour for every student, whichever tabs they happen to see)
    | and a default icon. Rename a tab by changing its label here -- no
    | module changes. A module naming a key that is not listed still gets its
    | own tab, labelled from the key, in the neutral tone; a module with no
    | CATEGORY goes under 'other'. Nothing here lists modules.
    |
    | Tones: blue, green, amber, purple, teal, neutral (tracking.css).
    */
    'categories' => [
        'assistantships' => ['label' => 'Assistantships', 'tone' => 'blue', 'icon' => 'certificate'],
        'supervision' => ['label' => 'Supervision & Examination', 'tone' => 'purple', 'icon' => 'person'],
        'candidacy' => ['label' => 'Candidacy & Progress', 'tone' => 'amber', 'icon' => 'clock'],
        'thesis' => ['label' => 'Thesis Submission', 'tone' => 'teal', 'icon' => 'book'],
        'travel-funding' => ['label' => 'Travel & Funding', 'tone' => 'green', 'icon' => 'folder'],
        'other' => ['label' => 'Other', 'tone' => 'neutral', 'icon' => 'doc'],
    ],

    /*
    | The "Need help?" box on a student's application details page.
    | PLACEHOLDERS until CGS confirms its official contact details.
    */
    'help' => [
        'name' => env('TRACKING_HELP_NAME', 'Centre for Graduate Studies (CGS)'),
        'email' => env('TRACKING_HELP_EMAIL', 'cgs@utp.edu.my'),
        'phone' => env('TRACKING_HELP_PHONE', '+60 5-xxx xxxx'),
        'hours' => env('TRACKING_HELP_HOURS', 'Monday to Friday, 8:30am to 5:00pm'),
    ],

];
