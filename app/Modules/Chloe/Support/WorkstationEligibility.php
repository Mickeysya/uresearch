<?php

namespace App\Modules\Chloe\Support;

use App\Modules\Chloe\Models\DemoStudentProfile;
use App\Modules\Chloe\Models\StudyCandidacy;
use Illuminate\Support\Carbon;

/**
 * PART 1: the one eligibility rule (Term of Use clauses 2/16/17), used
 * everywhere a student is offered or picks a seat -- the student select
 * endpoint, the student seat lists, CGS Assign Student search, and CGS
 * Reassign. Clause 2's supervisor endorsement has no equivalent anywhere
 * in this system and is deliberately NOT implemented (see the module's
 * own report on this).
 *
 * Three conditions, all required:
 *   (a) Active status -- StudyCandidacy.status for a real student,
 *       DemoStudentProfile.status for a demo one (that table mirrors
 *       StudyCandidacy's field names for exactly this kind of reuse).
 *   (b) candidacy_expiry_date in the future.
 *   (c) full-time AND research.
 *
 * Neither StudyCandidacy nor DemoStudentProfile has a dedicated study-mode
 * or programme-type column -- the ONLY place either exists anywhere in
 * this codebase is the free-text `programme` string on `users` /
 * `demo_student_profiles` (e.g. "MSc Full-Time", "PhD Chemical
 * Engineering (Part-Time)"). (c) is therefore parsed from that text:
 * containing "part-time" (case-insensitive) means Part-Time, containing
 * "coursework" means Coursework; everything else defaults to Full-Time
 * Research, since every real programme string in this system today is one
 * of the "<Degree> <Field> (Full-Time|Part-Time)" research-programme
 * patterns and none says Coursework. This is a reported limitation, not
 * invented data: a programme string that never says "Coursework" cannot
 * be told apart from one that is implicitly research by any signal that
 * actually exists in the database.
 */
final class WorkstationEligibility
{
    /** PART 1 #4's exact wording -- shown to the student and used nowhere else, so it is reworded in exactly one place if it ever needs to change. */
    public const INELIGIBLE_MESSAGE = 'Your student record is not eligible for a postgraduate workstation. Please contact the Centre for Graduate Studies (CGS).';

    public static function isEligible(StudentIdentity $identity): bool
    {
        return self::isActive($identity)
            && self::hasFutureExpiry($identity)
            && self::isFullTimeResearch($identity);
    }

    protected static function isActive(StudentIdentity $identity): bool
    {
        if ($identity->demoProfile) {
            return $identity->demoProfile->status === DemoStudentProfile::STATUS_ACTIVE;
        }

        $status = StudyCandidacy::where('student_id', $identity->user->id)->value('status');

        return $status === StudyCandidacy::STATUS_ACTIVE;
    }

    protected static function hasFutureExpiry(StudentIdentity $identity): bool
    {
        $expiry = $identity->demoProfile
            ? $identity->demoProfile->candidacy_expiry_date
            : StudyCandidacy::where('student_id', $identity->user->id)->value('candidacy_expiry_date');

        if (! $expiry) {
            return false;
        }

        return Carbon::parse($expiry)->isFuture();
    }

    protected static function isFullTimeResearch(StudentIdentity $identity): bool
    {
        $programme = mb_strtolower((string) $identity->programme);

        if ($programme === '') {
            return false;
        }

        $isPartTime = str_contains($programme, 'part-time');
        $isCoursework = str_contains($programme, 'coursework');

        return ! $isPartTime && ! $isCoursework;
    }
}
