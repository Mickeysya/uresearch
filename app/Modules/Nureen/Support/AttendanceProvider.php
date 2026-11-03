<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Contracts\SuppliesAttendance;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\AttendanceReading;
use App\Modules\Nureen\Models\AttendanceRecord;
use Illuminate\Support\Collection;

/**
 * Nureen's attendance module, as Core's dashboards see it.
 *
 * The only place that knows both `attendance_records` and Core's
 * AttendanceReading. Everything else on either side of the boundary talks to
 * one or the other, never both -- which is the point: Core's dashboards no
 * longer name a class in this folder, so renaming AttendanceRecord or
 * changing its columns is a change inside this module again.
 *
 * Registered from this module's ModuleProvider. If this module were removed,
 * nothing would bind SuppliesAttendance and Core's attendance panels would
 * hold their skeletons rather than fatal.
 */
class AttendanceProvider implements SuppliesAttendance
{
    public function latestFor(User $student): ?AttendanceReading
    {
        $record = AttendanceRecord::where('student_id', $student->id)
            ->orderByDesc('period_end')
            ->first();

        return $record ? $this->toReading($record) : null;
    }

    /**
     * The student dashboard's "Attendance Action" card, worded here so Core
     * only renders it. The figures come from Support\AttendanceTarget --
     * plain arithmetic on the student's own sessions, never the projection
     * or the trend (those are CGS's, on the at-risk list). Supportive in
     * tone throughout: the card says what to do, not how bad things are.
     */
    public function actionFor(User $student): ?array
    {
        // UTrace attendance is not compulsory for this student (AttendanceScope).
        if (! AttendanceScope::monitors($student)) {
            return [
                'label' => 'Attendance', 'value' => 'Not monitored',
                'note' => 'UTrace attendance applies to full-time research students',
                'pill' => 'View Attendance', 'pillTone' => 'muted', 'url' => route('attendance.overview'),
            ];
        }

        $target = app(AttendanceTarget::class)->for($student);
        $view = route('attendance.overview');

        return match ($target['state']) {
            AttendanceTarget::ON_TRACK => [
                'label' => 'Attendance Target', 'value' => 'On track', 'note' => 'Minimum 80% this semester',
                'pill' => 'View Attendance', 'pillTone' => 'good', 'url' => $view,
            ],
            AttendanceTarget::ATTEND => [
                'label' => 'Attendance Action',
                'value' => "Attend {$target['sessions_needed']} of the next {$target['sessions_remaining']} sessions",
                'note' => 'to stay at or above 80% this semester',
                'pill' => 'View Attendance', 'pillTone' => 'info', 'url' => $view,
            ],
            AttendanceTarget::JUSTIFICATION => $this->justificationCard($target['appeal']),
            default => [
                'label' => 'Attendance Target', 'value' => 'Keep checking in', 'note' => 'Your attendance target will appear after a few weeks',
                'pill' => 'View Attendance', 'pillTone' => 'muted', 'url' => $view,
            ],
        };
    }

    /** 80% is out of reach: ask for a justification, or show the appeal already filed. */
    protected function justificationCard(?Application $appeal): array
    {
        if (! $appeal) {
            return [
                'label' => 'Attendance Action', 'value' => 'Justification needed',
                'note' => 'Submit a justification letter and monthly activity breakdown to CGS',
                'pill' => 'Submit Justification', 'pillTone' => 'warn', 'url' => route('attendance-appeal.create'),
            ];
        }

        [$value, $note, $tone] = match ($appeal->status) {
            Application::STATUS_APPROVED => ['Appeal approved', 'CGS has accepted your justification', 'good'],
            Application::STATUS_REJECTED => ['Appeal rejected', 'Talk to your supervisor or CGS about next steps', 'warn'],
            default => ['Appeal under review', 'CGS is reviewing your justification', 'info'],
        };

        return [
            'label' => 'Attendance Action', 'value' => $value, 'note' => $note,
            'pill' => 'View Appeal', 'pillTone' => $tone, 'url' => route('applications.show', $appeal),
        ];
    }

    /** @return Collection<int, AttendanceReading> */
    public function latestPerStudent(): Collection
    {
        return AttendanceRecord::latestPerStudent()
            ->get()
            ->map(fn (AttendanceRecord $r) => $this->toReading($r));
    }

    protected function toReading(AttendanceRecord $record): AttendanceReading
    {
        return new AttendanceReading(
            percentage: (float) $record->percentage,
            period_end: $record->period_end,
            sessions_attended: (int) $record->sessions_attended,
            sessions_total: (int) $record->sessions_total,
            // A flag stored before the scope changed must not reach Core's
            // dashboards for a student who is no longer monitored.
            at_risk: (bool) $record->at_risk && AttendanceScope::monitors($record->student_id),
        );
    }
}
