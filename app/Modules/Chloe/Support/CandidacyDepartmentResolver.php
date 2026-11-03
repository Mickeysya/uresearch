<?php

namespace App\Modules\Chloe\Support;

use App\Modules\Chloe\Models\CandidacyDepartment;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Core\Models\User;

/**
 * Which department a student belongs to. A candidacy record carries it (`study_candidacies.department_id`); when it is empty it is derived from the
 * programme text with the exact catalogue name (config('candidacy.programmes')) first, then the keyword patterns in config('candidacy.programme_department_patterns'); text that matches nothing is placed by a hash of
 * the student ID, so the answer is always the same for the same student. The form never lets a student choose it.
 */
final class CandidacyDepartmentResolver
{
    /** The catalogue entry (name, level, department code) for an exact programme name, ignoring a trailing "(Full-Time)", "(Part-Time)" or "(Coursework)". */
    public static function catalogueEntry(?string $programme): ?array
    {
        $name = trim((string) preg_replace('/\s*\((?:full-time|part-time|coursework)\)\s*$/i', '', (string) $programme));

        foreach ((array) config('candidacy.programmes') as $entry) {
            if ($name !== '' && strcasecmp($entry[0], $name) === 0) {
                return ['name' => $entry[0], 'level' => $entry[1], 'department' => $entry[2]];
            }
        }

        return null;
    }

    public static function codeFor(?string $programme, string $studentId): string
    {
        if ($entry = self::catalogueEntry($programme)) {
            return $entry['department'];
        }

        foreach ((array) config('candidacy.programme_department_patterns') as $code => $patterns) {
            foreach ($patterns as $pattern) {
                if ($programme !== null && preg_match($pattern, $programme) === 1) {
                    return $code;
                }
            }
        }

        $codes = array_keys((array) config('candidacy.departments'));

        return $codes[crc32($studentId) % count($codes)];
    }

    public static function forProgramme(?string $programme, string $studentId): ?CandidacyDepartment
    {
        return CandidacyDepartment::where('code', self::codeFor($programme, $studentId))->first();
    }

    /** The live demo accounts always belong to Computer & Information Sciences. */
    public static function codeForUser(User $user, ?string $programme = null): string
    {
        if (in_array(strtolower((string) $user->email), array_map('strtolower', (array) config('candidacy.cis_demo_accounts')), true)) {
            return 'CIS';
        }

        return self::codeFor($programme ?? $user->programme, (string) ($user->matric_no ?? $user->id));
    }

    /** The student's department: the candidacy record's, else derived (and then stored on the record, which is our own table). */
    public static function forCandidacy(StudyCandidacy $candidacy): ?CandidacyDepartment
    {
        if ($candidacy->department_id && ($department = CandidacyDepartment::find($candidacy->department_id))) {
            return $department;
        }

        $user = $candidacy->student;

        if (! $user || ! ($department = CandidacyDepartment::where('code', self::codeForUser($user))->first())) {
            return null;
        }

        $candidacy->forceFill(['department_id' => $department->id])->save();

        return $department;
    }
}
