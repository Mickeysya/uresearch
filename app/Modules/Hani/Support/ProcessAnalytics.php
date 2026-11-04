<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerSeatHistory;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaExaminerProgress;
use App\Modules\Hani\Models\VivaSoftboundDetail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Process Analytics: where the time goes in Hani's workflows, computed from
 * the records the workflows already keep. Nothing here is a prediction.
 *
 *   stage time      approval_history: the time between one decision on an
 *                   application and the next (the first from submission).
 *                   A list sent back and replayed counts each visit.
 *   overdue now     Support\StageSla, the same reading the SLA reminders use
 *   viva pipeline   notice filed -> softbound filed -> all examiner reports
 *                   in -> viva date -> result recorded
 *   re-viva         rounds by current stage, and outcomes by level
 *   turnaround      viva_examiner_progress: thesis received -> report
 *                   received, MSc against PhD, against the report limit
 *   utilisation     examiner_seat_history: examiners holding a main seat on
 *                   a released panel at any point in the month, as a share
 *                   of today's active pool
 *
 * Scoped like the Examiner Dashboard: an Academic Executive sees their own
 * department's candidates and examiners; everyone else allowed in (CGS, the
 * Senior Director, the Dean) sees the whole university. tables() is the one
 * source for the page's tables and the xlsx/csv export.
 */
class ProcessAnalytics
{
    public const MODULES = ['examiner_nomination', 'viva_voce', 'viva_softbound', 're_viva'];

    protected const MAIN_SEATS = ['internal_main_id', 'external_main_id'];

    protected ?string $department = null;

    protected Carbon $from;

    protected Carbon $to;

    public function __construct(
        protected ModuleRegistry $registry,
        protected StageSla $sla,
    ) {}

    /** @return array<int, int> */
    public static function periods(): array
    {
        return array_map('intval', (array) config('hani.analytics.periods', [3, 6, 12]));
    }

    public static function period(mixed $months): int
    {
        $months = (int) $months;

        return in_array($months, self::periods(), true) ? $months : (int) config('hani.analytics.default_period', 12);
    }

    public function for(User $user, int $months): array
    {
        $this->department = Role::isDepartmentScoped($user->role) ? $user->department : null;
        $this->to = now();
        $this->from = now()->subMonths($months)->startOfDay();

        $stages = $this->stageTimes();

        $data = [
            'department' => $this->department,
            'months' => $months,
            'from' => $this->from,
            'to' => $this->to,
            'stages' => $stages,
            'slowest' => $this->slowest($stages),
            'overdue' => $this->overdue(),
            'pipeline' => $this->pipeline(),
            'reviva' => $this->reviva(),
            'turnaround' => $this->turnaround(),
            'utilisation' => $this->utilisation(),
        ];

        return $data + ['chartData' => $this->chartData($data), 'tables' => $this->tables($data)];
    }

    // ---- stage time ------------------------------------------------------

