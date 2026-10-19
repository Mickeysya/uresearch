<?php

namespace App\Modules\Jason\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Jason\Mail\ExaminerSignatureRequest;
use App\Modules\Jason\Models\AppointmentExaminer;
use App\Modules\Jason\Models\HardboundExaminerSignature;
use App\Modules\Jason\Models\HardboundSignature;
use App\Modules\Jason\Models\HardboundSubmissionDetail;
use App\Modules\Jason\Notifications\HardboundSubmissionRejected;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\UnauthorizedException;

class HardboundSubmissionController extends Controller
{
    use ApprovesApplications;

    /** The last stage: approval here accepts the pack and issues the receipt. */
    protected const FINAL_STAGE = 'cgs_review';

    /** Stages with a signature block on the Confirmation of Correction (UTP/CGS/017A). */
    protected const SIGNING_STAGES = ['supervisor', 'chair'];

    protected function moduleKey(): string
    {
        return 'hardbound_submission';
    }

    public const DOC_SUBMISSION_FORM = 'Hardbound Thesis Submission Form';

    public const DOC_CORRECTION_FORM = 'Confirmation of Correction to Thesis';

    /**
     * The Hardbound Thesis Submission form, UTP/CGS/021, drawn on demand.
     *
     * Downloaded blank, completed by hand and uploaded back until
     * 2026-10-19; now filled in on screen and rendered from what the
     * candidate entered plus the signature on their account. Nothing is
     * archived -- the form is its own data, so it costs no storage and
     * cannot drift from the record.
     *
     * Readable by the candidate and by the three roles holding a stage in
     * this chain: with nothing in the documents list, this is how an
     * approver reads what was submitted. Submissions filed under the upload
     * version still carry the candidate's own file, and that is served
     * instead when one is found.
     */
    public function submissionForm(Request $request, Application $application)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $user = $request->user();
        $stageRoles = array_map(fn ($stage) => $stage->role, app(WorkflowEngine::class)->stagesFor($application));

        abort_unless(
            $application->student_id === $user->id || in_array($user->role, $stageRoles, true),
            403
        );

        if ($uploaded = ApplicationDocument::where('application_id', $application->id)
            ->where('doc_type', self::DOC_SUBMISSION_FORM)
            ->latest('id')
            ->first()) {
            return redirect()->route('documents.show', $uploaded);
        }

        $detail = HardboundSubmissionDetail::where('application_id', $application->id)->firstOrFail();
        $student = $application->student;

        $pdf = Pdf::loadView('jason::hardbound.submission_form', [
            'application' => $application,
            'detail' => $detail,
            'student' => $student,
            'signature' => $student ? HardboundSignature::forUser($student->id)?->dataUri() : null,
            'signedAt' => $application->submitted_at ?? $application->created_at,
        ]);

