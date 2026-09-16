<?php

namespace App\Modules\Jason\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Jason\Models\HardboundSignature;
use App\Modules\Jason\Models\HardboundSubmissionDetail;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\UnauthorizedException;

class HardboundSubmissionController extends Controller
{
    use ApprovesApplications;

    /** The last stage: approval here accepts the pack and issues the receipt. */
    protected const FINAL_STAGE = 'cgs_review';

    protected function moduleKey(): string
    {
        return 'hardbound_submission';
    }

    public const DOC_SUBMISSION_FORM = 'Hardbound Thesis Submission Form';

    public const DOC_CORRECTION_FORM = 'Confirmation of Correction to Thesis';

    /**
     * The CGS form a student downloads, signs and uploads back. The
     * Confirmation of Correction is not here: the portal generates it from
     * the student's declaration and the approvers sign it electronically.
     */
    public const TEMPLATES = [
        'submission' => ['file' => 'hardbound-thesis-submission-form.pdf', 'label' => self::DOC_SUBMISSION_FORM],
    ];

    /**
     * Serves a blank form from the module's own templates folder. Nothing is
     * generated: whatever PDF sits at that path is what the student gets, so
     * replacing the file replaces the form.
     */
    public function template(string $form)
    {
        abort_unless(isset(self::TEMPLATES[$form]), 404);

        $path = __DIR__.'/../../Resources/templates/'.self::TEMPLATES[$form]['file'];

        abort_unless(is_file($path), 404, 'That form has not been uploaded to the portal yet.');

        return response()->download($path, self::TEMPLATES[$form]['file']);
    }

    /**
     * Where a Supervisor, Chair or CGS officer keeps the signature image
     * that is stamped onto the Confirmation of Correction when they approve.
     */
    public function signature(Request $request)
    {
        return view('jason::hardbound.signature', [
            'signature' => HardboundSignature::forUser($request->user()->id),
        ]);
    }

    public function storeSignature(Request $request): RedirectResponse
    {
        $request->validate(['signature' => HardboundSignature::rules()], [
            'signature.mimes' => 'Upload the signature as a PNG or JPG image.',
            'signature.max' => 'Keep the signature image under 1 MB.',
        ]);

        $file = $request->file('signature');
        $existing = HardboundSignature::forUser($request->user()->id);

        // Private disk, random name -- the same footing as every upload in
        // the portal, just not attached to an application.
        $path = $file->store(HardboundSignature::DIRECTORY, 'local');

        HardboundSignature::updateOrCreate(['user_id' => $request->user()->id], [
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
        ]);

        if ($existing && $existing->path !== $path) {
            Storage::disk('local')->delete($existing->path);
        }

        return redirect()->route('hardbound.signature')
            ->with('status', 'Signature saved. It will be stamped onto every Confirmation you approve from now on.');
    }

    /** The signature image, shown back to its owner only. */
    public function signatureImage(Request $request)
    {
        $signature = HardboundSignature::forUser($request->user()->id);

        abort_unless($signature, 404);

        return response($signature->contents(), 200, ['Content-Type' => $signature->mime_type]);
    }