    /**
     * Per module, each stage in chain order: visits, average, median, total
     * days and share of the module's time. Only decisions inside the period.
     *
     * @return array<string, array{label: string, total: float, stages: array<int, array>}>
     */
    protected function stageTimes(): array
    {
        $applications = $this->scoped(Application::query()->whereIn('module_type', self::MODULES))
            ->get(['id', 'module_type', 'submitted_at', 'created_at', 'student_id'])
            ->keyBy('id');

        $samples = [];

        ApprovalHistory::whereIn('application_id', $applications->keys())
            ->orderBy('application_id')->orderBy('created_at')->orderBy('id')
            ->get(['id', 'application_id', 'stage_key', 'created_at'])
            ->groupBy('application_id')
            ->each(function (Collection $rows, $id) use ($applications, &$samples) {
                $application = $applications[$id];
                $previous = Carbon::parse($application->submitted_at ?? $application->created_at);

                foreach ($rows as $row) {
                    if ($row->created_at->betweenIncluded($this->from, $this->to)) {
                        $samples[$application->module_type][$row->stage_key][] = max(0, $previous->diffInSeconds($row->created_at, false)) / 86400;
                    }
                    $previous = $row->created_at;
                }
            });

        $out = [];
        foreach (self::MODULES as $key) {
            if (! $this->registry->has($key)) {
                continue;
            }

            $module = $this->registry->get($key);
            $rows = [];
            foreach ($module->stages() as $stage) {
                $days = $samples[$key][$stage->key] ?? [];
                $rows[] = [
                    'key' => $stage->key,
                    'label' => $stage->label,
                    'count' => count($days),
                    'average' => $days ? round(array_sum($days) / count($days), 1) : null,
                    'median' => $days ? round($this->median($days), 1) : null,
                    'total' => round(array_sum($days), 1),
                ];
            }

            $total = array_sum(array_column($rows, 'total'));
            foreach ($rows as &$row) {
                $row['share'] = $total > 0 ? (int) round(100 * $row['total'] / $total) : 0;
            }
            unset($row);

            $out[$key] = ['label' => $module->label(), 'total' => round($total, 1), 'stages' => $rows];
        }

        return $out;
    }

    /** The stage taking the largest share of its module's time, per module and overall by average. */
    protected function slowest(array $stages): array
    {
        $perModule = [];
        $overall = null;

        foreach ($stages as $key => $module) {
            $measured = array_filter($module['stages'], fn ($s) => $s['count'] > 0);
            if (! $measured) {
                continue;
            }

            $top = collect($measured)->sortByDesc('total')->first();
            $perModule[$key] = $top['key'];

            if ($overall === null || $top['average'] > $overall['average']) {
                $overall = $top + ['module' => $module['label']];
            }
        }

        return ['perModule' => $perModule, 'overall' => $overall];
    }

    // ---- overdue now -----------------------------------------------------

    protected function overdue(): array
    {
        return $this->sla->overdue($this->department)->map(function (array $row) {
            $application = $row['application'];

            return [
                'reference' => $application->reference(),
                'module' => $application->module()->label(),
                'student' => $application->student?->name,
                'department' => $application->student?->department,
                'stage' => $application->currentStage()?->label ?? $row['stage'],
                'since' => $row['entered_at'],
                'days' => $row['days'],
                'unit' => $row['working_days'] ? 'working days' : 'days',
                'limit' => $row['limit'],
                'level' => $row['level'],
            ];
        })->all();
    }

    // ---- viva pipeline ---------------------------------------------------

    /** @return array{steps: array<int, array{label: string, count: int, days: ?float}>, notices: int} */
    protected function pipeline(): array
    {
        $notices = $this->scoped(Application::query()->where('module_type', 'viva_voce'))
            ->whereNotNull('submitted_at')
            ->whereBetween('submitted_at', [$this->from, $this->to])
            ->get(['id', 'submitted_at', 'student_id']);

        $ids = $notices->pluck('id');
        $details = VivaDetail::whereIn('application_id', $ids)->get()->keyBy('application_id');
        $softbound = VivaSoftboundDetail::query()
            ->join('applications', 'applications.id', '=', 'viva_softbound_details.application_id')
            ->whereIn('viva_softbound_details.viva_application_id', $ids)
            ->whereNotNull('applications.submitted_at')
            ->get(['viva_softbound_details.viva_application_id', 'applications.submitted_at'])
            ->groupBy('viva_application_id')
            ->map(fn ($rows) => $rows->min('submitted_at'));
        $reports = VivaExaminerProgress::current()->whereIn('viva_application_id', $ids)
            ->get(['viva_application_id', 'report_received_at'])
            ->groupBy('viva_application_id')
            ->map(fn ($rows) => $rows->every(fn ($r) => $r->report_received_at !== null) ? $rows->max('report_received_at') : null);

        $labels = ['Notice filed', 'Softbound filed', 'Examiner reports in', 'Viva held', 'Result recorded'];
        $counts = array_fill(0, 5, 0);
        $gaps = array_fill(1, 4, []);

        foreach ($notices as $notice) {
            $detail = $details[$notice->id] ?? null;
            $viva = $detail?->viva_at;
            $dates = [
                $notice->submitted_at,
                $softbound[$notice->id] ?? null,
                $reports[$notice->id] ?? null,
                $viva && $viva->lte($this->to) ? $viva : null,
                $detail?->result_recorded_at,
            ];

            foreach ($dates as $i => $date) {
                if ($date === null) {
                    break;
                }
                $counts[$i]++;
                if ($i > 0) {
                    $gaps[$i][] = max(0, Carbon::parse($dates[$i - 1])->diffInSeconds(Carbon::parse($date), false)) / 86400;
                }
            }
        }

        return [
            'notices' => $notices->count(),
            'steps' => array_map(fn ($i) => [
                'label' => $labels[$i],
                'count' => $counts[$i],
                'days' => $i > 0 && $gaps[$i] ? round(array_sum($gaps[$i]) / count($gaps[$i]), 1) : null,
            ], array_keys($labels)),
        ];
    }

