<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\ReVivaDetail;
use Illuminate\Support\Carbon;

/**
 * A rule-based deadline risk score, 0 to 100, for an open re-viva round.
 * Not a prediction: four factors, each with a weight from
 * hani.reviva.risk.weights (provisional), summed and shown with the reason
 * for every point, so a person can see why a round is at the top.
 *
 *   time_used        share of the round's current deadline already used:
 *                    before the outcome, the expected length of a round in
 *                    working days; after it, the final-thesis deadline
 *                    (levels 1-3) or the next round's window (level 4)
 *   stage_time       working days in the current stage against its limit
 *                    (hani.reviva.risk.stage_limits)
 *   divergence       the two re-evaluations disagree (major counts fully)
 *   previous_rounds  rounds before this one
 *
 * Every factor is capped at its weight. Closed rounds have no score.
 */
final class ReVivaRisk
{
    /**
     * @return array{score: int, band: string, factors: array<int, array{key: string, label: string, points: int, max: int, detail: string}>}|null
     */
    public static function for(ReVivaDetail $detail): ?array
    {
        if (! self::isOpen($detail)) {
            return null;
        }

        $weights = (array) config('hani.reviva.risk.weights');
        $today = today();
        $factors = [];

        [$from, $to, $what] = self::deadline($detail);
        $share = self::share($from, $to, $today);
        $factors[] = self::factor('time_used', 'Time used', $share, $weights['time_used'] ?? 35,
            sprintf('%d%% of %s used', (int) round($share * 100), $what).($share >= 1 ? ' (overdue)' : ''));

        [$stageDays, $limit, $stageLabel] = self::stageTime($detail);
        $factors[] = self::factor('stage_time', 'Time in stage', $limit ? min(1, $stageDays / $limit) : 0, $weights['stage_time'] ?? 30,
            $limit ? "{$stageDays} of {$limit} working days at {$stageLabel}" : 'No stage clock after the outcome');

        $divergence = ExaminerDivergence::betweenReEvaluations($detail->currentEvaluations());
        $factors[] = self::factor('divergence', 'Examiner divergence', match ($divergence['level'] ?? null) {
            'major' => 1.0, 'minor' => 0.5, default => 0.0,
        }, $weights['divergence'] ?? 20, $divergence ? ucfirst($divergence['level']).' divergence between the re-evaluations' : 'Re-evaluations agree, or not both recorded');

        $previous = max(0, $detail->cycle_number - 1);
        $factors[] = self::factor('previous_rounds', 'Previous rounds', min(1, $previous / max(1, (int) config('hani.reviva.risk.rounds_for_full', 2))), $weights['previous_rounds'] ?? 15,
            $previous ? "{$previous} earlier ".($previous === 1 ? 'round' : 'rounds') : 'First round');

        $score = min(100, array_sum(array_column($factors, 'points')));

        return [
            'score' => $score,
            'band' => $score >= (int) config('hani.reviva.risk.high', 70) ? 'high' : ($score >= (int) config('hani.reviva.risk.medium', 40) ? 'medium' : 'low'),
            'factors' => $factors,
        ];
    }

    /** Still running: no outcome, a level 1-3 thesis not accepted, or a level-4 round with no next one. */
    public static function isOpen(ReVivaDetail $detail): bool
    {
        if (! $detail->hasOutcome()) {
            return $detail->application?->status !== Application::STATUS_REJECTED;
        }

        return ($detail->needsFinalThesis() && $detail->corrections_accepted_at === null)
            || ($detail->requiresLoopBack() && ! $detail->nextCycle);
    }

    /** @return array{0: Carbon, 1: Carbon, 2: string} */
    protected static function deadline(ReVivaDetail $detail): array
    {
        if ($detail->needsFinalThesis() && $detail->correction_deadline) {
            return [Carbon::parse($detail->outcome_recorded_at ?? $detail->updated_at), $detail->correction_deadline, 'the time to the final-thesis deadline'];
        }

        if ($detail->requiresLoopBack()) {
            return [Carbon::parse($detail->outcome_recorded_at ?? $detail->updated_at), ReVivaOutcome::nextRoundCloses($detail), 'the next round\'s window'];
        }

        $logged = Carbon::parse($detail->resubmission_at);

        return [$logged, WorkingDays::add($logged, (int) config('hani.reviva.risk.expected_round_working_days', 45)), 'the expected length of a round'];
    }

    protected static function share(Carbon $from, Carbon $to, Carbon $today): float
    {
        $total = WorkingDays::between($from, $to);
        if ($total <= 0) {
            return $today->gte($to) ? 1.0 : 0.0;
        }

        return min(1.0, WorkingDays::between($from, $today) / $total);
    }

    /** @return array{0: int, 1: ?int, 2: string} working days in the current stage, its limit, its name */
    protected static function stageTime(ReVivaDetail $detail): array
    {
        if ($detail->hasOutcome()) {
            return [0, null, ''];
        }

        $application = $detail->application;
        $limits = (array) config('hani.reviva.risk.stage_limits');
        $stage = $application->status === Application::STATUS_APPROVED ? 'outcome' : $application->current_stage;
        $labels = [
            'report_sent' => 'Report Sent', 'under_panel_review' => 'Under Panel Review',
            'report_received' => 'Report Received', 'consolidation_scheduled' => 'Consolidation Scheduled', 'outcome' => 'awaiting the outcome',
        ];

        $entered = match ($stage) {
            'report_sent' => $application->submitted_at ?? $detail->resubmission_at,
            'under_panel_review' => $detail->sent_to_panel_at,
            // The latest decision is when the round entered this stage.
            default => $application->history->sortByDesc('created_at')->first()?->created_at,
        } ?? $detail->resubmission_at;

        return [WorkingDays::between(Carbon::parse($entered), today()), isset($limits[$stage]) ? (int) $limits[$stage] : null, $labels[$stage] ?? $stage];
    }

    protected static function factor(string $key, string $label, float $share, int $max, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'points' => (int) round(max(0, min(1, $share)) * $max), 'max' => $max, 'detail' => $detail];
    }
}
