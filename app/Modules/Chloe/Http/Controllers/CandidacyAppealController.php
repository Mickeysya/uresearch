<?php

namespace App\Modules\Chloe\Http\Controllers;

use App\Modules\Chloe\Models\CandidacyAppealDetail;
use App\Modules\Chloe\Models\CandidacyAppealPublication;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Notifications\CandidacyAppealApproved;
use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

/**
 * Only queueFor() and moduleKey() come from ApprovesApplications — decide()
 * below is this controller's own, because the shared trait's only accepts
 * approve/reject and this chain also needs Return. See
 * app/Modules/Chloe/README.md and WorkflowEngine::decide().
 */
class CandidacyAppealController extends Controller
{
    use ApprovesApplications;

    protected function moduleKey(): string
    {
        return 'candidacy_appeal';
    }

    public function create(Request $request)
    {
        $candidacy = StudyCandidacy::where('student_id', $request->user()->id)->first();

        abort_unless($candidacy, 404, 'No candidacy record found for your account.');

        if (! $this->eligible($candidacy)) {
            return redirect()->route('candidacy.status')->with('error', $this->ineligibleReason($candidacy));
        }

        $supervisors = User::where('role', Role::SUPERVISOR)->orderBy('name')->get();

        return view('chloe::candidacy.appeal.form', compact('candidacy', 'supervisors'));
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        $candidacy = StudyCandidacy::where('student_id', $request->user()->id)->first();
        abort_unless($candidacy, 404);
        abort_unless($this->eligible($candidacy), 403, $this->ineligibleReason($candidacy));

        $data = $request->validate($this->appealFormRules($candidacy) + [
            'supervisor_id' => ['required', 'exists:users,id'],
            'appeal_form' => DocumentStore::rules(required: false),
            'disclaimer' => ['accepted'],
        ], $this->appealFormMessages($candidacy));

        $supervisor = User::where('id', $data['supervisor_id'])->where('role', Role::SUPERVISOR)->first();
        abort_unless($supervisor, 422, 'Select a valid supervisor.');

        $application = DB::transaction(function () use ($request, $data, $candidacy, $engine, $documents) {
            $application = Application::create([
                'student_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            $detail = CandidacyAppealDetail::create($this->appealFormAttributes($data) + [
                'application_id' => $application->id,
                'study_candidacy_id' => $candidacy->id,
                'supervisor_id' => $data['supervisor_id'],
                'disclaimer_acknowledged_at' => now(),
            ]);

            $this->syncPublications($detail, $data['publications'] ?? []);

            if ($request->hasFile('appeal_form')) {
                $documents->attach($application, $request->file('appeal_form'), 'Official Appeal Form');
            }

            return $engine->submit($application);
        });

        return redirect()->route('applications.index')
            ->with('status', "Appeal #{$application->id} submitted. Your supervisor has been notified.");
    }

    /**
     * The student's own appeal, always readable regardless of status —
     * pending, returned, rejected or approved. Editing is a separate,
     * narrower action (edit()/resubmit(), only while RETURNED).
     */
    public function show(Request $request, Application $application)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless($application->student_id === $request->user()->id, 403);

        $detail = CandidacyAppealDetail::where('application_id', $application->id)
            ->with('candidacy', 'supervisor', 'publications')
            ->firstOrFail();

        $application->load('history.approver', 'documents');

