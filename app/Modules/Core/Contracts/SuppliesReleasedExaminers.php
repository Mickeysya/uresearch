<?php

namespace App\Modules\Core\Contracts;

/**
 * Released examiner panels, for the appointment letters. Implemented by
 * whichever module owns examiner nomination (Hani); read by whichever sends
 * the letters (Jason). Bound only if the implementing module is installed.
 *
 * A panel:
 *   [
 *     'nomination_id'  => int,
 *     'application_id' => int,                    // the "Application No."
 *     'released_at'    => \DateTimeInterface,
 *     'student'    => ['id', 'name', 'matric_id', 'programme', 'degree', 'department'],
 *     'supervisor' => ['name', 'email'],
 *     'thesis_title'   => string,
 *     'examiners' => [
 *       'internal_main'   => ['id', 'name', 'email', 'email_problem', 'department', 'faculty'],
 *       'internal_backup' => ['id', 'name', 'email', 'email_problem', 'department', 'faculty'] | null,
 *       'external_main'   => ['id', 'name', 'email', 'email_problem', 'institution', 'department'],
 *       'external_backup' => ['id', 'name', 'email', 'email_problem', 'institution', 'department'] | null,
 *     ],
 *   ]
 *
 * Each seat's 'id' is the examiner's examiners.id. Keep it with the letter
 * you send: with 'application_id' it is the key SuppliesAppointmentDispatch
 * is asked with, and it tells a promoted backup apart from an examiner who
 * already has a letter.
 *
 * Always the CURRENT panel: after a decline, the promoted backup is in the
 * main seat. An examiner's email may be null (CGS has none yet, or the one
 * on record was reported not working); 'email_problem' then says which, as
 * a sentence to show. Skip and flag them rather than send.
 */
interface SuppliesReleasedExaminers
{
    /** @return array<int, array> newest first; only those released at or after $since if given */
    public function releasedPanels(?\DateTimeInterface $since = null): array;

    /** The candidate's current released panel, or null if none has been released. */
    public function panelForStudent(int $studentId): ?array;
}
