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

/**
 * RPD Dismissal is initiated by Non-Exec CGS, not the student -- there is no
 * "New Application" form here. This controller's create()/store() pair is
 * the CGS-only replacement: a list of candidacies past deadline to pick
 * from, and the action that turns one of them into an Application.
 */
class RpdDismissalController extends Controller
{
    use ApprovesApplications;

    protected function moduleKey(): string
    {
        return 'rpd_dismissal';
    }

    /** Candidacies CGS can initiate a dismissal case for right now. */
    public function create()
    {
        return view('norhanis::rpd_dismissal.form', ['candidacies' => $this->initiableCandidacies()]);
    }

    public function store(Request $request, WorkflowEngine $engine): RedirectResponse
    {
        $data = $request->validate([
            'candidacy_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        // Re-derived from the same eligibility rules the list itself uses --
        // never trust a posted candidacy_id past that gate. A candidacy that
        // appealed, was extended, or already has a case in progress since the
        // page was loaded is refused here too.
        $candidacy = $this->initiableCandidacies()->firstWhere('id', (int) $data['candidacy_id']);

        abort_unless($candidacy, 404, 'That candidacy is not eligible for dismissal right now.');

        $application = DB::transaction(function () use ($request, $data, $candidacy, $engine) {
            $application = Application::create([
                'student_id' => $candidacy->student_id,
                'submitted_by_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            RpdDismissalDetail::create([
                'application_id' => $application->id,
                'candidacy_id' => $candidacy->id,
                'reason' => $data['reason'],
            ]);

            return $engine->submit($application);
        });

        return redirect()
            ->route('rpd-dismissal.create')
            ->with('status', "Dismissal case #{$application->id} initiated for {$candidacy->student->name}.");
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine);

        $details = RpdDismissalDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->with('candidacy.student')
            ->get()
            ->keyBy('application_id');

        return view('norhanis::rpd_dismissal.queue', $queue + ['details' => $details]);
    }

    /**
     * Overrides the trait's decide(): Faculty holds the final stage. Faculty's
     * approval is the moment the candidacy is actually terminated -- Registry
     * is not a stage in this chain (see RpdDismissalWorkflow's docblock), it
     * just issues the termination notice as a post-approval action, which the
     * generic ApplicationDecided email (fired by WorkflowEngine::decide() on
     * every decision) already covers. So there is nothing to dispatch here --
     * only the candidacy record to close out, which is a `candidacies.status`
     * write WorkflowEngine cannot make for us (it only ever touches
     * `applications.status`).
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $stage = $engine->currentStage($application);

        try {
            $decided = $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application has already been decided.');
        }

        if ($stage?->key === 'faculty' && $decided->status === Application::STATUS_APPROVED) {
            RpdDismissalDetail::where('application_id', $decided->id)->first()
                ?->candidacy()->update(['status' => Candidacy::STATUS_DISMISSED]);
        }

        $verb = $data['decision'] === 'approve' ? 'approved' : 'rejected';

        return back()->with('status', "Application #{$application->id} {$verb}.");
    }

    /**
     * Candidacy::scopeOverdue() plus one more rule an Eloquent scope cannot
     * express cleanly: exclude a candidacy that already has a dismissal case
     * open (draft, pending or approved), so CGS cannot start a second one for
     * the same student while the first is still being decided.
     */
    protected function initiableCandidacies()
    {
        $inProgress = RpdDismissalDetail::whereHas('application', function ($query) {
            $query->whereIn('status', [
                Application::STATUS_DRAFT,
                Application::STATUS_PENDING,
                Application::STATUS_APPROVED,
            ]);
        })->pluck('candidacy_id');

        return Candidacy::overdue()
            ->whereNotIn('id', $inProgress)
            ->with('student')
            ->orderBy('deadline')
            ->get();
    }
}
