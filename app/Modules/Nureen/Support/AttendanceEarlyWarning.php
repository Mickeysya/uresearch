<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\AttendanceAlert;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Models\AttendanceRiskState;
use App\Modules\Nureen\Notifications\AttendanceAtRisk;

/**
 * The automatic early warning: runs the full risk check
 * (AttendanceRiskEvaluator::assess()) for each student, stores the outcome
 * in attendance_risk_states for CGS, and emails the student -- with a copy
 * to their supervisor -- when they need it. No CGS action is involved.
 *
 * Runs after every attendance sync (AttendanceSyncRunner: the nightly
 * schedule and "Sync Now"), after a manual upload, and for one student when
 * CGS approves their attendance appeal.
 *
 * NO REPEATED ALERTS. A student is emailed only when:
 *   - they newly become at risk (they were not at risk at the previous
 *     check -- which includes a student who recovered and has now dropped
 *     again), or
 *   - they were already at risk and their projected end-of-semester % has
 *     fallen by MORE than config('nureen.attendance.realert_margin') points
 *     since the projection recorded with their last alert.
 * Every alert is recorded in attendance_alerts.
 */
class AttendanceEarlyWarning
{
    /**
     * @param  iterable<int>|null  $studentIds  null = every student with attendance on file
     * @return array{evaluated: int, newly_at_risk: int, alerted: int}
     */
    public function run(?iterable $studentIds = null): array
    {
        $ids = $studentIds === null
            ? AttendanceRecord::query()->distinct()->pluck('student_id')
            : collect($studentIds);

        // `percentage` is stored, and depends on the excused-sessions rule
        // (config nureen.attendance.excused_sessions). Re-derive it for the
        // few rows with excusals, so a change of rule takes effect at the
        // next check rather than leaving stale figures behind.
        AttendanceRecord::where('sessions_excused', '>', 0)
            ->when($studentIds !== null, fn ($q) => $q->whereIn('student_id', $ids))
            ->get()
            ->each(function (AttendanceRecord $record) {
                if ((float) $record->percentage !== $record->countedPercentage()) {
                    $record->save();
                    $record->update(['at_risk' => AttendanceRiskEvaluator::isAtRisk($record)]);
                }
            });

        $evaluated = $newly = $alerted = 0;

        foreach ($ids as $studentId) {
            $student = User::where('id', $studentId)->where('role', Role::STUDENT)->first();

            if (! $student) {
                continue;
            }

            $evaluated++;
            $outcome = $this->check($student);
            $newly += (int) $outcome['newly_at_risk'];
            $alerted += (int) $outcome['alerted'];
        }

        return ['evaluated' => $evaluated, 'newly_at_risk' => $newly, 'alerted' => $alerted];
    }

    /** @return array{newly_at_risk: bool, alerted: bool} */
    public function check(User $student): array
    {
        $assessment = AttendanceRiskEvaluator::assess($student->id);

        $state = AttendanceRiskState::firstOrNew(['student_id' => $student->id]);
        $wasAtRisk = $state->exists && $state->at_risk;

        $state->fill([
            'at_risk' => $assessment['at_risk'],
            'reason' => $assessment['reason'],
            'current_percentage' => $assessment['current'],
            'projected_percentage' => $assessment['projected'],
            'trend_slope' => $assessment['slope'],
            'periods_used' => $assessment['periods_used'],
            'projection_status' => $assessment['projection_status'],
            'latest_period_end' => $assessment['latest']?->period_end,
            'evaluated_at' => now(),
        ])->save();

        if (! $assessment['at_risk']) {
            return ['newly_at_risk' => false, 'alerted' => false];
        }

        $newly = ! $wasAtRisk;

        if (! $newly && ! $this->droppedBeyondMargin($student, $assessment['projected'])) {
            return ['newly_at_risk' => false, 'alerted' => false];
        }

        $this->alert($student, $assessment);

        return ['newly_at_risk' => $newly, 'alerted' => true];
    }

    /**
     * Already at risk: worth another email only if the projection has
     * fallen by more than the margin since the last one. An at-risk student
     * with no alert on record (flagged before alerts were recorded) is owed
     * one.
     */
    protected function droppedBeyondMargin(User $student, ?float $projected): bool
    {
        $last = AttendanceAlert::where('student_id', $student->id)->latest('sent_at')->latest('id')->first();

        if (! $last) {
            return true;
        }

        if ($projected === null || $last->projected_percentage === null) {
            return false;
        }

        return ($last->projected_percentage - $projected) > (float) config('nureen.attendance.realert_margin', 5.0);
    }

    protected function alert(User $student, array $assessment): void
    {
        $supervisor = $student->supervisor;

        AttendanceAlert::create([
            'student_id' => $student->id,
            'reason' => $assessment['reason'],
            'current_percentage' => $assessment['current'] ?? 0,
            'projected_percentage' => $assessment['projected'],
            'supervisor_copied' => $supervisor !== null,
            'sent_at' => now(),
        ]);

        $notification = new AttendanceAtRisk($student, $assessment['reason'], (float) ($assessment['current'] ?? 0));

        $student->notify($notification);
        $supervisor?->notify($notification);
    }
}
