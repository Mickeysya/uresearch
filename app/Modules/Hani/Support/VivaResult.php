<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Notifications\VivaAwaitingAcademicExec;
use App\Modules\Hani\Notifications\VivaOutcome;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Step 5 of the viva: the result, the corrections, and what both do to the
 * examiners. Module-side state on viva_details; never a stage, never
 * applications.status.
 *
 *   category 1-3  corrections due 1 / 3 / 6 months after the viva; the mains'
 *                 evaluation is complete (their 90-day gap starts)
 *   category 4    re-examination: the window opens at +6 and closes at +12
 *                 months; the mains stay Assigned for the re-viva, and the
 *                 Senior Executive is asked to log it
 *   category 5    fail: final; the mains' evaluation is complete
 */
class VivaResult
{
    public function __construct(protected PanelSeats $seats, protected DocumentStore $documents) {}

    public function record(Application $notice, int $category, UploadedFile $report, User $actor): VivaDetail
    {
        $progress = VivaProgress::forNotices(collect([$notice]))->first();
        $panel = $progress->panel();

        $detail = DB::transaction(function () use ($notice, $category, $report, $actor, $panel) {
            $detail = VivaDetail::where('application_id', $notice->id)->lockForUpdate()->firstOrFail();

            if ($detail->hasResult()) {
                throw ValidationException::withMessages(['result_category' => 'A result is already recorded for this viva.']);
            }
            if (! $detail->vivaHeld()) {
                throw ValidationException::withMessages(['result_category' => 'The result can be recorded only after the viva has taken place.']);
            }

            $viva = $detail->viva_at->copy()->startOfDay();
            $fill = [
                'result_category' => $category,
                'result_recorded_at' => now(),
                'result_recorded_by_id' => $actor->id,
            ];

            if (in_array($category, VivaDetail::CORRECTIONS, true)) {
                $fill['corrections_due_at'] = $viva->copy()->addMonthsNoOverflow((int) config("hani.result.correction_months.{$category}"));
            } elseif ($category === VivaDetail::REEXAMINATION) {
                $fill['resubmission_opens_at'] = $viva->copy()->addMonthsNoOverflow((int) config('hani.result.resubmission_opens_months', 6));
                $fill['resubmission_closes_at'] = $viva->copy()->addMonthsNoOverflow((int) config('hani.result.resubmission_closes_months', 12));
            }

            $detail->fill($fill)->save();

            // The chairman's viva report: the student is meant to see this one.
            $this->documents->attach($notice, $report, 'Viva Report');

            // Examiner housekeeping.
            if ($panel) {
                $category === VivaDetail::REEXAMINATION
                    ? $this->seats->holdForReexamination($panel, $detail->resubmission_closes_at->copy()->endOfDay(), $actor)
                    : $this->seats->complete($panel, $actor);
            }

            activity('viva')->causedBy($actor)->performedOn($notice)
                ->withProperties(['action' => 'result_recorded', 'category' => $category])
                ->log('Viva result recorded');

            return $detail;
        });

        $this->tellStudent($notice, $detail);

        if ($category === VivaDetail::REEXAMINATION) {
            $this->tell(User::where('role', Role::SENIOR_EXEC_CGS)->get(), new VivaOutcome(
                'reviva_to_log', $notice,
                'Re-viva to log: '.$notice->student?->name,
                [
                    "{$notice->student?->name}'s viva result is category 4 (re-examination). The candidate resubmits between {$detail->resubmission_opens_at->format('j M Y')} and {$detail->resubmission_closes_at->format('j M Y')}.",
                    'Please log the re-viva when the corrected thesis arrives. The same main examiners stay assigned for it.',
                ],
                'Open Re-viva Cycles', route('reviva.cycles.index'),
            ));
        }

        return $detail;
    }

    /** The student uploads the corrected final thesis (categories 1-3). */
    public function submitCorrections(Application $notice, UploadedFile $thesis, User $student): VivaDetail
    {
        $detail = DB::transaction(function () use ($notice, $thesis) {
            $detail = VivaDetail::where('application_id', $notice->id)->lockForUpdate()->firstOrFail();

            if (! $detail->needsCorrections() || $detail->corrections_accepted_at !== null) {
                throw ValidationException::withMessages(['corrected_thesis' => 'There are no corrections to submit for this viva.']);
            }

            $this->documents->attach($notice, $thesis, 'Corrected Final Thesis');
            $detail->update(['corrections_submitted_at' => now()]);

            return $detail;
        });

        VivaProgress::notifyAcademicExecs($student, new VivaAwaitingAcademicExec($notice, 'corrected final thesis', 'viva.results.index'));

        return $detail;
    }

    /** The AE accepts the corrections: the viva tracker completes. */
    public function acceptCorrections(Application $notice, User $actor): VivaDetail
    {
        $detail = DB::transaction(function () use ($notice, $actor) {
            $detail = VivaDetail::where('application_id', $notice->id)->lockForUpdate()->firstOrFail();

            if (! $detail->needsCorrections() || $detail->corrections_submitted_at === null) {
                throw ValidationException::withMessages(['corrections' => 'The student has not submitted corrections yet.']);
            }
            if ($detail->corrections_accepted_at !== null) {
                throw ValidationException::withMessages(['corrections' => 'These corrections were already accepted.']);
            }

            $detail->update(['corrections_accepted_at' => now(), 'corrections_accepted_by_id' => $actor->id]);

            return $detail;
        });

        $this->tell([$notice->student], new VivaOutcome(
            'corrections_accepted', $notice,
            'Corrections accepted',
            ['Your Academic Executive has accepted your corrected final thesis. Your viva is complete.'],
            'Open your viva page', route('viva.eligibility'),
        ));

        return $detail;
    }

    protected function tellStudent(Application $notice, VivaDetail $detail): void
    {
        $category = $detail->result_category;

        $lines = match (true) {
            in_array($category, VivaDetail::CORRECTIONS, true) => [
                'Your viva result has been recorded: category '.$detail->resultLabel().'.',
                'Submit your corrected final thesis in UResearch by '.$detail->corrections_due_at->format('j M Y').'. The chairman\'s viva report is on your viva page.',
            ],
            $category === VivaDetail::REEXAMINATION => [
                'Your viva result has been recorded: category '.$detail->resultLabel().'.',
                'Revise your thesis following the chairman\'s viva report (on your viva page) and resubmit it to CGS between '
                    .$detail->resubmission_opens_at->format('j M Y').' and '.$detail->resubmission_closes_at->format('j M Y').' for re-examination.',
            ],
            default => [
                'Your viva result has been recorded: category '.$detail->resultLabel().'. This is the final outcome.',
                'The chairman\'s viva report is on your viva page. Contact your Academic Executive if you have questions.',
            ],
        };

        $this->tell([$notice->student], new VivaOutcome('result_recorded', $notice, 'Your viva result', $lines, 'Open your viva page', route('viva.eligibility')));
    }

    /** Best effort, after the commit: a mail failure never undoes the action. */
    protected function tell(iterable $users, VivaOutcome $notification): void
    {
        try {
            Notification::send(collect($users)->filter(), $notification);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
