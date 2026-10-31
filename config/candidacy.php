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
];
