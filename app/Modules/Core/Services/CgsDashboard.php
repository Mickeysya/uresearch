<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Contracts\SuppliesAttendance;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\Concerns\BuildsPanels;
use App\Modules\Core\Services\Concerns\ReadsAttendance;
use App\Modules\Core\Support\Role;
use Illuminate\Support\Collection;

/**
 * Everything the CGS staff dashboard puts on screen.
 *
 * Same shape as StudentDashboard: one named method per panel, every query
 * wrapped in safely() so a dead source costs that panel rather than the page.
 *
 * SCOPE. CGS are administrators, so the headline figures are portal-wide —
 * every application, every student — while "Pending My Action" and the
 * workload breakdown are scoped to the stages this particular CGS role owns.
 * A Senior Director and a Non-Executive see the same totals but different
 * workloads, which is the distinction the design draws.
 */
class CgsDashboard
{
    /** safely(), remember(), markUnavailable(), unavailable(), withTrend(). */
    use BuildsPanels;

    /** attendanceSource() — the module supplying attendance, or null. */
    use ReadsAttendance;

    public function __construct(protected User $staff) {}

    /* ---------------------------------------------------------------
     | The five headline figures
     |---------------------------------------------------------------*/

    /** @return array{count: int, delta: ?float} */
    public function totalApplications(): array
    {
        return $this->safely('applications', fn () => $this->withTrend(
            fn ($from, $to) => Application::query()
                ->when($from, fn ($q) => $q->where('submitted_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('submitted_at', '<', $to))
                ->count()
        ), $this->noTrend());
    }

    /** @return array{count: int, delta: ?float} */
    public function pendingMyAction(): array
    {
        return $this->safely('applications', fn () => $this->withTrend(
            fn ($from, $to) => $this->myQueueQuery()
                ->when($from, fn ($q) => $q->where('submitted_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('submitted_at', '<', $to))
                ->count()
        ), $this->noTrend());
    }

    /** @return array{count: int, delta: ?float} */
    public function approved(): array
    {
        return $this->decidedCount(Application::STATUS_APPROVED);
    }

    /** @return array{count: int, delta: ?float} */
    public function rejected(): array
    {
        return $this->decidedCount(Application::STATUS_REJECTED);
    }

    /** @return array{count: int, delta: ?float} */
    public function activeStudents(): array
    {
        return $this->safely('students', fn () => $this->withTrend(
            fn ($from, $to) => User::query()
                ->where('role', Role::STUDENT)
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('created_at', '<', $to))
                ->count()
        ), $this->noTrend());
    }

    /* ---------------------------------------------------------------
     | Workload Overview
     |---------------------------------------------------------------*/

    /**
     * What is sitting on this role's desk, split by module — the donut.
     *
     * @return Collection<int, array{label: string, count: int, share: float, route: ?string, stage: ?string}>
     */
    public function workload(): Collection
    {
        return $this->safely('applications', function () {
            $queues = app(ModuleRegistry::class)->queuesForRole($this->staff->role);

            $rows = collect($queues)->map(function ($q) {
                $count = Application::query()
                    ->where('module_type', $q['module']->key())
                    ->where('current_stage', $q['stage']->key)
                    ->where('status', Application::STATUS_PENDING)
                    ->count();

                return [
                    'label' => $q['module']->label(),
                    'count' => $count,
                    'share' => 0.0,
                    'route' => $q['module']->queueRoute(),
                    'stage' => $q['stage']->key,
                ];
            })->filter(fn ($r) => $r['count'] > 0)->values();

            $total = (int) $rows->sum('count');

            return $rows
                ->map(fn ($r) => ['share' => $total > 0 ? round($r['count'] / $total * 100) : 0.0] + $r)
                ->sortByDesc('count')
                ->values();
        }, collect());
    }

    /* ---------------------------------------------------------------
     | Pending Actions
     |---------------------------------------------------------------*/

    /** @return Collection<int, Application> */
    public function pendingActions(int $limit = 5): Collection
    {
        return $this->safely('applications', fn () => $this->myQueueQuery()
            ->with('student')
            ->orderByDesc('submitted_at')
            ->limit($limit)
            ->get(), collect());
    }

    /* ---------------------------------------------------------------
     | Attendance Alerts
     |---------------------------------------------------------------*/

    /**
     * How every student's latest attendance record falls across the bands.
     *
     * Uses StudentDashboard::attendanceBands() rather than its own thresholds,
     * so a student reading "Good" on their dashboard is counted as Good here.
     * The 80% figure in the footer is separate on purpose: 80% is the
     * *mandatory compliance threshold*, while the bands are the traffic-light
     * scale, and they do not line up (a student on 78% is compliant-failing
     * but only "Warning" on the scale).
     *
     * @return array{bands: Collection<int, array{label: string, tone: string, count: int}>, belowThreshold: int, total: int}
     */
    public function attendanceAlerts(): array
    {
        $empty = ['bands' => collect(), 'belowThreshold' => 0, 'total' => 0];

        $source = $this->attendanceSource();

        if (! $source) {
            $this->markUnavailable('attendance');

            return $empty;
        }

        return $this->safely('attendance', function () use ($source) {
            $latest = $this->latestRecords($source);

            $bands = collect(StudentDashboard::attendanceBands())
                ->map(fn ($band) => [
                    'label' => $band['label'],
                    'tone' => $band['tone'],
                    'count' => $latest->filter(
                        fn ($r) => StudentDashboard::toneFor((float) $r->percentage) === $band['tone']
                    )->count(),
                ]);

            return [
                'bands' => $bands,
                'belowThreshold' => $latest->filter(fn ($r) => (float) $r->percentage < 80.0)->count(),
                'total' => $latest->count(),
            ];
        }, $empty);
    }

    /* ---------------------------------------------------------------
     | Recent Activities
     |---------------------------------------------------------------*/

    /**
     * One merged feed of what has happened across the portal.
     *
     * Two sources, because the portal records them separately: a submission
     * is a row appearing in `applications`, and a decision is a row in
     * `approval_history`. Merged and re-sorted here rather than stored as a
     * third "activity" table nobody would remember to write to.
     *
     * @return Collection<int, array{text: string, at: \Illuminate\Support\Carbon, tone: string, icon: string, url: ?string}>
     */
    public function recentActivities(int $limit = 6): Collection
    {
        return $this->safely('activity', function () use ($limit) {
            $registry = app(ModuleRegistry::class);

            $decisions = ApprovalHistory::with('application.student', 'approver')
                ->latest('created_at')
                ->limit($limit)
                ->get()
                ->filter(fn ($h) => $h->application && $h->application->student)
                ->map(fn ($h) => [
                    'text' => $h->application->student->name.'\'s '.$registry->labelFor($h->application->module_type)
                        .' application has been '.$h->decision.' by '.($h->approver->name ?? 'an approver').'.',
                    'at' => $h->created_at,
                    'tone' => $h->decision === 'rejected' ? 'critical' : 'good',
                    'icon' => $h->decision === 'rejected' ? 'pencil' : 'check',
                    'url' => null,
                ]);

            $submissions = Application::with('student')
                ->whereNotNull('submitted_at')
                ->latest('submitted_at')
                ->limit($limit)
                ->get()
                ->filter(fn ($a) => $a->student)
                ->map(fn ($a) => [
                    'text' => $a->student->name.' submitted a new '.$registry->labelFor($a->module_type).'.',
                    'at' => $a->submitted_at,
                    'tone' => 'info',
                    'icon' => 'doc',
                    'url' => null,
                ]);

            return $decisions->concat($submissions)
                ->sortByDesc(fn ($row) => $row['at'])
                ->take($limit)
                ->values();
        }, collect());
    }

    /* ---------------------------------------------------------------
     | Helpers
     |---------------------------------------------------------------*/

    /** Applications sitting on a stage this role owns. */
    protected function myQueueQuery()
    {
        $queues = app(ModuleRegistry::class)->queuesForRole($this->staff->role);

        $query = Application::query()->where('status', Application::STATUS_PENDING);

        if ($queues === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($queues) {
            foreach ($queues as $queue) {
                $q->orWhere(fn ($inner) => $inner
                    ->where('module_type', $queue['module']->key())
                    ->where('current_stage', $queue['stage']->key));
            }
        });
    }

    /** @return array{count: int, delta: ?float} */
    protected function decidedCount(string $status): array
    {
        return $this->safely('applications', fn () => $this->withTrend(
            fn ($from, $to) => Application::query()
                ->where('status', $status)
                ->when($from, fn ($q) => $q->where('updated_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('updated_at', '<', $to))
                ->count()
        ), $this->noTrend());
    }

    /**
     * The most recent attendance reading for each student, one each.
     *
     * @return Collection<int, \App\Modules\Core\Support\AttendanceReading>
     */
    protected function latestRecords(SuppliesAttendance $source): Collection
    {
        return $this->remember('latestRecords', fn () => $source->latestPerStudent());
    }
}
