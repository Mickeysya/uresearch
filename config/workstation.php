<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Working-day holidays
    |--------------------------------------------------------------------------
    |
    | Owned by the Workstation module (app/Modules/Chloe). Used by the
    | termination flow's 5-working-day vacate-by calculation (Postgraduate
    | Workstation Term of Use, clause 9/16) -- Monday to Friday is always
    | skipped; a date listed here is skipped too. 'YYYY-MM-DD' strings.
    | Empty by default -- add UTP's public/semester holidays as needed.
    */
    'holidays' => [
        // '2026-01-01',
    ],

];