    // ---- re-viva ---------------------------------------------------------

    protected function reviva(): array
    {
        $rounds = ReVivaDetail::with('application')
            ->whereHas('application', fn ($q) => $this->scoped($q->where('module_type', 're_viva')))
            ->get();

        $stageLabels = collect($this->registry->has('re_viva') ? $this->registry->get('re_viva')->stages() : [])
            ->mapWithKeys(fn ($s) => [$s->key => $s->label]);

        $byStage = $stageLabels->map(fn () => 0)->all();
        foreach ($rounds as $round) {
            $stage = $round->application?->status === Application::STATUS_PENDING ? $round->application->current_stage : null;
            if ($stage !== null && array_key_exists($stage, $byStage)) {
                $byStage[$stage]++;
            }
        }

        $decided = $rounds->filter(fn ($r) => $r->outcome_recorded_at?->betweenIncluded($this->from, $this->to));
        $byOutcome = [];
        foreach (ReVivaDetail::OUTCOME_LABELS as $level => $label) {
            $inLevel = $decided->where('outcome_level', $level);
            $byOutcome[$level] = [
                'label' => $label,
                'first' => $inLevel->where('cycle_number', 1)->count(),
                'later' => $inLevel->where('cycle_number', '>', 1)->count(),
            ];
        }

        return [
            'byStage' => collect($byStage)->map(fn ($count, $key) => ['label' => $stageLabels[$key], 'count' => $count])->values()->all(),
            'byOutcome' => $byOutcome,
            'decided' => $decided->count(),
            'open' => array_sum($byStage),
        ];
    }

    // ---- examiner turnaround --------------------------------------------

    protected function turnaround(): array
    {
        $rows = VivaExaminerProgress::query()
            ->whereNotNull('thesis_received_at')
            ->whereNotNull('report_received_at')
            ->whereBetween('report_received_at', [$this->from->toDateString(), $this->to->toDateString()])
            ->whereHas('notice', fn ($q) => $this->scoped($q))
            ->get(['viva_application_id', 'thesis_received_at', 'report_received_at']);

        $levels = VivaDetail::whereIn('application_id', $rows->pluck('viva_application_id')->unique())
            ->pluck('programme_level', 'application_id');
        $limits = (array) config('hani.examiner_progress.report_weeks', ['MSC' => 4, 'PHD' => 6]);

        $out = [];
        foreach (['MSC' => 'MSc', 'PHD' => 'PhD'] as $level => $label) {
            $days = $rows->filter(fn ($r) => ($levels[$r->viva_application_id] ?? null) === $level)
                ->map(fn ($r) => (int) $r->thesis_received_at->diffInDays($r->report_received_at, false))
                ->values()->all();
            $limit = 7 * (int) ($limits[$level] ?? 0);

            $out[$level] = [
                'label' => $label,
                'count' => count($days),
                'average' => $days ? round(array_sum($days) / count($days), 1) : null,
                'median' => $days ? round($this->median($days), 1) : null,
                'limit' => $limit,
                'late' => count(array_filter($days, fn ($d) => $d > $limit)),
                'days' => $days,
            ];
        }

        return $out;
    }

