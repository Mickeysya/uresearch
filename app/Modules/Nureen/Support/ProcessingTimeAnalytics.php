<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\ModuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Is CGS meeting its Client Charter" and "what is past its charter right
 * now", for Nureen's three approval-chain modules.
 *
 * Computed in PHP from consecutive approval_history rows per application,
 * not a database window function: the test suite runs on SQLite, the app
 * on MySQL, and one portable calculation beats maintaining two queries. At
 * FYP data volumes this is a handful of applications, not a reason to push
 * the work into the database.
 *
 * Every duration here is in working days on Support\WorkingDayCalendar,
 * and every "overdue" is Support\ClientCharter's -- the same calendar and
 * the same rule the approver queues and the reminder commands use, so this
 * page can never report a different answer from the one they act on.
 */
class ProcessingTimeAnalytics
{
    public const MODULES = ['ga_extension', 'supervision', 'ga_certification'];

    public function __construct(
        protected ModuleRegistry $registry,
        protected ClientCharter $charter,
        protected WorkingDayCalendar $calendar,
    ) {}

    /**
     * One row per (module, stage) with at least one decision recorded.
     *
     * A "decision" is any approval_history row at that stage -- endorsed,
     * approved, reviewed, rejected or returned -- since each one is what
     * moved the application off that stage. Its duration is the working
     * days from the application reaching the stage (submission, or the
     * previous history row) to that row; it was "within charter" when that
     * is no more than the stage's charter days -- exactly the opposite of
     * ClientCharter's overdue rule. A stage with no charter figure reports
     * its average but no compliance percentage.
     *
     * Rows come out in each module's own chain order, as listed in
     * config('nureen.charter_days').
     *
     * @return Collection<int, array{
     *     module: string,
     *     module_label: string,
     *     stage_key: string,
     *     stage_label: string,
     *     charter_days: ?int,
     *     decisions: int,
     *     within_charter: ?int,
     *     within_charter_percent: ?float,
     *     average_working_days: float,
     * }>
     */
    public function complianceByStageAndModule(): Collection
    {
        $applications = Application::query()
            ->whereIn('module_type', self::MODULES)
            // A saved draft has never been submitted, so it has no stage
            // time to report -- excluded by name, not by accident.
            ->where('status', '!=', Application::STATUS_DRAFT)
            ->with('history')
            ->get();

        /** @var array<string, array{labels: array<int, string>, days: array<int, int>}> */
        $byKey = [];

        foreach ($applications as $application) {
            $previous = $application->submitted_at;

            foreach ($application->history->sortBy([['created_at', 'asc'], ['id', 'asc']]) as $entry) {
                if ($previous) {
                    $key = $application->module_type.'|'.$entry->stage_key;
                    $byKey[$key]['labels'][] = $entry->stage_label;
                    // From the first working day on or after arrival, the
                    // same anchor ClientCharter::stageClock() uses.
                    $byKey[$key]['days'][] = $this->calendar->workingDaysBetween(
                        $this->calendar->onOrAfter(CarbonImmutable::instance($previous)),
                        CarbonImmutable::instance($entry->created_at),
                    );
                }

                $previous = $entry->created_at;
            }
        }

        return collect($byKey)
            ->map(function (array $data, string $key) {
                [$module, $stageKey] = explode('|', $key, 2);
                $days = collect($data['days']);
                $charterDays = $this->charter->charterDays($module, $stageKey);
                $within = $charterDays === null ? null : $days->filter(fn (int $d) => $d <= $charterDays)->count();

                return [
                    'module' => $module,
                    'module_label' => $this->registry->get($module)->label(),
                    'stage_key' => $stageKey,
                    // The label as recorded at decision time -- a stage
                    // that is not on the live config's chain (GA
                    // Extension's senior_director, in 'cgs' mode) still
                    // reads properly.
                    'stage_label' => end($data['labels']),
                    'charter_days' => $charterDays,
                    'decisions' => $days->count(),
                    'within_charter' => $within,
                    'within_charter_percent' => $within === null ? null : round($within / $days->count() * 100, 1),
                    'average_working_days' => round($days->avg(), 1),
                ];
            })
            ->sortBy(fn (array $row) => sprintf(
                '%d|%03d',
                array_search($row['module'], self::MODULES, true),
                $this->chainPosition($row['module'], $row['stage_key']),
            ))
            ->values();
    }

    /**
     * Pending applications whose current stage is past its Client Charter
     * (or, for a stage with no charter, its stalled_days fallback) -- the
     * very applications the remind-stalled commands are emailing about.
     *
     * @return Collection<int, array{
     *     application: Application,
     *     module_label: string,
     *     stage_label: string,
     *     entered_at: CarbonImmutable,
     *     due_date: CarbonImmutable,
     *     on_charter: bool,
     *     working_days_overdue: int,
     * }>
     */
    public function overdueApplications(): Collection
    {
        $overdue = collect();

        $applications = Application::query()
            ->whereIn('module_type', self::MODULES)
            ->where('status', Application::STATUS_PENDING)
            ->with(['student', 'history'])
            ->get();

        foreach ($applications as $application) {
            $clock = $this->charter->stageClock($application);

            if (! $clock || ! $clock['overdue']) {
                continue;
            }

            $overdue->push([
                'application' => $application,
                'module_label' => $this->registry->get($application->module_type)->label(),
                'stage_label' => $clock['stage']->label,
                'entered_at' => $clock['entered_at'],
                'due_date' => $clock['due_date'],
                'on_charter' => $clock['on_charter'],
                'working_days_overdue' => $clock['working_days_overdue'],
            ]);
        }

        return $overdue->sortByDesc('working_days_overdue')->values();
    }

    protected function chainPosition(string $module, string $stageKey): int
    {
        $position = array_search($stageKey, array_keys(config("nureen.charter_days.{$module}", [])), true);

        return $position === false ? 999 : $position;
    }
}
