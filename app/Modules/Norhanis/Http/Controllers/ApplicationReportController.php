<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Support\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reports and analytics for Travel, Claims and Publication.
 *
 * The lists answer "where is this student's application?". This answers the
 * questions about all of them at once: how many came in, how many were
 * approved, which stage they pile up on, and how long each stage takes -- the
 * bottleneck view in docs/scope/norhanis.md -- with a CSV of the rows behind
 * the figures for CGS's own reporting.
 *
 * It extends the overview controller on purpose: who may see which module,
 * and which students' rows, must be the same rule in both places.
 */
class ApplicationReportController extends ApplicationOverviewController
{
    /**
     * Who may open the report: the CGS roles that hold a stage in these
     * chains. Senior Executive CGS holds none, so is not among them.
     */
    public const REPORT_ROLES = [Role::NON_EXEC_CGS, Role::MANAGER_CGS, Role::SENIOR_DIRECTOR_CGS];

    /** The periods on offer: days back from today, or 0 for everything. */
    protected const PERIODS = [30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last 12 months', 0 => 'All time'];

    /** How many of the longest-waiting applications to name. */
    protected const LONGEST = 10;

    /** How many months the intake chart goes back. */
    protected const MONTHS = 6;

    public function index(Request $request, ModuleRegistry $registry, ?string $module = null)
    {
        [$shown, $period, $query] = $this->scope($request, $registry, $module);

        $stages = $this->stagesOf($registry, $shown);
        $slugs = array_flip($shown);

        // One pass over the applications and one over their history is every
        // figure on the page: only the columns needed, no models per row.
        $applications = $query()->get(['id', 'module_type', 'status', 'current_stage', 'submitted_at']);
        $history = ApprovalHistory::query()
            ->whereIn('application_id', $query()->select('id'))
            ->orderBy('application_id')->orderBy('created_at')->orderBy('id')
            ->get(['application_id', 'stage_key', 'created_at'])
            ->groupBy('application_id');

        $roleOf = function (string $moduleKey, ?string $stageKey) use ($registry): ?string {
            return collect($registry->get($moduleKey)->stages())->firstWhere('key', $stageKey)?->role;
        };

        $totals = [];
        $waiting = [];
        $spent = [];
        $longest = [];

        foreach ($shown as $slug => $key) {
            $totals[$slug] = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
            $waiting[$slug] = array_fill_keys(array_keys($stages), 0);
            $spent[$slug] = array_fill_keys(array_keys($stages), ['days' => 0.0, 'decisions' => 0]);
        }

        foreach ($applications as $application) {
            $slug = $slugs[$application->module_type];
            $totals[$slug][$application->status]++;

            // Time on a stage runs from the decision before it (or from
            // submission, for the first stage) to the decision that closed it.
            $since = $application->submitted_at;

            foreach ($history->get($application->id, []) as $entry) {
                $role = $roleOf($application->module_type, $entry->stage_key);

                if ($role !== null && $since !== null && isset($spent[$slug][$role])) {
                    $spent[$slug][$role]['days'] += $since->diffInMinutes($entry->created_at) / 1440;
                    $spent[$slug][$role]['decisions']++;
                }

                $since = $entry->created_at;
            }

            if ($application->status === Application::STATUS_PENDING) {
                $role = $roleOf($application->module_type, $application->current_stage);

                if ($role !== null && isset($waiting[$slug][$role])) {
                    $waiting[$slug][$role]++;
                }

                $longest[$application->id] = $since ? $since->diffInMinutes(now()) / 1440 : 0;
            }
        }

        arsort($longest);
        $longest = array_slice($longest, 0, self::LONGEST, true);

        $average = array_map(fn (array $byRole) => array_map(
            fn (array $sum) => $sum['decisions'] > 0 ? round($sum['days'] / $sum['decisions'], 1) : null,
            $byRole,
        ), $spent);
        $months = $this->intakeByMonth($applications, $slugs);
        $labels = array_map(fn (string $key) => $registry->get($key)->label(), $shown);

        return view('norhanis::report.index', [
            'scope' => $module,
            'period' => $period,
            'periods' => self::PERIODS,
            'modules' => $labels,
            // The same figures the tables show, in the shape the charts read.
            'chart' => [
                'modules' => $labels,
                'stages' => array_values($stages),
                'waiting' => array_map('array_values', $waiting),
                'days' => array_map('array_values', $average),
                'months' => $months,
            ],
            'choices' => array_map(fn (string $key) => $registry->get($key)->label(), $this->allowedModules($request, $registry, null)),
            'stages' => $stages,
            'totals' => $totals,
            'waiting' => $waiting,
            'average' => $average,
            'months' => $months,
            'longest' => Application::with('student')->findMany(array_keys($longest))
                ->sortByDesc(fn (Application $application) => $longest[$application->id])
                ->map(fn (Application $application) => ['application' => $application, 'days' => round($longest[$application->id], 1)])
                ->values(),
            'role' => $request->user()->role,
        ]);
    }

    /** The rows behind the report, one line per application, as a CSV file. */
    public function export(Request $request, ModuleRegistry $registry, ?string $module = null)
    {
        [, , $query] = $this->scope($request, $registry, $module);

        $name = 'applications-'.($module ?? 'all').'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Application No.', 'Student', 'Matric No.', 'Module', 'Application', 'Status', 'Now With', 'Submitted', 'Days Since Submitted']);