    // ---- pool utilisation -----------------------------------------------

    /** @return array<int, array{month: string, label: string, busy: int, pool: int, percent: int}> */
    protected function utilisation(): array
    {
        $pool = Examiner::query()
            ->where('is_active', true)
            ->when($this->department, fn ($q) => $q->where('department', $this->department))
            ->get(['id', 'created_at']);
        $poolIds = $pool->pluck('id')->flip();

        // Who held each main seat, and when: a release, promotion or
        // replacement starts a holding; examined, freed or declined ends it.
        $holdings = [];
        $open = [];
        ExaminerSeatHistory::whereIn('seat', self::MAIN_SEATS)
            ->orderBy('created_at')->orderBy('id')
            ->get(['nomination_id', 'seat', 'examiner_id', 'event', 'created_at'])
            ->each(function ($row) use (&$holdings, &$open) {
                $key = $row->nomination_id.'|'.$row->seat;

                if (in_array($row->event, ['released', 'promoted', 'replaced'], true) && $row->examiner_id) {
                    if (isset($open[$key])) {
                        $holdings[] = $open[$key] + ['to' => $row->created_at];
                    }
                    $open[$key] = ['examiner' => $row->examiner_id, 'from' => $row->created_at];
                } elseif (in_array($row->event, ['examined', 'freed', 'declined'], true) && isset($open[$key])) {
                    $holdings[] = $open[$key] + ['to' => $row->created_at];
                    unset($open[$key]);
                }
            });
        foreach ($open as $holding) {
            $holdings[] = $holding + ['to' => null];
        }

        $months = [];
        $month = $this->from->copy()->startOfMonth();
        while ($month->lte($this->to)) {
            $start = $month->copy();
            $end = $month->copy()->endOfMonth();

            $busy = collect($holdings)
                ->filter(fn ($h) => isset($poolIds[$h['examiner']]) && $h['from']->lte($end) && ($h['to'] === null || $h['to']->gte($start)))
                ->pluck('examiner')->unique()->count();
            // The active pool as it stands: examiners' created_at is when
            // they were entered or imported, not when they joined the pool.
            $size = $pool->count();

            $months[] = [
                'month' => $start->format('Y-m'),
                'label' => $start->format('M Y'),
                'busy' => $busy,
                'pool' => $size,
                'percent' => $size > 0 ? (int) round(100 * $busy / $size) : 0,
            ];
            $month->addMonth();
        }

        return $months;
    }

    // ---- shapes for the page, the charts and the export -----------------

    protected function chartData(array $data): array
    {
        return [
            'stages' => collect($data['stages'])->map(fn ($module, $key) => [
                'id' => $key,
                'labels' => array_column($module['stages'], 'label'),
                'average' => array_column($module['stages'], 'average'),
                'median' => array_column($module['stages'], 'median'),
                'slowest' => array_search($data['slowest']['perModule'][$key] ?? null, array_column($module['stages'], 'key'), true),
            ])->values()->all(),
            'pipeline' => [
                'labels' => array_column($data['pipeline']['steps'], 'label'),
                'counts' => array_column($data['pipeline']['steps'], 'count'),
            ],
            'revivaStages' => [
                'labels' => array_column($data['reviva']['byStage'], 'label'),
                'counts' => array_column($data['reviva']['byStage'], 'count'),
            ],
            'revivaOutcomes' => [
                'labels' => array_map(fn ($l) => 'Level '.$l, array_keys($data['reviva']['byOutcome'])),
                'first' => array_column($data['reviva']['byOutcome'], 'first'),
                'later' => array_column($data['reviva']['byOutcome'], 'later'),
            ],
            'turnaround' => [
                'labels' => array_column($data['turnaround'], 'label'),
                'average' => array_column($data['turnaround'], 'average'),
                'median' => array_column($data['turnaround'], 'median'),
                'limit' => array_column($data['turnaround'], 'limit'),
            ],
            'utilisation' => [
                'labels' => array_column($data['utilisation'], 'label'),
                'percent' => array_column($data['utilisation'], 'percent'),
                'busy' => array_column($data['utilisation'], 'busy'),
                'pool' => array_column($data['utilisation'], 'pool'),
            ],
        ];
    }

