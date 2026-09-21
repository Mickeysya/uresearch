<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Models\Candidacy;
use App\Modules\Norhanis\Models\RpdAppealDetail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

class RpdAppealController extends Controller
{
    use ApprovesApplications;

    protected function moduleKey(): string
    {
        return 'rpd_appeal';
    }

    public function create(Request $request)
    {
        $candidacy = $this->activeCandidacyFor($request->user());

        return view('norhanis::rpd_appeal.form', ['candidacy' => $candidacy]);
    }

    public function store(Request $request, WorkflowEngine $engine): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'requested_extension_months' => ['required', 'integer', 'between:1,'.Candidacy::MAX_APPEAL_EXTENSION_MONTHS],
        ]);

        // The candidacy is never trusted from the form -- it is derived from
        // the signed-in student's own active record, the same way TravelController
        // derives duration_days instead of trusting a posted number.
        $candidacy = $this->activeCandidacyFor($request->user());

        abort_unless($candidacy, 404, 'You have no active RPD candidacy to appeal.');

        $application = DB::transaction(function () use ($request, $data, $candidacy, $engine) {
            $application = Application::create([
                'student_id' => $request->user()->id,
                'submitted_by_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            RpdAppealDetail::create([
                'application_id' => $application->id,
                'candidacy_id' => $candidacy->id,
                'reason' => $data['reason'],
                'requested_extension_months' => $data['requested_extension_months'],
            ]);

            // Paused while the appeal is under review -- see Candidacy's
            // docblock. Stops RemindRpdCandidates nagging a student who has
            // already asked for more time, and stops the candidacy showing
            // up on CGS's overdue-for-dismissal list mid-review.
            $candidacy->update(['status' => Candidacy::STATUS_APPEAL_PENDING]);

            return $engine->submit($application);
        });

        return redirect()
            ->route('applications.index')
            ->with('status', "RPD Appeal #{$application->id} submitted. Your supervisor has been notified.");
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine);

        $details = RpdAppealDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->with('candidacy')
            ->get()
            ->keyBy('application_id');

        return view('norhanis::rpd_appeal.queue', $queue + ['details' => $details]);
    }

    /**
     * Overrides the trait's decide(): the Dean holds the final stage, and
     * approval there is the one moment candidacies.deadline is allowed to
     * move -- the "masterlist" update the scope document describes. Modelled
     * exactly on CertificationController::decide()/generateCertificate().
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        // Captured before decide() moves the application -- currentStage()
        // only resolves a stage while status is still PENDING.
        $stage = $engine->currentStage($application);

        try {
            $decided = $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application has already been decided.');
        }

        if ($stage?->key === 'dean' && $decided->status === Application::STATUS_APPROVED) {
            $this->extendCandidacy($decided);
        } elseif ($decided->status === Application::STATUS_REJECTED) {
            // Rejected at any stage -- release the candidacy back to normal
            // tracking under its existing (unchanged) deadline, so a failed
            // appeal does not leave it permanently exempt from reminders and
            // dismissal eligibility.
            $this->releaseCandidacy($decided);
        }

        $verb = $data['decision'] === 'approve' ? 'approved' : 'rejected';

        return back()->with('status', "Application #{$application->id} {$verb}.");
    }

    /**
     * Extends the candidacy's current deadline by the months the student
     * requested (1-6, validated at submission). The Dean's approval is the
     * only thing that lets this run; nothing here lets an approver or the
     * student choose a calendar date.
     */
    protected function extendCandidacy(Application $application): void
    {
        $detail = RpdAppealDetail::where('application_id', $application->id)->with('candidacy')->firstOrFail();
        $candidacy = $detail->candidacy;

        $candidacy->update([
            'deadline' => $candidacy->deadline->copy()->addMonths($detail->requested_extension_months),
            'status' => Candidacy::STATUS_ACTIVE,
        ]);
    }

    protected function releaseCandidacy(Application $application): void
    {
        $detail = RpdAppealDetail::where('application_id', $application->id)->first();

        $detail?->candidacy()->update(['status' => Candidacy::STATUS_ACTIVE]);
    }

    protected function activeCandidacyFor(User $user): ?Candidacy
    {
        return Candidacy::where('student_id', $user->id)
            ->where('status', Candidacy::STATUS_ACTIVE)
            ->latest('start_date')
            ->first();
    }
}
