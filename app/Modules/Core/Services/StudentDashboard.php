<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\Concerns\BuildsPanels;
use App\Modules\Core\Services\Concerns\ReadsAttendance;
use App\Modules\Core\Support\AttendanceReading;
use Illuminate\Support\Collection;

/**
 * Everything the student dashboard puts on screen, gathered in one place.
 *
 * Lives in a service rather than the controller so each panel's data has one
 * named method you can change in isolation -- swap tasks() for a real
 * deadlines table later and no view, route or controller changes.
 *
 * NO CROSS-MODULE REFERENCES. Attendance belongs to app/Modules/Nureen, and
 * this class used to import its Eloquent models directly behind a
 * class_exists() guard -- the one place Core named another folder. It now
 * asks for Core\Contracts\SuppliesAttendance, which Nureen implements and
 * binds. If no module supplies attendance the panel holds its skeleton, the
 * same degradation as before, with the dependency pointing the right way.
 */
class StudentDashboard
{
    /** safely(), remember(), markUnavailable(), unavailable(), withTrend(). */
    use BuildsPanels;

    /** attendanceSource() — the module supplying attendance, or null. */
    use ReadsAttendance;

    public function __construct(protected User $student) {}

    /* ---------------------------------------------------------------
     | The five stat cards
     |---------------------------------------------------------------*/

    /** Latest attendance record, or null if none has been uploaded. */
    public function attendance(): ?AttendanceReading
    {
        // Memoised: three separate panels ask for this in one request.
        return $this->remember('attendance', function () {
            $source = $this->attendanceSource();

            if (! $source) {
                // Not an error -- no module supplies attendance -- but the
                // panel still has nothing real to draw, so hold the skeleton
                // rather than showing an empty state.
                $this->markUnavailable('attendance');

                return null;
            }

            return $this->safely('attendance', fn () => $source->latestFor($this->student), null);
        });
    }

    /** Straight-line projection of the attendance trend, or null. */
    public function predictedAttendance(): ?float
    {
        $source = $this->attendanceSource();

        // No source, or nothing on file yet, means there is no trend to draw.
        if (! $source || ! $this->attendance()) {
            return null;
        }

        return $this->safely('attendance', fn () => $source->projectionFor($this->student), null);
    }

    public function activeApplications(): int
    {
        return $this->safely('applications', fn () => $this->student->applications()
            ->where('status', Application::STATUS_PENDING)
            ->count(), 0);
    }

    public function unreadNotifications(): int
    {
        return $this->safely('notifications', fn () => $this->student->unreadNotifications()->count(), 0);
    }

    /* ---------------------------------------------------------------
     | The panels
     |---------------------------------------------------------------*/

    /** @return Collection<int, Application> */
    public function applications(int $limit = 5): Collection
    {
        return $this->safely('applications', fn () => $this->student->applications()
            ->with('history')
            ->latest('submitted_at')
            ->limit($limit)
            ->get(), collect());
    }

    /** @return Collection<int, \Illuminate\Notifications\DatabaseNotification> */
    public function notifications(int $limit = 4): Collection
    {
        return $this->safely('notifications', fn () => $this->student->notifications()->latest()->limit($limit)->get(), collect());
    }

    /**
     * "Upcoming Tasks" — things genuinely waiting on the student.
     *
     * DERIVED FROM REAL STATE, not a tasks table: this portal has no
     * deadlines model, so rather than invent due dates these are the two
     * situations where the student actually has to do something next:
     *
     *   1. An application was rejected and needs looking at / resubmitting.
     *   2. Attendance is flagged at-risk and no appeal is open yet.
     *
     * When a real deadlines/tasks table arrives (RPD reminders will want
     * one), replace the body of this method and the panel keeps working.
     *
     * @return Collection<int, array{title: string, subtitle: string, meta: string, urgency: string, url: ?string}>
     */
    public function tasks(int $limit = 4): Collection
    {
        return $this->safely('tasks', fn () => $this->buildTasks($limit), collect());
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function buildTasks(int $limit): Collection
    {
        $tasks = collect();

        foreach ($this->student->applications()->where('status', Application::STATUS_REJECTED)->latest('updated_at')->limit($limit)->get() as $application) {
            $decided = $application->rejection();

            $tasks->push([
                'title' => 'Review and resubmit',
                'subtitle' => $application->module()->label().' · '.$application->reference(),
                'meta' => $decided ? 'Rejected '.$decided->created_at->format('j M Y') : 'Not approved',
                'urgency' => 'critical',
                'url' => route('applications.show', $application),
            ]);
        }

        $attendance = $this->attendance();

        if ($attendance?->at_risk && ! $this->hasOpenAttendanceAppeal()) {
            $tasks->push([
                'title' => 'File an attendance appeal',
                'subtitle' => 'Attendance is below the 80% threshold',
                'meta' => 'Period ending '.$attendance->period_end->format('j M Y'),
                'urgency' => 'warn',
                'url' => route('attendance-appeal.create'),
            ]);
        }

        return $tasks->take($limit)->values();
    }

    public function upcomingTaskCount(): int
    {
        return $this->tasks(99)->count();
    }

    /* ---------------------------------------------------------------
     | Helpers
     |---------------------------------------------------------------*/

    /**
     * Attendance bands, shared by the gauge and its legend so the two can
     * never disagree about where "Good" stops and "Warning" starts.
     *
     * @return array<int, array{label: string, tone: string, from: float}>
     */
    public static function attendanceBands(): array
    {
        return [
            ['label' => 'Good (≥ 85%)', 'tone' => 'good', 'from' => 85.0],
            ['label' => 'Warning (75% – 84%)', 'tone' => 'warn', 'from' => 75.0],
            ['label' => 'Critical (< 75%)', 'tone' => 'critical', 'from' => 0.0],
        ];
    }

    public static function toneFor(?float $percentage): string
    {
        if ($percentage === null) {
            return 'none';
        }

        foreach (self::attendanceBands() as $band) {
            if ($percentage >= $band['from']) {
                return $band['tone'];
            }
        }

        return 'critical';
    }

    protected function hasOpenAttendanceAppeal(): bool
    {
        return $this->student->applications()
            ->where('module_type', 'attendance_appeal')
            ->where('status', Application::STATUS_PENDING)
            ->exists();
    }

}