        return view('chloe::candidacy.appeal.show', compact('application', 'detail'));
    }

    public function edit(Request $request, Application $application)
    {
        $detail = $this->ownedReturnedAppeal($request, $application);

        return view('chloe::candidacy.appeal.resubmit', [
            'application' => $application,
            'detail' => $detail,
            'lastReturn' => $application->history()->where('decision', 'returned')->latest('created_at')->first(),
        ]);
    }

    public function resubmit(Request $request, Application $application, WorkflowEngine $engine, DocumentStore $documents)
    {
        $detail = $this->ownedReturnedAppeal($request, $application);
        $candidacy = $detail->candidacy;

        $data = $request->validate($this->appealFormRules($candidacy) + [
            'appeal_form' => DocumentStore::rules(required: false),
        ], $this->appealFormMessages($candidacy));

        DB::transaction(function () use ($request, $data, $detail, $application, $engine, $documents) {
            $detail->update($this->appealFormAttributes($data));

            $this->syncPublications($detail, $data['publications'] ?? []);

            if ($request->hasFile('appeal_form')) {
                $documents->attach($application, $request->file('appeal_form'), 'Official Appeal Form (Revised)');
            }

            $engine->resubmit($application);
        });

        return redirect()->route('applications.index')->with('status', "Appeal #{$application->id} resubmitted.");
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents']);

        $details = CandidacyAppealDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->with('candidacy', 'supervisor', 'publications')
            ->get()->keyBy('application_id');

        return view('chloe::candidacy.appeal.queue', $queue + ['details' => $details]);
    }

    public function decide(Request $request, Application $application, WorkflowEngine $engine)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject,return'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application has already been decided.');
        }

        // A rejection at ANY stage permanently blocks further appeals for
        // this candidacy — Return is different, it only reopens this same
        // application for editing (WorkflowEngine::resubmit()), it never
        // touches last_rejection_at. Only the Dean's approval calculates
        // the new expiry date; authorisation already guarantees the actor
        // was on the 'dean' stage if their role is DEAN_PGR —
        // WorkflowEngine::decide() would have thrown UnauthorizedException
        // otherwise.
        if ($data['decision'] === 'reject') {
            $this->applyRejection($application->fresh());
        } elseif ($data['decision'] === 'approve' && $request->user()->role === Role::DEAN_PGR) {
            $this->applyDeanApproval($application->fresh());
        }

        $verb = match ($data['decision']) {
            'approve' => 'approved',
            'reject' => 'rejected',
            'return' => 'returned for revision',
        };

        return back()->with('status', "Appeal #{$application->id} {$verb}.");
    }

    protected function applyDeanApproval(Application $application): void
    {
        $detail = CandidacyAppealDetail::where('application_id', $application->id)->with('candidacy')->first();

        if (! $detail || ! $detail->candidacy) {
            return;
        }

        $candidacy = $detail->candidacy;
        $newExpiry = $candidacy->candidacy_expiry_date->copy()->addMonths($detail->requested_extension_months);

        $detail->update(['new_expiry_date' => $newExpiry]);

        $candidacy->update([
            'candidacy_expiry_date' => $newExpiry,
            'cumulative_extension_months' => $candidacy->cumulative_extension_months + $detail->requested_extension_months,
        ]);

        $candidacy->student?->notify(new CandidacyAppealApproved($detail->fresh()));
    }

    /** A rejection at any stage — Supervisor, Chair, CGS Verification or Dean — is final. */
    protected function applyRejection(Application $application): void
    {
        $detail = CandidacyAppealDetail::where('application_id', $application->id)->with('candidacy')->first();

        if (! $detail || ! $detail->candidacy) {
            return;
        }

        $detail->candidacy->update(['last_rejection_at' => now()]);
    }

    /**
     * Shared Section A-D validation, used by both the first submission and
     * a resubmission after Return — same fields, same rules either way.
     */
    protected function appealFormRules(StudyCandidacy $candidacy): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:2000'],

            // Section A -- the form's percentage-of-completion field applies
            // to whichever phase is ticked, not just Writing (it's one
            // merged cell spanning all four phase rows on the paper form).
            'phase' => ['required', 'in:'.implode(',', array_keys(CandidacyAppealDetail::phases()))],
            'writing_completion_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'rcs_status' => ['required', 'in:completed,pending'],
            'rcs_date' => ['required_if:rcs_status,completed', 'nullable', 'date'],
            'rcs_category' => ['required_if:rcs_status,completed', 'nullable', 'string', 'max:255'],
            'rcs_expected_date' => ['required_if:rcs_status,pending', 'nullable', 'date'],

            // Section B
            'extension_via_gsc' => ['required', 'in:yes,no'],
            'extension_via_vc' => ['required', 'in:yes,no'],

            // Section C
            'publications' => ['nullable', 'array'],
            'publications.*' => ['nullable', 'string', 'max:1000'],

            // Section D
            'requested_extension_months' => ['required', 'integer', 'min:1', 'max:'.$candidacy->remainingAppealMonths()],
        ];
    }

    protected function appealFormMessages(StudyCandidacy $candidacy): array
    {
        return [
            'requested_extension_months.max' => 'You can request at most '.$candidacy->remainingAppealMonths().' more month(s) — the 12-month cap.',
            'writing_completion_percent.required' => 'Enter your percentage of completion.',
            'rcs_date.required_if' => 'Enter the RCS date, or mark RCS as not yet done.',
            'rcs_category.required_if' => 'Enter the RCS category, or mark RCS as not yet done.',
            'rcs_expected_date.required_if' => 'Enter an expected RCS date, or mark RCS as completed.',
        ];
    }

    /**
     * Maps validated Section A-D input onto CandidacyAppealDetail's
     * fillable attributes. 'publications' is handled separately by
     * syncPublications() since it's a child table, not a column.
     */
    protected function appealFormAttributes(array $data): array
    {
        return [
            'reason' => $data['reason'] ?? '',
            'phase' => $data['phase'],
            'writing_completion_percent' => $data['writing_completion_percent'],
            'rcs_status' => $data['rcs_status'],
            'rcs_date' => $data['rcs_status'] === CandidacyAppealDetail::RCS_COMPLETED ? $data['rcs_date'] : null,
            'rcs_category' => $data['rcs_status'] === CandidacyAppealDetail::RCS_COMPLETED ? $data['rcs_category'] : null,
            'rcs_expected_date' => $data['rcs_status'] === CandidacyAppealDetail::RCS_PENDING ? $data['rcs_expected_date'] : null,
            'extension_via_gsc' => $data['extension_via_gsc'] === 'yes',
            'extension_via_vc' => $data['extension_via_vc'] === 'yes',
            'requested_extension_months' => $data['requested_extension_months'],
        ];
    }

    protected function syncPublications(CandidacyAppealDetail $detail, array $publications): void
    {
        $detail->publications()->delete();

        foreach (array_filter($publications, fn ($description) => trim((string) $description) !== '') as $description) {
            CandidacyAppealPublication::create([
                'candidacy_appeal_detail_id' => $detail->id,
                'description' => trim($description),
            ]);
        }
    }

    protected function eligible(StudyCandidacy $candidacy): bool
    {
        return $candidacy->canAppeal() && ! $candidacy->hasOpenAppeal();
    }

    protected function ineligibleReason(StudyCandidacy $candidacy): string
    {
        return match (true) {
            $candidacy->hasOpenAppeal() => 'You already have an appeal in progress.',
            $candidacy->last_rejection_at !== null => 'A previous appeal was rejected — no further appeals can be filed.',
            $candidacy->remainingAppealMonths() <= 0 => 'You have used your full 12-month appeal allowance.',
            default => 'You are not eligible to submit a new appeal.',
        };
    }

    protected function ownedReturnedAppeal(Request $request, Application $application): CandidacyAppealDetail
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless($application->student_id === $request->user()->id, 403);
        abort_unless($application->status === Application::STATUS_RETURNED, 403, 'This appeal is not awaiting revision.');

        return CandidacyAppealDetail::where('application_id', $application->id)->with('candidacy')->firstOrFail();
    }
}
