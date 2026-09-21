<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Norhanis\Models\Candidacy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Records the outcome of an RPD attempt reaching CGS from the AE -- not a
 * WorkflowModule, because there is no approver here, just CGS entering a
 * result. Department-level RPD assessment scheduling and examiner
 * coordination are explicitly outside this module's scope (norhanis.md
 * §4 "Excluded AE Processes"), so this is the point where that manual
 * process re-enters the system: whoever CGS hears the result from, this is
 * where it gets recorded against the candidacy.
 *
 * A failed attempt does not touch applications/WorkflowEngine at all -- it
 * is a `candidacies` state change only, same as the appeal/dismissal
 * controllers' post-approval updates to this table.
 */
class CandidacyController extends Controller
{
    /** Candidacies that have attempted and could plausibly need a result recorded. */
    public function create()
    {
        return view('norhanis::candidacies.failed_attempt', [
            'candidacies' => $this->recordableCandidacies(),
        ]);
    }

    public function recordFailedAttempt(Request $request, Candidacy $candidacy): RedirectResponse
    {
        $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        abort_unless(
            $this->recordableCandidacies()->contains('id', $candidacy->id),
            404,
            'That candidacy is not eligible to be recorded as failed right now.'
        );

        $candidacy->update([
            'status' => Candidacy::STATUS_FAILED_AWAITING_RESUBMISSION,
            'resubmission_deadline' => Candidacy::computeResubmissionDeadline(
                now(),
                $candidacy->programme,
                $candidacy->study_mode,
            ),
            'attempt_number' => $candidacy->attempt_number + 1,
        ]);

        return redirect()
            ->route('candidacy.failed-attempt.create')
            ->with('status', "Recorded a failed RPD attempt for {$candidacy->student->name}. Resubmission due {$candidacy->fresh()->resubmission_deadline->format('j M Y')}.");
    }

    /**
     * Active, or already on a resubmission attempt -- either can fail again,
     * which is why this isn't restricted to STATUS_ACTIVE alone: a second
     * (or later) failure recomputes resubmission_deadline the same way as
     * the first and bumps attempt_number again. A dismissed or completed
     * candidacy has nothing left to record.
     */
    protected function recordableCandidacies()
    {
        return Candidacy::whereIn('status', [
            Candidacy::STATUS_ACTIVE,
            Candidacy::STATUS_FAILED_AWAITING_RESUBMISSION,
        ])
            ->with('student')
            ->orderBy('deadline')
            ->get();
    }
}
