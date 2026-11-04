<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
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
 * the Academic Executive, and everything that follows from it. Module-side
 * state; never applications.status (the chain already finished).
 *
 *   suggestion   both examiners map to the same level -> that level;
 *                otherwise "Consolidation needed", showing both
 *                (1 -> 1, 2 -> 2, 3 -> 3, 4 + viva YES -> 4, 4 + viva NO -> 5)
 *   levels 1-3   final thesis due 1 / 3 / 6 months from the outcome date
 *                (hani.reviva.final_thesis_months); the mains' evaluation is
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
                throw ValidationException::withMessages(['outcome_level' => 'The outcome is recorded once consolidation is complete.']);
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
                    ? $today->copy()->addMonthsNoOverflow((int) config("hani.reviva.final_thesis_months.{$level}"))
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

        $lines = match (true) {
            $detail->needsFinalThesis() => [
                "The outcome of {$student->name}'s re-viva (round {$round}) has been recorded: ".$detail->outcomeLabel().'.',
                'The final thesis is due in UResearch by '.$detail->correction_deadline->format('j M Y').'.',
            ],
            $level === ReVivaDetail::OUTCOME_LOOP_BACK => [
                "The outcome of {$student->name}'s re-viva (round {$round}) has been recorded: ".$detail->outcomeLabel().'.',
                'An oral examination is required. Submit the corrected thesis to CGS by '.self::nextRoundCloses($detail)->format('j M Y').' for re-viva round '.($round + 1).'.',
            ],
            default => [
                "The outcome of {$student->name}'s re-viva (round {$round}) has been recorded: ".$detail->outcomeLabel().'.',
                'This is the final outcome. Contact your Academic Executive if you have questions.',
            ],
        };

        $this->tell(array_filter([$student, $student->supervisor]), new ReVivaUpdate(
            'outcome', $application, 'Re-viva outcome: '.$student->name, $lines,
            'View the re-viva', route('applications.show', $application),
        ));

        if ($level === ReVivaDetail::OUTCOME_LOOP_BACK) {
            $this->tell(User::where('role', Role::SENIOR_EXEC_CGS)->get(), new ReVivaUpdate(
                'next_round_to_log', $application, 'Re-viva round '.($round + 1).' to log: '.$student->name,
                [
                    "The outcome of {$student->name}'s re-viva round {$round} is level 4: an oral examination is required.",
                    'Please log round '.($round + 1).' when the corrected thesis arrives, by '.self::nextRoundCloses($detail)->format('j M Y').'. The same main examiners stay assigned.',
                ],
                'Log round '.($round + 1), route('reviva.create', ['student' => $student->id]),
            ));
        }
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
