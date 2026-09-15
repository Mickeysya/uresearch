<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Models\Candidacy;
use App\Modules\Norhanis\Models\RpdDismissalDetail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;
use Illuminate\Validation\ValidationException;

/**
 * Dismissal for exceeded candidacy — norhanis.md Module 4, path 3.
 *
 * Opened by Non-Executive CGS against a student who passed their deadline
 * without an approved appeal, then Dean → Faculty → Registry.
 *
 * Two things differ from every other module in the project:
 *
 *   1. The student is the SUBJECT, not the submitter. `applications.student_id`
 *      names the person being dismissed; `initiated_by` names the CGS officer
 *      who opened the case.
 *   2. The final stage does real work. When the Registry approves, the
 *      candidacy is marked dismissed and the termination is timestamped.
 */
class RpdDismissalController extends Controller
{
    use ApprovesApplications;

    protected function moduleKey(): string
    {
        return 'rpd_dismissal';
    }

    /** The CGS entry point: every overdue candidacy with no dismissal open yet. */
    public function create()
    {
        $open = RpdDismissalDetail::whereIn(
            'application_id',
            Application::where('module_type', $this->moduleKey())
                ->where('status', Application::STATUS_PENDING)
                ->pluck('id')
        )->pluck('candidacy_id');

        $eligible = Candidacy::with('student')
            ->whereIn('status', [Candidacy::STATUS_ACTIVE, Candidacy::STATUS_EXTENDED])
            ->whereDate('rpd_deadline', '<', now()->startOfDay())
            ->whereNotIn('id', $open)
            ->orderBy('rpd_deadline')
            ->get();

        return view('norhanis::rpd_dismissal.form', ['eligible' => $eligible]);
    }

    public function store(Request $request, WorkflowEngine $engine)
    {
        $data = $request->validate([
            'candidacy_id' => ['required', 'integer', 'exists:candidacies,id'],
            'grounds' => ['required', 'string', 'min:40', 'max:2000'],
        ], [
            'grounds.min' => 'State the grounds in enough detail for the Dean and Faculty to act on.',
        ]);

        $candidacy = Candidacy::with('student')->findOrFail($data['candidacy_id']);

        // Re-checked server-side: the picker only lists eligible rows, but the
        // form can be posted directly, and dismissing a student whose deadline
        // has not passed is the single most damaging thing this module can do.
        if (! $candidacy->isDismissible()) {
            throw ValidationException::withMessages([
                'candidacy_id' => $candidacy->isOverdue()
                    ? 'That candidacy is already '.$candidacy->status.'.'
                    : 'That student has not passed their RPD deadline ('.$candidacy->rpd_deadline->format('j M Y').').',
            ]);
        }

        $application = DB::transaction(function () use ($request, $data, $candidacy, $engine) {
            $application = Application::create([
                // The student being dismissed, NOT the CGS officer filing it.
                'student_id' => $candidacy->student_id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            RpdDismissalDetail::create([
                'application_id' => $application->id,
                'candidacy_id' => $candidacy->id,
                'initiated_by' => $request->user()->id,
                'deadline_missed_on' => $candidacy->rpd_deadline,
                'grounds' => $data['grounds'],
            ]);

            return $engine->submit($application);
        });

        return redirect()
            ->route('candidacies.index')
            ->with('status', "Dismissal #{$application->id} opened for {$candidacy->student->name}. The Dean of PGR has been notified.");
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents', 'student']);

        $details = RpdDismissalDetail::with(['candidacy', 'initiator'])
            ->whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        return view('norhanis::rpd_dismissal.queue', $queue + ['details' => $details]);
    }

    /**
     * Approve or reject — and on the Registry's approval, close the candidacy.
     *
     * A rejection anywhere in the chain leaves the candidacy untouched and
     * still overdue, which is correct: the Dean declining to endorse a
     * dismissal does not grant the student more time, it just means no
     * dismissal. CGS can open a new one, or the student can still appeal.
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $decided = DB::transaction(function () use ($application, $request, $data, $engine) {
                $decided = $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);

                if ($decided->status === Application::STATUS_APPROVED) {
                    $this->closeCandidacy($decided);
                }

                return $decided;
            });
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application has already been decided.');
        }

        if ($decided->status === Application::STATUS_APPROVED) {
            return back()->with('status', "Dismissal #{$decided->id} completed. The candidacy is now closed and the student has been notified.");
        }

        $verb = $data['decision'] === 'approve' ? 'endorsed' : 'rejected';

        return back()->with('status', "Dismissal #{$decided->id} {$verb}.");
    }

    protected function closeCandidacy(Application $application): void
    {
        $detail = RpdDismissalDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return;
        }

        Candidacy::where('id', $detail->candidacy_id)
            ->update(['status' => Candidacy::STATUS_DISMISSED]);

        // A fact on the record rather than something inferred from the status:
        // "when was this student actually told" is the question Registry gets
        // asked, and status alone cannot answer it.
        $detail->update(['terminated_at' => now()]);
    }
}
