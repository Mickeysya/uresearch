<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Models\ReVivaEvaluation;
use App\Modules\Hani\Notifications\ReVivaUpdate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The re-viva outcome: suggested from the two re-evaluations, confirmed by
 * the Senior Executive, and everything that follows from it. Module-side
 * state; never applications.status (the chain already finished).
 *
 *   suggestion   both examiners map to the same level -> that level;
 *                otherwise "Consolidation needed", showing both
 *                (1 -> 1, 2 -> 2, 3 -> 3, 4 + viva YES -> 4, 4 + viva NO -> 5)
 *   levels 1-3   final thesis window from the outcome date: within 1 month,
 *                1 to 3 months, 3 to 6 months (hani.reviva.final_window_months;
 *                correction_deadline holds the maximum); the mains' evaluation is
 *                complete, so their 90-day gap starts
 *   level 4      an oral examination again: the mains stay held until the
 *                next round is logged or its window closes; the Senior
 *                Executive is asked to log round N+1
 *   level 5      final; the mains' evaluation is complete
 *
 * The student and supervisor are told the level and what to do next, never
 * an examiner's name or individual category.
 */
class ReVivaOutcome
{
    public function __construct(protected PanelSeats $seats) {}

    /**
     * @return array{level: ?int, evaluations: Collection<string, ReVivaEvaluation>, text: string}
     */
    public static function suggest(ReVivaDetail $detail): array
    {
        $evaluations = $detail->currentEvaluations();
        $internal = $evaluations->get('internal_main_id');
        $external = $evaluations->get('external_main_id');

        if (! $internal || ! $external) {
            return ['level' => null, 'evaluations' => $evaluations, 'text' => 'Both re-evaluations are not recorded, so there is nothing to suggest from.'];
        }

        if ($internal->level() === $external->level()) {
            return ['level' => $internal->level(), 'evaluations' => $evaluations,
                'text' => 'Both examiners recommend '.ReVivaEvaluation::CATEGORIES[$internal->category][0]
                    .($internal->category === ReVivaEvaluation::FAIL ? ($internal->viva_again ? ', with the viva again' : ', without the viva again') : '')
                    .', which is '.ReVivaDetail::OUTCOME_LABELS[$internal->level()].'.'];
        }

        return ['level' => null, 'evaluations' => $evaluations,
            'text' => 'Consolidation needed: Internal '.$internal->label().' (level '.$internal->level().') · External '.$external->label().' (level '.$external->level().').'];
    }

    public function record(ReVivaDetail $detail, int $level, ?string $remarks, User $actor): ReVivaDetail
    {
        $detail = DB::transaction(function () use ($detail, $level, $remarks, $actor) {
            $locked = ReVivaDetail::with('application')->whereKey($detail->id)->lockForUpdate()->firstOrFail();

            if ($locked->hasOutcome()) {
                throw ValidationException::withMessages(['outcome_level' => 'An outcome is already recorded for this round.']);
            }
            if ($locked->application->status !== Application::STATUS_APPROVED) {
                throw ValidationException::withMessages(['outcome_level' => 'The outcome is recorded when the review is complete.']);
            }

            $suggested = self::suggest($locked)['level'];

            if (($suggested === null || $suggested !== $level) && blank($remarks)) {
                throw ValidationException::withMessages(['outcome_remarks' => $suggested === null
                    ? 'The examiners differ: record how the consolidation reached this level.'
                    : 'You chose a level other than the one both examiners recommend: say why.']);
            }

            $today = now()->startOfDay();
            $locked->fill([
                'outcome_level' => $level,
                'outcome_remarks' => $remarks,
                'suggested_level' => $suggested,
                'outcome_recorded_at' => now(),
                'outcome_recorded_by_id' => $actor->id,
                'correction_deadline' => in_array($level, ReVivaDetail::OUTCOME_CORRECTIONS, true)
                    ? $today->copy()->addMonthsNoOverflow(ReVivaDetail::finalWindowMonths($level)[1])
                    : null,
            ])->save();

            // Examiner housekeeping: this is what releases the hold set when
            // the viva result was category 4.
            if ($panel = $locked->panel()) {
                $level === ReVivaDetail::OUTCOME_LOOP_BACK
                    ? $this->seats->holdForReexamination($panel, self::nextRoundCloses($locked)->endOfDay(), $actor)
                    : $this->seats->complete($panel, $actor);
            }

            activity('re_viva')->causedBy($actor)->performedOn($locked->application)
                ->withProperties(['action' => 'outcome_recorded', 'level' => $level, 'suggested' => $suggested])
                ->log('Re-viva outcome recorded');

            return $locked;
        });

        $this->notify($detail);

        return $detail;
    }

    /** Level 4: when the next round's corrected thesis is due by. */
    public static function nextRoundCloses(ReVivaDetail $detail): Carbon
    {
        $months = (array) config('hani.reviva.next_round_window_months', [0, 6]);

        return Carbon::parse($detail->outcome_recorded_at ?? now())->startOfDay()->addMonthsNoOverflow((int) ($months[1] ?? 6));
    }

    protected function notify(ReVivaDetail $detail): void
    {
        $application = $detail->application;
        $student = $application->student;
        $level = $detail->outcome_level;
        $round = $detail->cycle_number;

        [$subject, $lines] = match (true) {
            $detail->needsFinalThesis() => ['Re-viva result: Passed', [
                "We are pleased to inform you that you have passed the re-examination (round {$round}): ".$detail->outcomeLabel().'.',
                'The final thesis must be submitted in UResearch '.$detail->finalWindowText('j F Y').'.',
                'You can upload it from '.$detail->finalOpensOn()->format('j F Y').'. After '.$detail->correction_deadline->format('j F Y').' it is overdue.',
            ]],
            $level === ReVivaDetail::OUTCOME_LOOP_BACK => ['Re-viva result: another round required', [
                "The result of re-viva round {$round} is: ".$detail->outcomeLabel().'.',
                'A further round is required. Please submit the re-corrected thesis, Summary of Corrections and Confirmation of Correction in UResearch by '.self::nextRoundCloses($detail)->format('j F Y').' for round '.($round + 1).'.',
            ]],
            default => ['Re-viva result: Dismissed', [
                "We regret to inform you that the result of re-viva round {$round} is: ".$detail->outcomeLabel().'.',
                'This is the final outcome and no further action is available.',
            ]],
        };

        $this->tell(array_filter([$student, $student->supervisor]), new ReVivaUpdate(
            'outcome', $application, $subject, $lines, 'View the re-viva', route('applications.show', $application),
        ));
    }

    /** Best effort, after the commit: a mail failure never undoes the outcome. */
    protected function tell(iterable $users, ReVivaUpdate $notification): void
    {
        try {
            Notification::send(collect($users)->filter(), $notification);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
