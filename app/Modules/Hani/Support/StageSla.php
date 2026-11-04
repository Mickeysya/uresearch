<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How long each of Hani's open applications has been at its current stage,
 * against the per-stage limits in hani.sla. One reading for both
 * `hani:sla-reminders` (who to remind) and Process Analytics ("overdue
 * now"), so the page and the emails never disagree.
 *
 * The clock starts at the latest approval_history row (the decision that
 * moved it here, a return included), or at submission for the first stage.
 * Stages that hani.sla.stages marks working_days count Monday to Friday less
 * the public holidays (Support\WorkingDays); the others count calendar days.
 *
 * Stages in hani.sla.skip are left out entirely: another command already
 * chases them, and nobody should get two reminders for the same wait.
 */
class StageSla
{
    /** @return array{days: array<int, int>, working_days: bool}|null */
    public function rule(string $module, string $stage): ?array
    {
        if ($this->skipReason($module, $stage) !== null) {
            return null;
        }

        $rule = config("hani.sla.stages.{$module}.{$stage}");

        return is_array($rule) ? [
            'days' => array_values(array_map('intval', (array) ($rule['days'] ?? []))),
            'working_days' => (bool) ($rule['working_days'] ?? true),
        ] : null;
    }

    public function skipReason(string $module, string $stage): ?string
    {
        return ((array) config('hani.sla.skip'))["{$module}.{$stage}"] ?? null;
    }

    /** @return array<int, string> module keys with at least one limit */
    public function modules(): array
    {
        return array_keys((array) config('hani.sla.stages', []));
    }

    /**
     * Every open application at a stage with a limit, longest wait first.
     *
     * @return Collection<int, array{application: Application, stage: string, entered_at: Carbon, days: int, working_days: bool, unit: string, thresholds: array<int, int>, level: int, limit: int}>
     */
    public function waiting(?string $department = null, ?CarbonInterface $today = null): Collection
    {
        $today = Carbon::parse($today ?? today())->startOfDay();

        return Application::query()
            ->with('student')
            ->withMax('history', 'created_at')
            ->whereIn('module_type', $this->modules())
            ->where('status', Application::STATUS_PENDING)
            ->whereNotNull('current_stage')
            ->when($department, fn ($q) => $q->whereHas('student', fn ($s) => $s->where('department', $department)))
            ->get()
            ->map(function (Application $application) use ($today) {
                $rule = $this->rule($application->module_type, $application->current_stage);

                if (! $rule || $rule['days'] === []) {
                    return null;
                }

                $entered = $this->enteredAt($application);
                $days = $rule['working_days']
                    ? WorkingDays::between($entered, $today)
                    : (int) max(0, $entered->copy()->startOfDay()->diffInDays($today, false));

                $level = 0;
                foreach ($rule['days'] as $i => $threshold) {
                    if ($days >= $threshold) {
                        $level = $i + 1;
                    }
                }

                return [
                    'application' => $application,
                    'stage' => $application->current_stage,
                    'entered_at' => $entered,
                    'days' => $days,
                    'working_days' => $rule['working_days'],
                    'unit' => $rule['working_days'] ? 'working days' : 'days',
                    'thresholds' => $rule['days'],
                    'level' => $level,
                    'limit' => $rule['days'][0],
                ];
            })
            ->filter()
            ->sortByDesc('days')
            ->values();
    }

    /** Only those past their first threshold. */
    public function overdue(?string $department = null, ?CarbonInterface $today = null): Collection
    {
        return $this->waiting($department, $today)->filter(fn ($row) => $row['level'] > 0)->values();
    }

    /** When the application reached its current stage. Needs withMax('history', 'created_at') or loads it. */
    public function enteredAt(Application $application): Carbon
    {
        $last = $application->history_max_created_at
            ?? $application->history()->max('created_at');

        return Carbon::parse($last ?? $application->submitted_at ?? $application->created_at);
    }
}
