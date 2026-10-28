<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\WorkflowEngine;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * "How long does each stage actually take" and "what is stuck right now",
 * for Nureen's three approval-chain modules.
 *
 * Computed in PHP from consecutive approval_history rows per application,
 * not a database window function: the test suite runs on SQLite, the app
 * on MySQL, and one portable calculation beats maintaining two queries. At
 * FYP data volumes this is a handful of applications, not a reason to push
 * the work into the database.
 */
class ProcessingTimeAnalytics
{
    public const MODULES = ['ga_extension', 'supervision', 'ga_certification'];

    public function __construct(
        protected WorkflowEngine $engine,
        protected ModuleRegistry $registry,
    ) {}

    /**
     * One row per (module, stage) that has at least one decision recorded:
     * the average time between an application arriving at that stage and
     * the decision that moved it on.
     *
     * @return Collection<int, array{module: string, module_label: string, stage_key: string, stage_label: string, average_days: float, decisions: int}>
     */
    public function averageDaysByStageAndModule(): Collection
    {
        $applications = Application::query()
            ->whereIn('module_type', self::MODULES)
            ->with(['history' => fn ($query) => $query->orderBy('created_at')])
            ->get();

        /** @var Collection<string, Collection<int, float>> */
        $byKey = collect();

        foreach ($applications as $application) {
            $previous = $application->submitted_at;

            foreach ($application->history as $entry) {
                if ($previous) {
                    // diffInDays() is signed in Carbon 3: receiver->diffInDays(arg)
                    // is (arg - receiver), so calling it on the EARLIER
                    // timestamp with the later one as the argument is what
                    // gives a positive duration here.
                    $days = max(0.0, Carbon::parse($previous)->diffInDays(Carbon::parse($entry->created_at)));

                    $key = $application->module_type.'|'.$entry->stage_key;
                    $byKey[$key] = ($byKey[$key] ?? collect())->push($days);
                }

                $previous = $entry->created_at;
            }
        }

        return $byKey
            ->map(function (Collection $days, string $key) {
                [$module, $stageKey] = explode('|', $key, 2);

                return [
                    'module' => $module,
                    'module_label' => $this->registry->get($module)->label(),
                    'stage_key' => $stageKey,
                    'stage_label' => $this->stageLabel($module, $stageKey),
                    'average_days' => round($days->avg(), 1),
                    'decisions' => $days->count(),
                ];
            })
            ->values()
            ->sortBy([['module_label', 'asc'], ['stage_label', 'asc']])
            ->values();
    }

    /**
     * Pending applications that have outgrown their own module's reminder
     * threshold -- deliberately the same number remind-stalled commands
     * use (config('nureen.reminders.<module>.stalled_days')), so this list
     * matches what reminders are actually firing on rather than keeping a
     * second number in step with it by hand.
     *
     * @return Collection<int, array{application: Application, module_label: string, stage_label: string, days_waiting: int}>
     */
    public function overdueApplications(): Collection
    {
        $overdue = collect();

        foreach (self::MODULES as $module) {
            $days = (int) config("nureen.reminders.{$module}.stalled_days");
            $cutoff = now()->subDays($days);

            $applications = Application::query()
                ->where('module_type', $module)
                ->where('status', Application::STATUS_PENDING)
                ->where('updated_at', '<=', $cutoff)
                ->with('student')
                ->get();

            foreach ($applications as $application) {
                $stage = $this->engine->currentStage($application);

                $overdue->push([
                    'application' => $application,
                    'module_label' => $this->registry->get($module)->label(),
                    'stage_label' => $stage?->label ?? $application->current_stage,
                    'days_waiting' => max(0, (int) $application->updated_at->diffInDays(now())),
                ]);
            }
        }

        return $overdue->sortByDesc('days_waiting')->values();
    }

    protected function stageLabel(string $module, string $stageKey): string
    {
        foreach ($this->registry->get($module)->stages() as $stage) {
            if ($stage->key === $stageKey) {
                return $stage->label;
            }
        }

        return $stageKey;
    }
}
