<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\AttendanceRecord;

/**
 * What a student should do next about attendance -- the student dashboard's
 * "Attendance Action" card (Core's stat-cards partial, fed through
 * Core\Contracts\SuppliesAttendance::actionFor()).
 *
 * It replaces the old "Predicted Attendance" card. The projection and the
 * trend are CGS's alone (the at-risk list); a student is told only plain
 * arithmetic on their own figures: how many of the sessions still to come
 * they need to attend to finish the semester at or above 80%.
 *
 *   on_track       not flagged, or flagged but already certain to finish
 *                  at or above 80% whatever happens next
 *   attend         flagged, and 80% is still reachable: attend N of the
 *                  next M sessions (sessionsNeeded())
 *   justification  80% can no longer be reached this semester -- or, once
 *                  an attendance appeal exists, that appeal's status
 *   too_early      fewer than projection_min_periods periods this semester
 *
 * "Flagged" is the same outcome CGS sees and the early-warning email is
 * sent on (AttendanceRiskEvaluator::assess()), so the card never disagrees
 * with the email. Figures are this semester's only, with excused sessions
 * counted per config('nureen.attendance.excused_sessions').
 */
class AttendanceTarget
{
    public const ON_TRACK = 'on_track';

    public const ATTEND = 'attend';

    public const JUSTIFICATION = 'justification';

    public const TOO_EARLY = 'too_early';

    /**
     * The fewest of the next $remaining sessions a student must attend so
     * that (attended + N) / (total + remaining) >= threshold -- or null
     * when even attending all of them falls short.
     *
     * Pure arithmetic: no projection, no trend. 0 means the target is
     * already secured.
     */
    public static function sessionsNeeded(int $attended, int $total, int $remaining, float $threshold = AttendanceRiskEvaluator::THRESHOLD): ?int
    {
        // Rounded before ceil() so 112.00000000001 sessions is 112, not 113.
        $needed = (int) ceil(round($threshold * ($total + $remaining) / 100 - $attended, 6));
        $needed = max(0, $needed);

        return $needed > $remaining ? null : $needed;
    }

    /**
     * Sessions still to come this semester: the periods left of a
     * configured-length semester, times the average sessions per period
     * so far. Never negative.
     *
     * @param  array<int, int>  $sessionsPerPeriod  each period's scheduled sessions so far
     */
    public static function remainingSessions(array $sessionsPerPeriod, int $semesterPeriods): int
    {
        $periodsLeft = max(0, $semesterPeriods - count($sessionsPerPeriod));

        if ($periodsLeft === 0 || $sessionsPerPeriod === []) {
            return 0;
        }

        return (int) round($periodsLeft * array_sum($sessionsPerPeriod) / count($sessionsPerPeriod));
    }

    /**
     * The card for one student.
     *
     * @return array{state: string, sessions_needed: ?int, sessions_remaining: ?int, appeal: ?Application}
     */
    public function for(User $student): array
    {
        $records = AttendanceRecord::where('student_id', $student->id)
            ->when(config('nureen.attendance.semester_start'), fn ($q, $start) => $q->whereDate('period_end', '>=', $start))
            ->orderBy('period_end')
            ->get()
            ->filter(fn (AttendanceRecord $r) => $r->sessions_total > 0);

        $result = ['state' => self::TOO_EARLY, 'sessions_needed' => null, 'sessions_remaining' => null, 'appeal' => null];

        if ($records->count() < (int) config('nureen.attendance.projection_min_periods', 3)) {
            return $result;
        }

        $remaining = self::remainingSessions(
            $records->map(fn (AttendanceRecord $r) => (int) $r->sessions_total)->values()->all(),
            (int) config('nureen.attendance.semester_periods', 14),
        );
        $needed = self::sessionsNeeded(
            $records->sum(fn (AttendanceRecord $r) => $r->countedAttended()),
            $records->sum(fn (AttendanceRecord $r) => $r->countedTotal()),
            $remaining,
        );

        $result['sessions_remaining'] = $remaining;
        $result['sessions_needed'] = $needed;

        if (! AttendanceRiskEvaluator::assess($student->id)['at_risk'] || $needed === 0) {
            return ['state' => self::ON_TRACK] + $result;
        }

        if ($needed !== null) {
            return ['state' => self::ATTEND] + $result;
        }

        return ['state' => self::JUSTIFICATION, 'appeal' => $this->latestAppeal($student)] + $result;
    }

    /** The student's most recent attendance appeal this semester, if any. */
    protected function latestAppeal(User $student): ?Application
    {
        return Application::where('student_id', $student->id)
            ->where('module_type', 'attendance_appeal')
            ->where('status', '!=', Application::STATUS_DRAFT)
            ->when(config('nureen.attendance.semester_start'), fn ($q, $start) => $q->whereDate('submitted_at', '>=', $start))
            ->latest('submitted_at')
            ->latest('id')
            ->first();
    }
}