            // cursor(), so a year of applications is never all in memory.
            foreach ($query()->with('student')->orderBy('id')->cursor() as $application) {
                $stage = $application->isOpen() ? $application->currentStage() : null;

                fputcsv($out, array_map([self::class, 'cell'], [
                    $application->id,
                    $application->student?->name,
                    $application->student?->matric_no,
                    $application->module()->label(),
                    $application->module()->summary($application),
                    ucfirst($application->status),
                    $stage?->label ?? 'Closed',
                    $application->submitted_at?->format('Y-m-d'),
                    $application->submitted_at ? (int) $application->submitted_at->diffInDays(now()) : '',
                ]));
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    /**
     * A value made safe for a spreadsheet. A cell that begins with =, +, -
     * or @ is run as a formula when the file is opened, and the application
     * text here was typed by students.
     */
    protected static function cell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    /**
     * What this request covers: the modules, the period, and a fresh query
     * for the applications in them that this reader may see.
     *
     * @return array{0: array<string, string>, 1: int, 2: \Closure(): Builder}
     */
    protected function scope(Request $request, ModuleRegistry $registry, ?string $module): array
    {
        $allowed = $this->allowedModules($request, $registry, $module);
        $shown = $module !== null ? [$module => $allowed[$module]] : $allowed;

        $period = (int) ($request->validate([
            'period' => ['nullable', 'in:'.implode(',', array_keys(self::PERIODS))],
        ])['period'] ?? 0);

        $query = fn (): Builder => $this->visibleTo($request, $shown)
            ->when($period > 0, fn (Builder $query) => $query->where('submitted_at', '>=', now()->subDays($period)));

        return [$shown, $period, $query];
    }

    /**
     * Applications submitted in each of the last MONTHS months, per module.
     * Counted here rather than in SQL so it reads the same on MySQL and on the
     * SQLite the tests run against.
     *
     * @param  array<string, string>  $slugs  module_type => slug
     * @return array{labels: array<int, string>, series: array<string, array<int, int>>}
     */
    protected function intakeByMonth(Collection $applications, array $slugs): array
    {
        $months = collect(range(self::MONTHS - 1, 0))->map(fn (int $back) => Carbon::now()->startOfMonth()->subMonths($back));
        $series = array_fill_keys(array_values($slugs), array_fill(0, self::MONTHS, 0));
        $index = $months->map->format('Y-m')->flip();

        foreach ($applications as $application) {
            $at = $index[$application->submitted_at?->format('Y-m')] ?? null;

            if ($at !== null) {
                $series[$slugs[$application->module_type]][$at]++;
            }
        }

        return ['labels' => $months->map->format('M Y')->all(), 'series' => $series];
    }
}