    /**
     * Every analytics table as headings and rows: the page shows them and
     * Exports\ProcessAnalyticsExport writes them, so the file is exactly
     * what was on screen.
     *
     * @return array<string, array{headings: array<int, string>, rows: array<int, array<int, mixed>>}>
     */
    public function tables(array $data): array
    {
        $stageRows = [];
        foreach ($data['stages'] as $key => $module) {
            foreach ($module['stages'] as $stage) {
                $stageRows[] = [
                    $module['label'], $stage['label'], $stage['count'], $stage['average'], $stage['median'],
                    $stage['total'], $stage['share'], ($data['slowest']['perModule'][$key] ?? null) === $stage['key'] ? 'Yes' : '',
                ];
            }
        }

        return [
            'Stage times' => [
                'headings' => ['Module', 'Stage', 'Decisions', 'Average days', 'Median days', 'Total days', 'Share of module time (%)', 'Slowest stage'],
                'rows' => $stageRows,
            ],
            'Overdue now' => [
                'headings' => ['Reference', 'Module', 'Student', 'Department', 'Stage', 'At stage since', 'Waited', 'Unit', 'Limit', 'Threshold reached'],
                'rows' => array_map(fn ($r) => [
                    $r['reference'], $r['module'], $r['student'], $r['department'], $r['stage'],
                    $r['since']->format('Y-m-d'), $r['days'], $r['unit'], $r['limit'], $r['level'],
                ], $data['overdue']),
            ],
            'Viva pipeline' => [
                'headings' => ['Step', 'Notices reaching it', 'Average days from the previous step'],
                'rows' => array_map(fn ($s) => [$s['label'], $s['count'], $s['days']], $data['pipeline']['steps']),
            ],
            'Re-viva by stage' => [
                'headings' => ['Stage', 'Open rounds'],
                'rows' => array_map(fn ($s) => [$s['label'], $s['count']], $data['reviva']['byStage']),
            ],
            'Re-viva outcomes' => [
                'headings' => ['Outcome', 'Round 1', 'Round 2 or later'],
                'rows' => array_values(array_map(fn ($o) => [$o['label'], $o['first'], $o['later']], $data['reviva']['byOutcome'])),
            ],
            'Report turnaround' => [
                'headings' => ['Programme', 'Reports', 'Average days', 'Median days', 'Limit (days)', 'Late'],
                'rows' => array_values(array_map(fn ($t) => [$t['label'], $t['count'], $t['average'], $t['median'], $t['limit'], $t['late']], $data['turnaround'])),
            ],
            'Pool utilisation' => [
                'headings' => ['Month', 'Examiners holding a main seat', 'Active pool', 'Utilisation (%)'],
                'rows' => array_map(fn ($m) => [$m['month'], $m['busy'], $m['pool'], $m['percent']], $data['utilisation']),
            ],
        ];
    }

    // ---- helpers ---------------------------------------------------------

    /** Applications of the department's students only, for an AE. */
    protected function scoped($query)
    {
        return $query->when($this->department, fn ($q) => $q->whereHas('student', fn ($s) => $s->where('department', $this->department)));
    }

    /** @param  array<int, float|int>  $values */
    protected function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
