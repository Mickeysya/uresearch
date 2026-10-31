<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Stage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * CGS's Client Charter -- config('nureen.charter_days') -- applied to one
 * application.
 *
 * Every figure the charter drives comes from here, counted on the one
 * Support\WorkingDayCalendar: the current stage's due date (approver
 * queues), the student's expected decision date (the Expected Decision
 * Dates page), whether a reviewer is due a reminder (the three
 * remind-stalled commands) and the overdue list and compliance figures
 * (Support\ProcessingTimeAnalytics). Nothing else does its own date
 * arithmetic, so none of them can disagree.
 *
 * One rule ties them together: a stage is OVERDUE once more working days
 * have passed since the application reached it than the stage's charter
 * allows -- in other words, from the first working day after its due date.
 * Days are counted from the first working day on or after arrival, so a
 * weekend arrival counts as Monday morning.
 * That same day is when its reviewer is first reminded.
 *
 * A stage whose charter is null has no due date and no expected decision
 * date; nothing is invented for it. Only the reminder commands still need
 * a number there, and they fall back to config('nureen.reminders.<module>.
 * stalled_days') -- see thresholdDays(). Approved, rejected, returned and
 * draft applications have no clock at all.
 */
class ClientCharter
{
    public function __construct(
        protected WorkflowEngine $engine,
        protected WorkingDayCalendar $calendar,
    ) {}

    /** The charter's working days for one stage, or null when CGS gives none. */
    public function charterDays(string $module, string $stageKey): ?int
    {
        $days = config("nureen.charter_days.{$module}.{$stageKey}");

        return $days === null ? null : (int) $days;
    }

    /**
     * The working days a stage may take before it counts as overdue and
     * its reviewer is reminded: the charter, or for a null-charter stage
     * the module's stalled_days fallback. $override (a remind-stalled
     * command's --days) replaces both.
     */
    public function thresholdDays(string $module, string $stageKey, ?int $override = null): int
    {
        return $override
            ?? $this->charterDays($module, $stageKey)
            ?? (int) config("nureen.reminders.{$module}.stalled_days");
    }

    /**
     * When the application reached the stage it is on now: the most recent
     * approval_history row (every decision and every return-to-supervisor
     * writes one, and each of those is what moved it), or submission when
     * nothing has been decided yet. submitted_at is compared too, because
     * WorkflowEngine::resubmit() restarts the clock by moving it forward
     * without writing history.
     */
    public function enteredStageAt(Application $application): ?CarbonImmutable
    {
        $latest = $application->relationLoaded('history')
            ? $application->history->max('created_at')
            : ApprovalHistory::where('application_id', $application->id)->max('created_at');

        $candidates = array_filter([
            $latest ? CarbonImmutable::parse($latest) : null,
            $application->submitted_at ? CarbonImmutable::instance($application->submitted_at) : null,
        ]);

        return $candidates === [] ? null : max($candidates);
    }

    /**
     * Where a pending application stands against its current stage, or null
     * when it is not pending.
     *
     * `due_date` is by the THRESHOLD (charter or fallback), which is what
     * reminders and the approver queue go by; `on_charter` says whether
     * that threshold is a real charter figure. Anything shown to a student
     * must check `on_charter` first -- see expectedDecision().
     *
     * @return array{
     *     stage: Stage,
     *     entered_at: CarbonImmutable,
     *     charter_days: ?int,
     *     threshold_days: int,
     *     on_charter: bool,
     *     due_date: CarbonImmutable,
     *     working_days_taken: int,
     *     overdue: bool,
     *     working_days_overdue: int,
     * }|null
     */
    public function stageClock(Application $application, ?int $overrideDays = null): ?array
    {
        $stage = $this->engine->currentStage($application);
        $enteredAt = $this->enteredStageAt($application);

        if (! $stage || ! $enteredAt) {
            return null;
        }

        $charterDays = $this->charterDays($application->module_type, $stage->key);
        $threshold = $this->thresholdDays($application->module_type, $stage->key, $overrideDays);

        // The clock starts on the first working day the stage can act: an
        // application reaching it on a Saturday is treated as arriving on
        // Monday. Without this a 0-day ("same working day") stage reached
        // on a Sunday showed a Sunday due date.
        $anchor = $this->calendar->onOrAfter($enteredAt);
        $taken = $this->calendar->workingDaysBetween($anchor, now());

        return [
            'stage' => $stage,
            'entered_at' => $enteredAt,
            'charter_days' => $charterDays,
            'threshold_days' => $threshold,
            'on_charter' => $overrideDays === null && $charterDays !== null,
            'due_date' => $this->calendar->addWorkingDays($anchor, $threshold),
            'working_days_taken' => $taken,
            'overdue' => $taken > $threshold,
            'working_days_overdue' => max(0, $taken - $threshold),
        ];
    }

    /**
     * What a student is told about when to expect an outcome, or null when
     * the application is not pending.
     *
     * The expected date is the current stage's due date -- or today, if
     * that has already passed (moved on to the next working day when today
     * is a weekend or holiday) -- plus the
     * charter days of every stage still ahead on THIS application's own
     * chain (its mode snapshot decides which stages those are, and a
     * return-to-supervisor simply puts it back at an earlier position).
     * So an overdue application never shows a date in the past. If the
     * current stage or any stage ahead has no charter figure,
     * `expected_date` is null and `untimed_stage` names the stage
     * responsible, rather than a date being made up.
     *
     * @return array{
     *     clock: array,
     *     stage_due_date: ?CarbonImmutable,
     *     expected_date: ?CarbonImmutable,
     *     total_charter_days: ?int,
     *     untimed_stage: ?Stage,
     * }|null
     */
    public function expectedDecision(Application $application): ?array
    {
        $clock = $this->stageClock($application);

        if (! $clock) {
            return null;
        }

        $stages = $this->engine->stagesFor($application);
        $keys = array_map(fn (Stage $s) => $s->key, $stages);
        $remaining = array_slice($stages, array_search($clock['stage']->key, $keys, true) + 1);

        $untimed = $clock['on_charter'] ? null : $clock['stage'];
        $remainingDays = 0;

        foreach ($remaining as $stage) {
            $days = $this->charterDays($application->module_type, $stage->key);

            if ($days === null) {
                $untimed ??= $stage;
            } else {
                $remainingDays += $days;
            }
        }

        $expected = null;

        if (! $untimed) {
            $today = CarbonImmutable::now()->startOfDay();
            $from = $clock['due_date']->gte($today) ? $clock['due_date'] : $today;
            $expected = $this->calendar->addWorkingDays($this->calendar->onOrAfter($from), $remainingDays);
        }

        $total = 0;
        foreach ($stages as $stage) {
            $days = $this->charterDays($application->module_type, $stage->key);
            if ($days === null) {
                $total = null;
                break;
            }
            $total += $days;
        }

        return [
            'clock' => $clock,
            'stage_due_date' => $clock['on_charter'] ? $clock['due_date'] : null,
            'expected_date' => $expected,
            'total_charter_days' => $total,
            'untimed_stage' => $untimed,
        ];
    }

    /**
     * Whether the current stage's reviewer should be reminded now.
     *
     * The first reminder goes out the first working day the stage is
     * overdue. After that, the existing repeat rule: once another full
     * threshold's worth of working days has passed since the last one (at
     * least one working day, so a 0-day stage is not reminded twice in a
     * day). A reminder from a different stage, or from an earlier visit to
     * this one before a return-to-supervisor, does not count.
     */
    public function dueForReminder(
        Application $application,
        ?CarbonInterface $lastRemindedAt,
        ?string $remindedStageKey,
        ?int $overrideDays = null,
    ): bool {
        $clock = $this->stageClock($application, $overrideDays);

        if (! $clock || ! $clock['overdue']) {
            return false;
        }

        if (! $this->remindedDuringThisStage($clock, $lastRemindedAt, $remindedStageKey)) {
            return true;
        }

        return $this->calendar->workingDaysBetween($lastRemindedAt, now()) >= max(1, $clock['threshold_days']);
    }

    /**
     * True when the last reminder was about the stage the application is on
     * now, during this visit to it -- what decides whether a reminder count
     * carries on or starts again at 1.
     */
    public function remindedDuringThisStage(array $clock, ?CarbonInterface $lastRemindedAt, ?string $remindedStageKey): bool
    {
        return $lastRemindedAt
            && $remindedStageKey === $clock['stage']->key
            && $lastRemindedAt->gte($clock['entered_at']);
    }

    /**
     * Orders an approver queue by when each application reached its stage,
     * earliest first. Every row in one queue sits on the same stage with
     * the same threshold, and a due date only moves later as the entry date
     * does -- so this puts every overdue row first, longest overdue at the
     * top, without having to compute a due date in SQL.
     *
     * Same "latest history row, else submission" rule as enteredStageAt();
     * written as COALESCE because none of Nureen's three modules use
     * WorkflowEngine::resubmit(), so submitted_at is never later than the
     * newest history row here.
     */
    public function orderByStageEntry(Builder $query): void
    {
        $query->reorder()
            ->orderByRaw('COALESCE((SELECT MAX(approval_history.created_at) FROM approval_history WHERE approval_history.application_id = applications.id), applications.submitted_at) ASC')
            ->orderBy('applications.id');
    }
}
