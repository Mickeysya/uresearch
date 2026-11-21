<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\Concerns\BuildsPanels;
use App\Modules\Core\Services\Concerns\ReadsAttendance;
use App\Modules\Core\Services\Concerns\ReadsPostgraduateProgress;
use App\Modules\Core\Support\Role;
use Illuminate\Support\Carbon;
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
    use BuildsPanels {
        withTrend as protected trendFrom;
    }

    /** The month-over-month arrow needs at least this many last month -- see withTrend(). */
    public const MIN_TREND_BASELINE = 5;

    /** attendanceSource() — the module supplying attendance, or null. */
    use ReadsAttendance;

    /** postgraduateProgressSource() — the module supplying Stage Gate/Allowance figures, or null. */
    use ReadsPostgraduateProgress;

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

    /**
     * Of Pending My Action, how many are this officer's own modules
     * (config/cgs_assignment.php) -- the rest they can view but another
     * officer decides. Null for any role but Non-Executive CGS, and when
     * none of the modules waiting on that role is assigned to anyone, so
     * the card shows nothing rather than a meaningless "0 assigned".
     */
    public function assignedToMe(): ?int
    {
        if ($this->staff->role !== Role::NON_EXEC_CGS) {
            return null;
        }

        return $this->safely('applications', function () {
            $assignment = app(CgsAssignment::class);
            $queues = collect(app(ModuleRegistry::class)->queuesForRole($this->staff->role));

            if ($queues->every(fn ($q) => $assignment->officer($q['module']->key()) === null)) {
                return null;
            }

            $mine = $queues->filter(fn ($q) => $assignment->officer($q['module']->key())?->is($this->staff));

            if ($mine->isEmpty()) {
                return 0;
            }

            return Application::query()
                ->where('status', Application::STATUS_PENDING)
                ->where(function ($q) use ($mine) {
                    foreach ($mine as $queue) {
                        $q->orWhere(fn ($inner) => $inner
                            ->where('module_type', $queue['module']->key())
                            ->where('current_stage', $queue['stage']->key));
                    }
                })
                ->count();
        }, null);
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

    /**
     * How many Stage Gates are past deadline right now, portal-wide — see
     * Core\Contracts\SuppliesPostgraduateProgress. No trend: a single
     * daily-evaluated snapshot, not a count with a meaningful month-ago
     * comparison the way applications/students have.
     *
     * @return array{count: int, delta: ?float}
     */
    public function stageGateBottleneck(): array
    {
        $source = $this->postgraduateProgressSource();

        if (! $source) {
            $this->markUnavailable('postgraduate');

            return $this->noTrend();
        }

        return $this->safely('postgraduate', fn () => ['count' => $source->stageGateBottleneckCount(), 'delta' => null], $this->noTrend());
    }

    /**
     * How many students are currently flagged 3+ months accruing
     * allowance — same threshold the Allowance page's own anomaly chart
     * uses.
     *
     * @return array{count: int, delta: ?float}
     */
    public function allowanceAnomalies(): array
    {
        $source = $this->postgraduateProgressSource();

        if (! $source) {
            $this->markUnavailable('postgraduate');

            return $this->noTrend();
        }

        return $this->safely('postgraduate', fn () => ['count' => $source->allowanceAnomalyCount(), 'delta' => null], $this->noTrend());
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
     * The bands follow the attendance rule, whose lines come from the
     * supplying module (SuppliesAttendance::riskThresholds(): 80% and 85%):
     *
     *   Good       watchFrom and above          (85% and above)
     *   Watch      threshold to below watchFrom (80% to below 85%)
     *   Below 80%  under threshold              (under 80%)
     *
     * Half-open ranges, so every tracked student is in exactly one band, the
     * three counts add up to 'total', and 'belowThreshold' (the footer) is
     * the third band's count rather than a separate figure. This used to
     * reuse StudentDashboard::attendanceBands() (75/85), whose "Warning
     * (75% – 84%)" overlapped the below-80% footer. The student and
     * supervisor dashboards keep that scale; only this card changed.
     *
     * @return array{bands: Collection<int, array{label: string, tone: string, count: int}>, belowThreshold: int, threshold: string, total: int}
     */
    public function attendanceAlerts(): array
    {
        $empty = ['bands' => collect(), 'belowThreshold' => 0, 'threshold' => '', 'total' => 0];

        $source = $this->attendanceSource();

        if (! $source) {
            $this->markUnavailable('attendance');

            return $empty;
        }

        return $this->safely('attendance', function () use ($source) {
            $latest = $this->latestPerStudent($source);

            ['threshold' => $threshold, 'watchFrom' => $watchFrom] = $source->riskThresholds();
            $t = self::percent($threshold);
            $w = self::percent($watchFrom);

            // [label, tone, from (inclusive), below (exclusive; null = no upper bound)]
            $bands = collect([
                ["Good ({$w} and above)", 'good', $watchFrom, null],
                ["Watch ({$t} to below {$w})", 'warn', $threshold, $watchFrom],
                ["Below {$t}", 'critical', null, $threshold],
            ])->map(fn ($band) => [
                'label' => $band[0],
                'tone' => $band[1],
                'count' => $latest->filter(function ($r) use ($band) {
                    $pct = (float) $r->percentage;

                    return ($band[2] === null || $pct >= $band[2]) && ($band[3] === null || $pct < $band[3]);
                })->count(),
            ]);

            return [
                'bands' => $bands,
                'belowThreshold' => $bands->last()['count'],
                'threshold' => $t,
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
     * @return Collection<int, array{text: string, at: Carbon, tone: string, icon: string, url: ?string}>
     */
    public function recentActivities(int $limit = 6): Collection
    {
        return $this->safely('activity', function () use ($limit) {
            $registry = app(ModuleRegistry::class);

            return $this->mergeFeed(
                $limit,
                $this->recentDecisions($limit)->map(fn ($h) => [
                    'text' => $h->application->student->name.'\'s '.$registry->labelFor($h->application->module_type)
                        .' application has been '.$h->decision.' by '.($h->approver->name ?? 'an approver').'.',
                    'at' => $h->created_at,
                    'tone' => $h->decision === 'rejected' ? 'critical' : 'good',
                    'icon' => $h->decision === 'rejected' ? 'pencil' : 'check',
                    'url' => null,
                ]),
                $this->recentSubmissions($limit)->map(fn ($a) => [
                    'text' => $a->student->name.' submitted a new '.$registry->labelFor($a->module_type).'.',
                    'at' => $a->submitted_at,
                    'tone' => 'info',
                    'icon' => 'doc',
                    'url' => null,
                ]),
            );
        }, collect());
    }

    /* ---------------------------------------------------------------
     | Helpers
     |---------------------------------------------------------------*/

    /** 80.0 => "80%", 82.5 => "82.5%". */
    protected static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
    }

    /**
     * BuildsPanels::withTrend(), with this dashboard's smallest comparable
     * month: below MIN_TREND_BASELINE last month, no arrow at all, so a tiny
     * demo baseline (1 last month, 41 this month) never reads "↑ 4000%".
     */
    protected function withTrend(callable $counter): array
    {
        return $this->trendFrom($counter, self::MIN_TREND_BASELINE);
    }

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
}