    public function create(Request $request)
    {
        // Submissions CGS has returned and the student has not yet replaced,
        // surfaced here because this is the one student page the module
        // owns: Core's student sidebar does not render module links.
        $awaiting = HardboundSubmissionDetail::resubmittableFor($request->user()->id)->get();

        return view('jason::hardbound.form', [
            'student' => $request->user(),
            'returned' => null,
            'detail' => null,
            'awaiting' => $awaiting,
            'awaitingDetails' => HardboundSubmissionDetail::whereIn('application_id', $awaiting->pluck('id'))
                ->get()->keyBy('application_id'),
        ]);
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        $data = $this->validateSubmission($request);

        $application = $this->fileSubmission($request, $data, $engine, $documents);

        return redirect()
            ->route('applications.index')
            ->with('status', "Hardbound submission #{$application->id} sent to your supervisor to confirm the corrections.");
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
            'awaiting' => collect(),
            'awaitingDetails' => collect(),
        ]);
    }

    public function resubmit(Request $request, Application $application, WorkflowEngine $engine, DocumentStore $documents)
    {
        $this->resubmittableDetail($request, $application);

        $data = $this->validateSubmission($request, resubmission: true);

        $fresh = $this->fileSubmission($request, $data, $engine, $documents, replaces: $application);

        return redirect()
            ->route('applications.index')
            ->with('status', "Resubmission #{$fresh->id} sent to your supervisor, replacing #{$application->id}.");
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
     * has to carry comments explaining what to fix, and an approval issues
     * the acknowledgement receipt. Authorisation and the stage move remain
     * the engine's.
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $stage = $engine->currentStage($application);
        $returning = $request->input('decision') === 'reject';

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            // A rejection at any stage is a return to the student, and a return
            // without comments gives them nothing to correct.
            'remarks' => [$returning ? 'required' : 'nullable', 'string', 'max:2000'],
        ], [
            'remarks.required' => 'Tell the student what to correct before returning the submission.',
        ]);

        // Approving stamps the approver's signature onto the Confirmation, so
        // there has to be one to stamp. Rejecting signs nothing.
        if (! $returning && $stage && ! HardboundSignature::forUser($request->user()->id)) {
            return redirect()->route('hardbound.signature')
                ->with('error', 'Upload your signature first — approving stamps it onto the Confirmation of Correction to Thesis.');
        }

        try {
            $application = $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application has already been decided.');
        }

        if (! $returning) {
            // Re-issue the Confirmation with this approver's signature added.
            $this->issueConfirmation($application);

            if ($stage?->key === self::FINAL_STAGE) {
                $this->issueAcknowledgement($application);
            }
        }

        return back()->with('status', $returning
            ? "Submission #{$application->id} returned to the student."
            : "Submission #{$application->id} {$stage?->decision} and signed.");
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
            // The Confirmation of Correction is generated from this; the student
            // signs by declaring, the approvers sign as it moves.
            'corrections_made' => ['required', 'string', 'max:4000'],
            'declaration' => ['accepted'],
            // The Hardbound Thesis Submission form is signed by the student
            // themselves, so it is still downloaded, signed and uploaded. On a
            // resubmission it is re-uploaded only if it changed.
            'submission_form' => DocumentStore::rules(required: ! $resubmission),
            'response_to_comments' => [$resubmission ? 'required' : 'nullable', 'string', 'max:2000'],
        ], [
            'corrections_made.required' => 'List the corrections made to the thesis.',
            'declaration.accepted' => 'Confirm the declaration to submit.',
            'submission_form.required' => 'Attach the signed Hardbound Thesis Submission form.',
            'response_to_comments.required' => 'Explain what you changed in response to the comments.',
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
                'corrections_made' => $data['corrections_made'],
                'resubmission_of_id' => $replaces?->id,
                'response_to_comments' => $data['response_to_comments'] ?? null,
            ]);

            if ($request->hasFile('submission_form')) {
                $documents->attach($application, $request->file('submission_form'), self::DOC_SUBMISSION_FORM);
            }

            $application = $engine->submit($application);

            // The unsigned Confirmation, so the Supervisor reads what they are
            // being asked to sign. Re-issued with a signature at each approval.
            $this->issueConfirmation($application);

            return $application;
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
     * Generates the Confirmation of Correction to Thesis with every
     * signature collected so far and archives it, replacing the previous
     * copy so the application always carries exactly one, current version.
     * Each approval's signature and date come from the approval_history row
     * the engine wrote and the approver's own uploaded signature.
     */
    protected function issueConfirmation(Application $application): void
    {
        $detail = HardboundSubmissionDetail::where('application_id', $application->id)->firstOrFail();

        $signatures = [];

        foreach ($application->history()->with('approver')->orderBy('id')->get() as $row) {
            if ($row->decision === 'rejected') {
                continue;
            }

            $signatures[$row->stage_key] = [
                'name' => $row->approver?->name ?? '—',
                'date' => $row->created_at,
                'image' => ($row->approver_id ? HardboundSignature::forUser($row->approver_id)?->dataUri() : null),
            ];
        }

        $pdf = Pdf::loadView('jason::hardbound.confirmation', [
            'application' => $application,
            'detail' => $detail,
            'student' => $application->student,
            'signatures' => $signatures,
            'issuedAt' => now(),
        ]);

        foreach (ApplicationDocument::where('application_id', $application->id)
            ->where('doc_type', self::DOC_CORRECTION_FORM)->get() as $previous) {
            Storage::disk('local')->delete($previous->path);
            $previous->delete();
        }

        app(DocumentStore::class)->storeGenerated(
            $application,
            $pdf->output(),
            "Confirmation-of-Correction-{$application->id}.pdf",
            self::DOC_CORRECTION_FORM,
        );
    }

    /**
     * The acknowledgement receipt the spec calls for on approval -- the
     * student's proof that CGS accepted the bound thesis. Archived as an
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
