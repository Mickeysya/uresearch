<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Support\AttendanceRiskEvaluator;
use Illuminate\Support\Collection;

/**
 * Everything the student dashboard puts on screen, gathered in one place.
 *
 * Lives in a service rather than the controller so each panel's data has one
 * named method you can change in isolation -- swap tasks() for a real
 * deadlines table later and no view, route or controller changes.
 *
 * ONE CROSS-MODULE REFERENCE, deliberate and guarded: attendance belongs to
 * app/Modules/Nureen, and Core otherwise never names a module directly (it
 * goes through ModuleRegistry). The dashboard needs attendance figures and
 * there is no registry hook for "panel data" yet, so this reaches for
 * Nureen's models behind hasAttendanceModule(). If that module is ever
 * removed the dashboard degrades to an empty attendance panel instead of
 * fataling. Worth replacing with a ProvidesDashboardPanels contract when a
 * second module wants a panel -- see TODO.md.
 */
class StudentDashboard
{
    /**
     * Panels whose data could not be read this request, keyed by panel name.
     * The view renders a held skeleton for these rather than an empty state --
     * "nothing to show" and "could not load" mean different things to a
     * student looking at their own record.
     *
     * @var array<string, true>
     */
    protected array $failed = [];

    /** Per-request memo, so three panels asking for attendance is one query. */
    protected array $cache = [];

    public function __construct(protected User $student) {}

    /**
     * Run a panel's query, and if it throws, mark the panel unavailable and
     * fall back rather than 500-ing the whole dashboard. One dead module or
     * one bad query should cost that panel, not the page.
     *
     * @template T
     * @param  callable(): T  $fn
     * @param  T  $fallback
     * @return T
     */
    protected function safely(string $panel, callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $this->failed[$panel] = true;
            report($e);

            return $fallback;
        }
    }

    /** @return array<string, true> */
    public function unavailable(): array
    {
        return $this->failed;
    }

    /* ---------------------------------------------------------------
     | The five stat cards
     |---------------------------------------------------------------*/

    /** Latest attendance record, or null if none has been uploaded. */
    public function attendance(): ?AttendanceRecord
    {
        // Cached: three separate panels ask for this in one request.
        if (array_key_exists('attendance', $this->cache)) {
            return $this->cache['attendance'];
        }

        if (! $this->hasAttendanceModule()) {
            // Not an error -- the module simply is not installed -- but the
            // panel still has nothing real to draw, so hold the skeleton.
            $this->failed['attendance'] = true;

            return $this->cache['attendance'] = null;
        }

        return $this->cache['attendance'] = $this->safely('attendance', fn () => AttendanceRecord::where('student_id', $this->student->id)
            ->orderByDesc('period_end')
            ->first(), null);
    }

    /** Straight-line projection of the attendance trend, or null. */
    public function predictedAttendance(): ?float
    {
        $latest = $this->attendance();

        if (! $latest) {
            return null;
        }

        return $this->safely('attendance', fn () => AttendanceRiskEvaluator::project($latest), null);
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

    protected function hasAttendanceModule(): bool
    {
        return class_exists(AttendanceRecord::class);
    }
}
