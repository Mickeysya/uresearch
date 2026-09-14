<?php

namespace App\Modules\Jason\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Jason\Models\HardboundSubmissionDetail;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

class HardboundSubmissionController extends Controller
{
    use ApprovesApplications;

    /** The stage a rejection at which means "returned for correction". */
    protected const REVIEW_STAGE = 'cgs_review';

    protected function moduleKey(): string
    {
        return 'hardbound_submission';
    }

    public function create(Request $request)
    {
        return view('jason::hardbound.form', [
            'student' => $request->user(),
            'returned' => null,
            'detail' => null,
        ]);
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        $data = $this->validateSubmission($request);

        $application = $this->fileSubmission($request, $data, $engine, $documents);

        return redirect()
            ->route('applications.index')
            ->with('status', "Hardbound submission #{$application->id} sent to CGS for review.");
    }

    /**
     * The resubmission form for an application CGS returned. Prefilled from
     * the returned submission so the student corrects rather than retypes.
     */
    public function resubmitForm(Request $request, Application $application)
    {
        $detail = $this->resubmittableDetail($request, $application);

        return view('jason::hardbound.form', [
            'student' => $request->user(),
            'returned' => $application,
            'detail' => $detail,
        ]);
    }

    public function resubmit(Request $request, Application $application, WorkflowEngine $engine, DocumentStore $documents)
    {
        $this->resubmittableDetail($request, $application);

        $data = $this->validateSubmission($request, resubmission: true);

        $fresh = $this->fileSubmission($request, $data, $engine, $documents, replaces: $application);

        return redirect()
            ->route('applications.index')
            ->with('status', "Resubmission #{$fresh->id} sent to CGS, replacing #{$application->id}.");
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents', 'student']);

        $details = HardboundSubmissionDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        return view('jason::hardbound.queue', $queue + ['details' => $details]);
    }

    /**
     * Overrides the trait's decide() for two module-specific rules: a return
     * has to carry comments explaining what to fix, and a final approval
     * issues the acknowledgement receipt. Authorisation and the stage move
     * remain the engine's.
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $stage = $engine->currentStage($application);
        $returning = $request->input('decision') === 'reject' && $stage?->key === self::REVIEW_STAGE;

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            // The spec makes comments mandatory when returning a submission:
            // "corrected documents and a response to the CGS comments" is
            // impossible to write against an empty remark.
            'remarks' => [$returning ? 'required' : 'nullable', 'string', 'max:2000'],
        ], [
            'remarks.required' => 'Tell the student what to correct before returning the submission.',
        ]);

        try {
            $application = $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application has already been decided.');
        }

        if ($data['decision'] === 'approve' && $stage?->key === 'cgs_approve') {
            $this->issueAcknowledgement($application);
        }

        return back()->with('status', match (true) {
            $returning => "Submission #{$application->id} returned to the student.",
            $data['decision'] === 'approve' => "Submission #{$application->id} approved.",
            default => "Submission #{$application->id} rejected.",
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateSubmission(Request $request, bool $resubmission = false): array
    {
        return $request->validate([
            'thesis_title' => ['required', 'string', 'max:500'],
            'programme' => ['required', 'string', 'max:150'],
            'supervisor_name' => ['required', 'string', 'max:150'],
            'thesis_document' => DocumentStore::rules(required: true),
            'clearance_form' => DocumentStore::rules(required: ! $resubmission),
            'response_to_comments' => [$resubmission ? 'required' : 'nullable', 'string', 'max:2000'],
        ], [
            'thesis_document.required' => 'Attach the final hardbound thesis PDF.',
            'clearance_form.required' => 'Attach the completed clearance form.',
            'response_to_comments.required' => 'Explain what you changed in response to the CGS comments.',
        ]);
    }

    /**
     * Creates the application, its detail row and its uploads, then hands it
     * to the first stage. Shared by a first submission and a resubmission --
     * the only difference is the application it points back at.
     */
    protected function fileSubmission(
        Request $request,
        array $data,
        WorkflowEngine $engine,
        DocumentStore $documents,
        ?Application $replaces = null,
    ): Application {
        return DB::transaction(function () use ($request, $data, $engine, $documents, $replaces) {
            $student = $request->user();

            $application = Application::create([
                'student_id' => $student->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            HardboundSubmissionDetail::create([
                'application_id' => $application->id,
                'thesis_title' => $data['thesis_title'],
                'matric_no' => $student->matric_no ?? '',
                'programme' => $data['programme'],
                'supervisor_name' => $data['supervisor_name'],
                'resubmission_of_id' => $replaces?->id,
                'response_to_comments' => $data['response_to_comments'] ?? null,
            ]);

            $documents->attach($application, $request->file('thesis_document'), 'Hardbound Thesis');

            if ($request->hasFile('clearance_form')) {
                $documents->attach($application, $request->file('clearance_form'), 'Clearance Form');
            }

            return $engine->submit($application);
        });
    }

    /**
     * The detail row of an application this student is allowed to resubmit.
     * The rule itself lives in HardboundSubmissionDetail::resubmittableFor(),
     * shared with the sidebar link and the tracking summary; the checks
     * before it only exist to give a specific reason when it fails.
     */
    protected function resubmittableDetail(Request $request, Application $application): HardboundSubmissionDetail
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        // Students see only their own rows.
        abort_unless($application->student_id === $request->user()->id, 403);

        abort_unless($application->status === Application::STATUS_REJECTED, 403,
            'That submission is not awaiting correction.');

        abort_if(
            HardboundSubmissionDetail::where('resubmission_of_id', $application->id)->exists(),
            403,
            'You have already resubmitted this submission.'
        );

        abort_unless(
            HardboundSubmissionDetail::resubmittableFor($request->user()->id)->whereKey($application->id)->exists(),
            403,
            'CGS rejected this submission. File an appeal before resubmitting.'
        );

        return HardboundSubmissionDetail::where('application_id', $application->id)->firstOrFail();
    }

    /**
     * The acknowledgement receipt the spec calls for on final approval --
     * the student's proof that CGS accepted the bound thesis. Archived as an
     * ApplicationDocument so the existing download route and its permission
     * check apply; the approval email itself is the engine's usual
     * ApplicationDecided notification.
     */
    protected function issueAcknowledgement(Application $application): void
    {
        $detail = HardboundSubmissionDetail::where('application_id', $application->id)->firstOrFail();

        $pdf = Pdf::loadView('jason::hardbound.receipt', [
            'application' => $application,
            'detail' => $detail,
            'student' => $application->student,
            'issuedAt' => now(),
        ]);

        app(DocumentStore::class)->storeGenerated(
            $application,
            $pdf->output(),
            "Hardbound-Acknowledgement-{$application->id}.pdf",
            'Acknowledgement Receipt',
        );
    }
}
