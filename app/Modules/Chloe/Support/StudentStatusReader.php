<?php

namespace App\Modules\Chloe\Support;

use App\Modules\Chloe\Models\CandidacyDemoStudentStatus;
use App\Modules\Chloe\Models\StudyCandidacy;
use Carbon\CarbonImmutable;

/**
 * THE one place that answers: has this student submitted the Softbound (and when), completed, become inactive (deferred included), or been
 * dismissed, and WHICH source answered. Read-only; nothing here writes anywhere, and no page lets CGS set Softbound.
 *
 * Lookup order for Softbound / completed / inactive, stop at the first hit:
 *   1. the shared Softbound module's real table (SharedStatusAdapter; not active until its table is known)
 *   2. our own clearly labelled DEMO overlay table, used ONLY when the shared source has no record for the student AND the student is one of
 *      our demo students. It never overrides a shared record and never applies to a real student.
 *   3. no record = false
 * Our own candidacy record (study_candidacies.status / dismissed_at) is read for a real student after the shared source. "Dismissed" always
 * reads the existing study_candidacies.dismissed_at (a demo student: the demo profile's status).
 *
 * config('candidacy.status_source'): demo (overlay only, until merge) | shared_with_demo_fallback | shared (production: overlay off).
 */
final class StudentStatusReader
{
    public static function for(StudentIdentity $identity): StudentStatus
    {
        $mode = (string) config('candidacy.status_source', 'demo');
        $none = StudentStatus::NONE;
        $sources = ['softbound' => $none, 'completed' => $none, 'inactive' => $none, 'dismissed' => $none];
        $date = null;
        $softbound = $completed = $inactive = $dismissed = false;

        // 1. shared
        if ($shared = SharedStatusAdapter::lookup($identity->studentId)) {
            [$softbound, $date, $completed, $inactive] = [$shared['softbound_submitted_at'] !== null, $shared['softbound_submitted_at'], $shared['completed'], $shared['inactive']];
            $sources = array_merge($sources, ['softbound' => StudentStatus::SHARED, 'completed' => StudentStatus::SHARED, 'inactive' => StudentStatus::SHARED]);
        } elseif ($identity->demoProfile && $mode !== 'shared' && ($row = CandidacyDemoStudentStatus::where('demo_profile_id', $identity->demoProfile->id)->first())) {
            // 2. the demo overlay (demo students only, and only because the shared source had no record)
            $softbound = $row->softbound_submitted_at !== null;
            $date = $row->softbound_submitted_at ? CarbonImmutable::parse($row->softbound_submitted_at) : null;
            [$completed, $inactive] = [$row->completed, $row->inactive];
            $sources = array_merge($sources, ['softbound' => $softbound ? StudentStatus::OVERLAY : $none, 'completed' => $completed ? StudentStatus::OVERLAY : $none, 'inactive' => $inactive ? StudentStatus::OVERLAY : $none]);
        } elseif ($identity->user && ($candidacy = StudyCandidacy::where('student_id', $identity->user->id)->first())) {
            // our own candidacy record, for a real student
            $softbound = $candidacy->status === StudyCandidacy::STATUS_SOFTBOUND;
            $completed = $candidacy->status === StudyCandidacy::STATUS_COMPLETED;
            $inactive = $candidacy->status === StudyCandidacy::STATUS_INACTIVE;
            $sources = array_merge($sources, ['softbound' => $softbound ? StudentStatus::RECORD : $none, 'completed' => $completed ? StudentStatus::RECORD : $none, 'inactive' => $inactive ? StudentStatus::RECORD : $none]);
        }

        // dismissed: the existing candidacy fields (a demo student: its profile status)
        if ($identity->user && ($candidacy ??= StudyCandidacy::where('student_id', $identity->user->id)->first())) {
            $dismissed = $candidacy->dismissed_at !== null || $candidacy->status === StudyCandidacy::STATUS_DISMISSED;
        } elseif ($identity->demoProfile) {
            $dismissed = strtolower((string) $identity->demoProfile->status) === 'dismissed';
        }
        $sources['dismissed'] = $dismissed ? StudentStatus::RECORD : $none;

        return new StudentStatus($softbound, $date, $completed, $inactive, $dismissed, $sources);
    }
}
