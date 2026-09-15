<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Models\Candidacy;
use App\Modules\Norhanis\Models\RpdAppealDetail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;
use Illuminate\Validation\ValidationException;

/**
 * RPD extension appeals — norhanis.md Module 4, path 2.
 *
 * The chain itself is ordinary (see RpdAppealWorkflow). What is not ordinary is
 * decide(): on the Dean's approval this is the code that "automatically
 * recalculates the new deadline and updates the masterlist", which is the
 * feature the scope actually names.
 */
class RpdAppealController extends Controller
{
    use ApprovesApplications;

    protected function moduleKey(): string
    {
        return 'rpd_appeal';
    }

    public function create(Request $request)
    {
        $candidacy = $this->candidacyFor($request);

        return view('norhanis::rpd_appeal.form', [
            'candidacy' => $candidacy,
            'maxMonths' => $candidacy?->extensionMonthsRemaining() ?? 0,
            'openAppeal' => $candidacy ? $this->openAppealFor($candidacy) : null,
        ]);
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        $candidacy = $this->candidacyFor($request);

        // Guarded here and not only in the view: a student can post this form
        // directly, and the twelve-month ceiling is a policy, not a hint.
        if (! $candidacy) {
            throw ValidationException::withMessages([
                'requested_months' => 'You have no RPD candidacy on record. Contact CGS before appealing.',
            ]);
        }

        if (! $candidacy->canAppeal()) {
            throw ValidationException::withMessages([
                'requested_months' => $candidacy->extensionMonthsRemaining() === 0
                    ? 'You have already used the maximum '.Candidacy::MAX_EXTENSION_MONTHS.' months of extension.'
                    : 'Your candidacy is '.$candidacy->status.' and can no longer be appealed.',
            ]);
        }

        if ($this->openAppealFor($candidacy)) {
            throw ValidationException::withMessages([
                'requested_months' => 'You already have an appeal under review. Wait for its outcome before filing another.',
            ]);
        }

        $data = $request->validate([
            // max is the remaining ceiling, not a constant -- a student who has
            // already taken 9 months may ask for 3, not 12.
            'requested_months' => ['required', 'integer', 'min:1', 'max:'.$candidacy->extensionMonthsRemaining()],
            'justification' => ['required', 'string', 'min:40', 'max:2000'],
            'supporting_document' => DocumentStore::rules(),
        ], [
            'requested_months.max' => 'You have '.$candidacy->extensionMonthsRemaining().' month(s) of extension left under the '.Candidacy::MAX_EXTENSION_MONTHS.'-month ceiling.',
            'justification.min' => 'Please give the Dean enough detail to decide on — a sentence or two at minimum.',
        ]);

        $application = DB::transaction(function () use ($request, $data, $candidacy, $engine, $documents) {
            $application = Application::create([
                'student_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            RpdAppealDetail::create([
                'application_id' => $application->id,
                'candidacy_id' => $candidacy->id,
                'requested_months' => $data['requested_months'],
                'justification' => $data['justification'],
                // Snapshot: the approver must see the deadline the student was
                // looking at, even if something moves it in the meantime.
                'deadline_at_filing' => $candidacy->rpd_deadline,
            ]);

            if ($request->hasFile('supporting_document')) {
                $documents->attach($application, $request->file('supporting_document'), 'Supporting Document');
            }

            return $engine->submit($application);
        });

        return redirect()
            ->route('applications.index')
            ->with('status', "RPD extension appeal #{$application->id} submitted. Your supervisor has been notified.");
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents', 'student']);

        $details = RpdAppealDetail::with('candidacy')
            ->whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        return view('norhanis::rpd_appeal.queue', $queue + ['details' => $details]);
    }

    /**
     * Approve or reject — and on the Dean's approval, move the masterlist.
     *
     * Everything that changes the candidacy happens in one transaction with the
     * engine's own write, so a failure halfway cannot leave an approved appeal
     * beside an unmoved deadline.
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
                    $this->grantExtension($decided);
                }

                return $decided;
            });
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application has already been decided.');
        }

        if ($data['decision'] === 'approve' && $decided->status === Application::STATUS_APPROVED) {
            $detail = RpdAppealDetail::where('application_id', $decided->id)->first();

            return back()->with('status', "Appeal #{$decided->id} approved. The RPD deadline is now "
                .$detail?->new_deadline?->format('j M Y').'.');
        }

        $verb = $data['decision'] === 'approve' ? 'approved' : 'rejected';

        return back()->with('status', "Application #{$decided->id} {$verb}.");
    }

    /**
     * Move the deadline, bank the months used, and clear the reminder log.
     *
     * The reminder rows have to go: they record that the 3/2/1-month emails
     * fired against the OLD deadline. Leaving them would mean the student is
     * never reminded about the new one, because the unique index still says
     * "milestone 3 already sent for this candidacy".
     */
    protected function grantExtension(Application $application): void
    {
        $detail = RpdAppealDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return;
        }

        $candidacy = Candidacy::find($detail->candidacy_id);

        if (! $candidacy) {
            return;
        }

        // Extend from the deadline as it stands now, not from the snapshot --
        // if anything else moved it while the appeal was in the chain, the
        // student is owed their months on top of that, not instead of it.
        $newDeadline = $candidacy->rpd_deadline->copy()->addMonthsNoOverflow($detail->requested_months);

        $candidacy->update([
            'rpd_deadline' => $newDeadline,
            'status' => Candidacy::STATUS_EXTENDED,
            'extension_months_used' => $candidacy->extension_months_used + $detail->requested_months,
        ]);

        $detail->update(['new_deadline' => $newDeadline]);

        $candidacy->reminderLogs()->delete();
    }

    protected function candidacyFor(Request $request): ?Candidacy
    {
        return Candidacy::where('student_id', $request->user()->id)->first();
    }

    /** An appeal already in the chain for this candidacy, if any. */
    protected function openAppealFor(Candidacy $candidacy): ?Application
    {
        return Application::query()
            ->where('module_type', $this->moduleKey())
            ->where('status', Application::STATUS_PENDING)
            ->whereIn('id', RpdAppealDetail::where('candidacy_id', $candidacy->id)->pluck('application_id'))
            ->first();
    }
}