        // Inline, not an attachment: this is read far more often than it is
        // filed away, and a reviewer checking a detail should not end up with
        // another copy in their downloads folder each time. `?download=1`
        // when somebody actually wants the file.
        return $this->servePdf($pdf, "Hardbound-Thesis-Submission-{$application->id}.pdf", $request);
    }

    /**
     * A page to read the forms on, rather than a file to open.
     *
     * Both CGS forms are generated, so there is nothing to download to check
     * them -- they are embedded here from the same routes that serve them.
     * The Confirmation is an archived document and goes through Core's
     * download route, which always sends an attachment, so only 021 can be
     * framed; the Confirmation is linked instead.
     */
    public function forms(Request $request, Application $application)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $user = $request->user();
        $stageRoles = array_map(fn ($stage) => $stage->role, app(WorkflowEngine::class)->stagesFor($application));

        abort_unless(
            $application->student_id === $user->id || in_array($user->role, $stageRoles, true),
            403
        );

        return view('jason::hardbound.forms', [
            'application' => $application,
            'detail' => HardboundSubmissionDetail::where('application_id', $application->id)->first(),
            'student' => $application->student,
            'confirmation' => ApplicationDocument::where('application_id', $application->id)
                ->where('doc_type', self::DOC_CORRECTION_FORM)
                ->latest('id')
                ->first(),
        ]);
    }

    /**
     * Inline unless the caller asked for a file.
     *
     * Content-Disposition is the whole difference: `inline` hands the PDF to
     * the browser's own viewer, `attachment` puts it on disk.
     */
    protected function servePdf($pdf, string $filename, Request $request)
    {
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
        ]);
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

        // Every submission they have filed, so they can read the forms the
        // portal wrote for them. Nothing else shows these: 021 is not an
        // archived document any more, so it does not appear in Core's
        // documents list.
        $filed = Application::where('module_type', $this->moduleKey())
            ->where('student_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        return view('jason::hardbound.form', [
            'student' => $request->user(),
            'returned' => null,
            'detail' => null,
            'awaiting' => $awaiting,
            'awaitingDetails' => HardboundSubmissionDetail::whereIn('application_id', $awaiting->pluck('id'))
                ->get()->keyBy('application_id'),
            'filed' => $filed,
            'filedDetails' => HardboundSubmissionDetail::whereIn('application_id', $filed->pluck('id'))
                ->get()->keyBy('application_id'),
        ]);
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        if ($redirect = $this->mustHaveSignature($request)) {
            return $redirect;
        }

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
            'filed' => collect(),
            'filedDetails' => collect(),
        ]);
    }

    public function resubmit(Request $request, Application $application, WorkflowEngine $engine, DocumentStore $documents)
    {
        if ($redirect = $this->mustHaveSignature($request)) {
            return $redirect;
        }

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
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        // A return without comments gives the student nothing to act on. This
        // is checked here rather than as a validation rule because the shared
        // decision form labels remarks "(optional)" and nothing renders the
        // validation error bag -- a failed rule would just bounce the reviewer
        // back to an unchanged page with no explanation. A flashed error is
        // rendered by the layout.
        if ($returning && blank($data['remarks'] ?? null)) {
            return back()->with('error',
                'Returning a submission needs remarks — tell the student what to correct, then press Reject again.');
        }

        // Approving as Supervisor or Chairman stamps a signature onto the
        // Confirmation, so there has to be one to stamp. CGS has no block on
        // that form, and rejecting signs nothing.
        if (! $returning && $stage && in_array($stage->key, self::SIGNING_STAGES, true)
            && ! HardboundSignature::forUser($request->user()->id)) {
            return redirect()->route('hardbound.signature')
                ->with('error', 'Upload your signature first. Approving stamps it onto the Confirmation of Correction to Thesis.');
        }

        // CGS accepts a form all three signatories have signed. The examiner
        // signs through an emailed link rather than a queue, so without this
        // the submission could complete with their block empty -- which is
        // what the form is for. Only enforced where there is an examiner to
        // chase: a candidate whose panel was appointed off-system has none
        // on record, and blocking CGS on somebody the portal cannot name
        // would strand the submission.
        if (! $returning && $stage?->key === self::FINAL_STAGE
            && $this->appointedExaminers($application)->isNotEmpty()
            && ! HardboundExaminerSignature::forApplication($application->id)) {
            return back()->with('error',
                'The examiner has not signed the Confirmation yet. They were emailed a signing link when the '
                .'Chairman approved; resend it from this queue, or ask CGS to collect the signature on paper.');
        }

        try {
            $application = $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application has already been decided.');
        }

        if (! $returning && in_array($stage?->key, self::SIGNING_STAGES, true)) {
            // Re-issue the Confirmation with this approver's signature added.
            $this->issueConfirmation($application);
        }

        // The Chairman is the last of the two signatories with an account.
        // With both of them on the form, the examiner is asked for theirs.
        if (! $returning && $stage?->key === 'chair') {
            $this->inviteExaminers($application);
        }

        if (! $returning && $stage?->key === self::FINAL_STAGE) {
            $this->issueAcknowledgement($application);
        }

        if ($returning && $stage) {
            // The engine's own notice says only "not approved ... contact your
            // supervisor", which is wrong here -- this chain closes the
            // application and offers two ways forward.
            $application->student?->notify(new HardboundSubmissionRejected(
                $application,
                $stage,
                $request->user()->name,
                $data['remarks'] ?? null,
            ));
        }

        return back()->with('status', $returning
            ? "Submission #{$application->id} returned to the student."
            : "Submission #{$application->id} {$stage?->decision} and signed.");
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * UTP/CGS/021 carries the candidate's own certification -- that they
     * have handed over a CD and two bound copies prepared to the
     * University's guidelines -- so it cannot be filed over a blank
     * signature rule. Gated the way the extension memo and every approver
     * already are: upload once, then file.
     */
    protected function mustHaveSignature(Request $request): ?RedirectResponse
    {
        if (HardboundSignature::forUser($request->user()->id)) {
            return null;
        }

        return redirect()->route('hardbound.signature')
            ->with('error', 'Upload your signature first. It is stamped onto the Hardbound Thesis '
                .'Submission form you are certifying.');
    }

    protected function validateSubmission(Request $request, bool $resubmission = false): array
    {
        return $request->validate([
            'thesis_title' => ['required', 'string', 'max:500'],
            'programme' => ['required', 'string', 'max:150'],
            // The four boxes UTP/CGS/021 used to leave blank for the
            // candidate's pen. Nullable: the form is the candidate's own
            // certification, and CGS can complete a box on the printed
            // copy, but it cannot refuse to be filed over one.
            // Six boxes each on UTP/CGS/021. Capped here rather than
            // truncated when drawing it, so what the candidate typed and
            // what the form shows are the same thing.
            'country' => ['nullable', 'string', 'max:6'],
            'intake' => ['nullable', 'string', 'max:6'],
            'passport_no' => ['nullable', 'string', 'max:40'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'supervisor_name' => ['required', 'string', 'max:150'],
            // The Confirmation of Correction (UTP/CGS/017A) is generated from
            // these; the approvers sign it as it moves.
            'viva_date' => ['required', 'date', 'before_or_equal:today'],
            'co_supervisor_name' => ['nullable', 'string', 'max:150'],
            'response_to_comments' => [$resubmission ? 'required' : 'nullable', 'string', 'max:2000'],
        ], [
            'viva_date.required' => 'Enter the date of your viva voce examination.',
            'viva_date.before_or_equal' => 'The viva date cannot be in the future.',
            'date_of_birth.before' => 'A date of birth has to be in the past.',
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
                'country' => $data['country'] ?? null,
                'intake' => $data['intake'] ?? null,
                'passport_no' => $data['passport_no'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'supervisor_name' => $data['supervisor_name'],
                'viva_date' => $data['viva_date'],
                'co_supervisor_name' => $data['co_supervisor_name'] ?? null,
                'resubmission_of_id' => $replaces?->id,
                'response_to_comments' => $data['response_to_comments'] ?? null,
            ]);

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

        // The backstop, and the only check here that is the shared rule
        // rather than a restatement of it: if resubmittableFor() is ever
        // tightened, this is what enforces the tightening. It says nothing
        // about appealing -- with the Senior Executive sign-off dropped from
        // this chain, every rejection in it is a return, and the appeal is a
        // route to a ruling rather than a precondition for resubmitting.
        abort_unless(
            HardboundSubmissionDetail::resubmittableFor($request->user()->id)->whereKey($application->id)->exists(),
            403,
            'That submission is no longer awaiting correction.'
        );

        return HardboundSubmissionDetail::where('application_id', $application->id)->firstOrFail();
    }

    /**
     * The examiners the Dean appointed for this candidate, from the
     * Appointment Letter chain.
     *
     * The same people sign the Confirmation, so they are read back rather
     * than retyped -- and read from an approved appointment only, because an
     * examiner whose appointment is still moving has not been appointed yet.
     *
     * @return \Illuminate\Support\Collection<int, AppointmentExaminer>
     */
    protected function appointedExaminers(Application $application)
    {
        if (! $application->student_id) {
            return collect();
        }

        $appointments = Application::where('module_type', 'appointment_letter')
            ->where('student_id', $application->student_id)
            ->where('status', Application::STATUS_APPROVED)
            ->pluck('id');

        return AppointmentExaminer::whereIn('application_id', $appointments)
            ->orderByRaw("CASE WHEN examiner_type = 'internal' THEN 0 ELSE 1 END")
            ->get();
    }

    /**
     * Emails each appointed examiner a private link to sign the
     * Confirmation with.
     *
     * A temporary signed URL, not an account: an examiner has no login and
     * is not going to be given one for a single signature. The link carries
     * the application and the examiner it was issued for, so one examiner's
     * link cannot sign as another.
     *
     * One failing address must not stop the rest, and must not take down a
     * decision the engine has already committed -- the same reason the
     * appointment packs are dispatched one at a time.
     */
    protected function inviteExaminers(Application $application): void
    {
        $examiners = $this->appointedExaminers($application);

        if ($examiners->isEmpty() || HardboundExaminerSignature::forApplication($application->id)) {
            return;
        }

        $detail = HardboundSubmissionDetail::where('application_id', $application->id)->first();

        foreach ($examiners as $examiner) {
            $url = URL::temporarySignedRoute(
                'hardbound.examiner.sign',
                now()->addDays(HardboundExaminerSignature::LINK_DAYS),
                ['application' => $application->id, 'examiner' => $examiner->id],
            );

            try {
                Mail::to($examiner->examiner_email)->send(new ExaminerSignatureRequest(
                    $application,
                    $examiner,
                    $url,
                    $application->student?->name ?? 'the candidate',
                    $detail?->thesis_title ?? '',
                ));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * The page an examiner reaches from their emailed link.
     *
     * No `auth` middleware: the signed URL is the credential. Laravel's
     * `signed` middleware rejects a tampered or expired one before this
     * runs.
     */
    public function examinerSignForm(Request $request, Application $application, AppointmentExaminer $examiner)
    {
        $this->examinerMaySign($application, $examiner);

        return view('jason::hardbound.examiner_sign', [
            'application' => $application,
            'examiner' => $examiner,
            'student' => $application->student,
            'detail' => HardboundSubmissionDetail::where('application_id', $application->id)->first(),
            'signed' => HardboundExaminerSignature::forApplication($application->id),
        ]);
    }

    /**
     * Takes the examiner's signature and re-issues the Confirmation with it.
     */
    public function storeExaminerSignature(Request $request, Application $application, AppointmentExaminer $examiner)
    {
        $this->examinerMaySign($application, $examiner);

        if ($existing = HardboundExaminerSignature::forApplication($application->id)) {
            return back()->with('warning',
                'This form has already been signed by '.($existing->examiner?->examiner_name ?? 'an examiner').'.');
        }

        $request->validate(['signature' => HardboundExaminerSignature::rules()]);

        $file = $request->file('signature');

        $path = $file->store(HardboundExaminerSignature::DIRECTORY, 'local');

        HardboundExaminerSignature::create([
            'application_id' => $application->id,
            'appointment_examiner_id' => $examiner->id,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'signed_at' => now(),
        ]);

        // The form now carries all three signatures.
        $this->issueConfirmation($application);

        return back()->with('status', 'Thank you. Your signature is on the Confirmation of Correction to Thesis.');
    }

    /**
     * The checks that stand behind the signed URL: the link has to belong to
     * this module, to this application, and to a submission that has
     * actually reached the examiner.
     */
    protected function examinerMaySign(Application $application, AppointmentExaminer $examiner): void
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        abort_unless(
            $this->appointedExaminers($application)->contains('id', $examiner->id),
            403,
            'That signing link is not for this submission.'
        );

        // Before the Chairman approves there is nothing to countersign, and
        // after CGS accepts it the form is closed.
        abort_unless(
            $application->status === Application::STATUS_PENDING
                && $application->current_stage === self::FINAL_STAGE,
            403,
            'This form is not waiting for an examiner signature.'
        );
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

        // The examiner's block. Filled from their emailed signature where
        // one has been given, and otherwise left as the paper form has it:
        // blank, for a physical signature and official stamp.
        if ($examiner = HardboundExaminerSignature::forApplication($application->id)) {
            $signatures['examiner'] = [
                'name' => $examiner->examiner?->examiner_name ?? '—',
                'date' => $examiner->signed_at,
                'image' => $examiner->dataUri(),
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
