<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\AttendanceRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
    /** @var array<string, true> */
    protected array $failed = [];

    /** @var array<string, mixed> */
    protected array $cache = [];

    public function __construct(protected User $staff) {}

    /**
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
        ), ['count' => 0, 'delta' => null]);
    }

    /** @return array{count: int, delta: ?float} */
    public function pendingMyAction(): array
    {
        return $this->safely('applications', fn () => $this->withTrend(
            fn ($from, $to) => $this->myQueueQuery()
                ->when($from, fn ($q) => $q->where('submitted_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('submitted_at', '<', $to))
                ->count()
        ), ['count' => 0, 'delta' => null]);
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
        ), ['count' => 0, 'delta' => null]);
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

        if (! class_exists(AttendanceRecord::class)) {
            $this->failed['attendance'] = true;

            return $empty;
        }

        return $this->safely('attendance', function () {
            $latest = $this->latestRecords();

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

            $label = function (string $key) use ($registry) {
                return $registry->has($key) ? $registry->get($key)->label() : ucfirst(str_replace('_', ' ', $key));
            };

            $decisions = ApprovalHistory::with('application.student', 'approver')
                ->latest('created_at')
                ->limit($limit)
                ->get()
                ->filter(fn ($h) => $h->application && $h->application->student)
                ->map(fn ($h) => [
                    'text' => $h->application->student->name.'\'s '.$label($h->application->module_type)
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
                    'text' => $a->student->name.' submitted a new '.$label($a->module_type).'.',
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
        ), ['count' => 0, 'delta' => null]);
    }

    /**
     * Run a count three ways: all time (the headline), this month, and last
     * month (the "vs last month" delta).
     *
     * Returns a null delta rather than a fake 0% when last month had nothing
     * to compare against — a brand-new portal has no trend, and showing
     * "↑ 0%" would imply it measured one.
     *
     * @param  callable(?\Illuminate\Support\Carbon, ?\Illuminate\Support\Carbon): int  $counter
     * @return array{count: int, delta: ?float}
     */
    protected function withTrend(callable $counter): array
    {
        $startOfThis = now()->startOfMonth();
        $startOfLast = now()->subMonthNoOverflow()->startOfMonth();

        $total = $counter(null, null);
        $thisMonth = $counter($startOfThis, null);
        $lastMonth = $counter($startOfLast, $startOfThis);

        $delta = $lastMonth > 0
            ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1)
            : null;

        return ['count' => $total, 'delta' => $delta];
    }

    /** The most recent attendance record for each student, one row each. */
    protected function latestRecords(): Collection
    {
        if (array_key_exists('latestRecords', $this->cache)) {
            return $this->cache['latestRecords'];
        }

        $newest = AttendanceRecord::select('student_id', DB::raw('MAX(period_end) as max_period_end'))
            ->groupBy('student_id');

        return $this->cache['latestRecords'] = AttendanceRecord::joinSub($newest, 'latest', function ($join) {
            $join->on('attendance_records.student_id', '=', 'latest.student_id')
                ->on('attendance_records.period_end', '=', 'latest.max_period_end');
        })->get(['attendance_records.*']);
    }
}
