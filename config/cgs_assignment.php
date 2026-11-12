<?php

/*
|--------------------------------------------------------------------------
| Which Non-Executive CGS officer decides each module's CGS stage
|--------------------------------------------------------------------------
|
| CGS confirmed that each officer handles specific modules (agreed with the
| team, including Hani as Integration & Git Lead, 2026-11-12). Read only
| through App\Modules\Core\Services\CgsAssignment -- nothing else looks here.
|
| Per module key (applications.module_type), one of:
|   ['officer' => email]                   only that officer may decide
|   ['officer' => email, 'backup' => email] the backup may also decide once
|                                           the application is escalated
|   '*'                                    any Non-Executive CGS may decide
|
| A module that is NOT listed here behaves as before this map existed: any
| Non-Executive CGS may decide. So a module added later breaks nothing.
| Likewise, an officer address with no Non-Executive CGS account behind it
| (a fresh checkout, the test database) falls back to any CGS rather than
| locking everybody out.
|
| What it restricts: deciding (approve, reject, return, endorse/issue a
| letter, revoke a letter) at a stage whose role is Non-Executive CGS.
| Viewing is never restricted. Other roles' stages are untouched.
|
| Team decisions, 2026-11-12: Haziq's modules are '*' (open to every
| Non-Executive CGS officer; Akmar's role does not change), and the map does
| not cover Senior Executive CGS stages at all, so Hani's modules (whose CGS
| desks are all Senior Executive CGS) are not listed.
|
*/

return [

    'modules' => [
        // Norhanis
        'travel' => ['officer' => 'norshahira.cgs@utp.edu.my'],
        'publication' => ['officer' => 'norshahira.cgs@utp.edu.my'],
        'claims_student' => ['officer' => 'norshahira.cgs@utp.edu.my'],

        // Nureen
        'ga_extension' => ['officer' => 'zulkifly.cgs@utp.edu.my'],
        'supervision' => ['officer' => 'zulkifly.cgs@utp.edu.my'],
        'ga_certification' => ['officer' => 'zulkifly.cgs@utp.edu.my'],
        'attendance_appeal' => ['officer' => 'zulkifly.cgs@utp.edu.my'],

        // Jason -- both Hardbound keys stay open to every CGS officer.
        'hardbound_submission' => '*',
        'hardbound_appeal' => '*',
        'appointment_letter' => ['officer' => 'syahmi.cgs@utp.edu.my'],

        // Chloe. Workstation, candidacy_reminder and candidacy_dismissal
        // are not workflows (no stages, nothing to decide), so not listed.
        'candidacy_appeal' => ['officer' => 'amirul.cgs@utp.edu.my'],

        // Haziq -- open to every Non-Executive CGS officer: team decision,
        // 2026-11-12 (Akmar stays Senior Executive CGS). Covers everything
        // in his folder: Stage Gates and Allowance have no keys of their
        // own and are checked against these two.
        'gra_application' => '*',
        'ga_application' => '*',

        // Hani -- not listed: team decision, 2026-11-12, the map does not
        // cover Senior Executive CGS stages, and none of examiner_nomination,
        // re_viva, viva_voce or viva_softbound has a Non-Executive CGS stage.
    ],

    /*
    | Absence cover. An application is ESCALATED at its CGS stage once it is
    | overdue AND has had this many reminders there. Then the backup (or, with
    | no backup set, any Non-Executive CGS) may decide it too.
    |
    | "Overdue" is the module's own Client Charter answer where it gives one
    | (Core\Contracts\SuppliesOverdueStatus); otherwise the application has
    | sat on its current stage for overdue_after_days calendar days -- the
    | same 14 days at which the queue marks a row overdue.
    |
    | The reminder count comes from Core\Contracts\SuppliesReminderCount. A
    | module that does not implement it sends no counted reminders, so for it
    | being overdue is enough.
    */
    'escalate_after_reminders' => (int) env('CGS_ESCALATE_AFTER_REMINDERS', 2),

    'overdue_after_days' => (int) env('CGS_OVERDUE_AFTER_DAYS', 14),

];
