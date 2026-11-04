<?php

/**
 * Each team member's modules have one Non-Executive CGS officer of their own.
 * Travel, Claims and Publication are Norshahira's: at the CGS stage, the
 * "awaiting your action" email goes to her alone. Every CGS officer can
 * still see and act on them, in the queue, the lists and the dashboard.
 *
 * Read through Support\CgsOfficer. If no account has this address (a
 * teammate who has not loaded database/sql/cgs_officers.sql yet, or the test
 * database), every Non-Executive CGS is emailed instead.
 */
return [
    'officer' => env('NORHANIS_CGS_OFFICER', 'norshahira.cgs@utp.edu.my'),
];
