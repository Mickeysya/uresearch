<?php

namespace App\Modules\Norhanis\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * How long each approval stage takes, and so when an open application should
 * finish.
 *
 * Two figures per stage: the client charter (what CGS promises, from
 * Config/charter.php) and the median of what the stage has actually taken.
 * The median is used once a stage has MIN_SAMPLES decisions behind it; before
 * that the charter stands in, so a prediction exists from the first
 * application. A median rather than an average, because one application left
 * on a desk for forty days would drag an average for months.
 *
 * Every figure is in working days, Monday to Friday, as the charter is.
 * Public holidays are not skipped.
 */
class StageDurations
{
    /** Decisions a stage needs before its own history replaces the charter. */
    public const MIN_SAMPLES = 5;

    /** The modules this covers: those with a charter. */
    public const MODULES = ['travel', 'claims_student', 'publication'];

    /** @var array<string, array<string, array<int, float>>>|null module => stage key => durations, built once */
    protected ?array $samples = null;

    /** The charter's working days for a stage, or null if it has none. */
    public function charterDays(string $module, string $stageKey): ?int
    {
        return config("norhanis.charter.{$module}.{$stageKey}");
    }

    /**
     * What a stage typically takes, and where that figure came from.
     *
     * @return array{days: float|null, basis: string|null, samples: int}
     *         basis is 'history', 'charter', or null when neither exists
     */
    public function typical(string $module, string $stageKey): array
    {
        $durations = $this->samples()[$module][$stageKey] ?? [];

        if (count($durations) >= self::MIN_SAMPLES) {
            return ['days' => round(self::median($durations), 1), 'basis' => 'history', 'samples' => count($durations)];
        }

        $charter = $this->charterDays($module, $stageKey);

        return ['days' => $charter === null ? null : (float) $charter, 'basis' => $charter === null ? null : 'charter', 'samples' => count($durations)];
    }

    /**
     * The road ahead for an open application: each stage with its figures,
     * and the date the last stage should be decided by.
     *
     * Null for anything closed, or a module this does not cover.
     *
     * @return array{steps: array<int, array{stage: \App\Modules\Core\Support\Stage, state: string, charter: int|null, typical: float|null, basis: string|null, waited: float|null, overdue: bool}>, remaining: float, expected: Carbon}|null
     */
    public function estimate(Application $application): ?array
    {
        if ($application->status !== Application::STATUS_PENDING || ! in_array($application->module_type, self::MODULES, true)) {
            return null;
        }

        // The current stage started at the last decision, or at submission.
        $since = $application->history()->reorder()->latest('created_at')->latest('id')->value('created_at');
        $since = $since ? Carbon::parse($since) : $application->submitted_at;

        $steps = [];
        $remaining = 0.0;

        foreach ($application->progress() as $step) {
            $charter = $this->charterDays($application->module_type, $step['stage']->key);
            $typical = $this->typical($application->module_type, $step['stage']->key);
            $waited = null;

            if ($step['state'] === 'current') {
                $waited = $since ? round(self::workingDaysBetween($since, now()), 1) : 0.0;
                $remaining += max(($typical['days'] ?? 0) - $waited, 0);
            } elseif ($step['state'] === 'upcoming') {
                $remaining += $typical['days'] ?? 0;
            }

            $steps[] = [
                'stage' => $step['stage'],
                'state' => $step['state'],
                'charter' => $charter,
                'typical' => $typical['days'],
                'basis' => $typical['basis'],
                'waited' => $waited,
                'overdue' => $waited !== null && $charter !== null && $waited > $charter,
            ];
        }

        return [
            'steps' => $steps,
            'remaining' => round($remaining, 1),
            'expected' => self::addWorkingDays(now(), $remaining),
        ];
    }

    /**
     * What a whole route typically takes, start to finish, for an application
     * not yet submitted: the lead-time check on the Travel form. The same
     * per-stage figures as estimate(), so the form and the stepper agree.
     *
     * @param  array<int, \App\Modules\Core\Support\Stage>  $stages
     */
    public function routeDays(string $module, array $stages): float
    {
        return round(array_sum(array_map(fn ($stage) => $this->typical($module, $stage->key)['days'] ?? 0, $stages)), 1);
    }

    /**
     * Working days from one moment to another, to the fraction: a stage
     * decided at Friday noon that arrived at Thursday noon took 1.0, and one
     * that arrived Friday noon and was decided Monday noon took 1.0 as well.
     */
    public static function workingDaysBetween(CarbonInterface $from, CarbonInterface $to): float
    {
        $days = 0.0;
        $cursor = Carbon::instance($from);

        while ($cursor < $to) {
            $midnight = $cursor->copy()->startOfDay()->addDay();
            $end = $midnight < $to ? $midnight : Carbon::instance($to);

            if (! $cursor->isWeekend()) {
                $days += $cursor->diffInSeconds($end) / 86400;
            }

            $cursor = $end;
        }

        return $days;
    }

    /** The moment a number of working days after another, weekends skipped. */
    public static function addWorkingDays(CarbonInterface $from, float $days): Carbon
    {
        $cursor = Carbon::instance($from);

        while (true) {
            if ($cursor->isWeekend()) {
                $cursor = $cursor->copy()->startOfDay()->addDay();

                continue;
            }

            $left = $cursor->diffInSeconds($cursor->copy()->startOfDay()->addDay()) / 86400;

            if ($days <= $left) {
                return $cursor->copy()->addSeconds((int) round($days * 86400));
            }

            $days -= $left;
            $cursor = $cursor->copy()->startOfDay()->addDay();
        }
    }

    /** @param  array<int, float>  $values  at least one */
    public static function median(array $values): float
    {
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * Every decided stage's duration, grouped by module and stage. Time on a
     * stage runs from the decision before it (or from submission, for the
     * first stage) to the decision that closed it -- the same rule as the
     * report. Built once per request: the container holds one instance.
     *
     * @return array<string, array<string, array<int, float>>>
     */
    protected function samples(): array
    {
        if ($this->samples !== null) {
            return $this->samples;
        }

        $query = fn () => Application::query()
            ->whereIn('module_type', self::MODULES)
            ->whereNotNull('submitted_at');

        $applications = $query()->get(['id', 'module_type', 'submitted_at']);

        $history = ApprovalHistory::query()
            ->whereIn('application_id', $query()->select('id'))
            ->orderBy('application_id')->orderBy('created_at')->orderBy('id')
            ->get(['application_id', 'stage_key', 'created_at'])
            ->groupBy('application_id');

        $samples = [];

        foreach ($applications as $application) {
            $since = $application->submitted_at;

            foreach ($history->get($application->id, []) as $entry) {
                $samples[$application->module_type][$entry->stage_key][] = self::workingDaysBetween($since, $entry->created_at);
                $since = $entry->created_at;
            }
        }

        return $this->samples = $samples;
    }
}
