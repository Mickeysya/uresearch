<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\User;

/**
 * Whether a student's attendance is monitored at all: risk-evaluated,
 * flagged, alerted. UTrace attendance is compulsory only for full-time
 * research students (Postgraduate Handbook 2025).
 *
 * config('nureen.attendance.scope'):
 *   'all'                every student (the behaviour before this switch);
 *   'full_time_research' only those StudyProfile shows are full-time
 *                        research, and -- per 'scope_unknown' -- those it
 *                        cannot tell about.
 *
 * Every check that flags or alerts asks this first: AttendanceRiskEvaluator
 * (the rule and the projection), AttendanceEarlyWarning (the email) and
 * AttendanceProvider (what Core's dashboards are told). Attendance is still
 * recorded and shown for everyone; it just never puts an unmonitored
 * student at risk.
 */
final class AttendanceScope
{
    public static function monitors(User|int|null $student): bool
    {
        if (config('nureen.attendance.scope') !== 'full_time_research') {
            return true;
        }

        $student = $student instanceof User ? $student : User::find($student);

        if (! $student) {
            return false;
        }

        return StudyProfile::for($student)->isFullTimeResearch()
            ?? config('nureen.attendance.scope_unknown') !== 'exclude';
    }

    /** Why a student is not monitored, for CGS; null when they are. */
    public static function exclusionReason(User $student): ?string
    {
        if (self::monitors($student)) {
            return null;
        }

        $profile = StudyProfile::for($student);

        return $profile->isFullTimeResearch() === false
            ? 'Not monitored: '.$profile->describe().' ('.$profile->source.'). UTrace attendance applies to full-time research students only.'
            : 'Not monitored: study mode not on record, and unknown students are excluded (nureen.attendance.scope_unknown).';
    }
}
