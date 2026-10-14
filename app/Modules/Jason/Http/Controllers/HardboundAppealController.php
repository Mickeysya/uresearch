<?php

namespace App\Modules\Jason\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Jason\Models\HardboundAppealDetail;
use App\Modules\Jason\Models\HardboundSignature;
use App\Modules\Jason\Notifications\ExtensionMemoReceived;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

/**
 * Appeal Hardbound Submission: an appeal for an extension of the hardbound
 * thesis submission deadline.
 *
 * The candidate fills in a form and the portal writes CGS's memo from it:
 * the reason in their own words, the date they are asking for, and the rest
 * from records the portal already holds. Nothing is downloaded, filled in by
 * hand and uploaded back (which is how this worked between 2026-09-24 and
 * 2026-10-14), and nothing is archived either -- the memo is rendered on
 * demand, every time somebody opens it, from the appeal's own row and the
 * signatures on file. An appeal therefore costs no document storage at all.
 *
 * It is routed the way the paper memo is: through the Supervisor, then the
 * HOD/Chair, then to CGS, and because the portal writes the document their
 * signatures are stamped straight into it under the Through lines. The
 * candidate's own signature comes from the same "My Signature" upload the
 * endorsers use -- `hardbound_signatures` is keyed on the user account, so
 * one row per person covers every form they sign.
 *
 * The Dean's "Approved / Not Approved" block stays blank. CGS takes the pack
 * to the Dean off-portal and emails the candidate the outcome, so the portal
 * never claims to know whether the extension was granted.
 */
class HardboundAppealController extends Controller
{
    use ApprovesApplications;

    /**
     * Appeals filed before 2026-10-14 carry the candidate's uploaded memo as
     * a document; nothing new writes one. `memo()` still serves those.
     */
    public const DOC_MEMO = 'Appeal Memo';

    /** The stages whose endorsement is stamped into the memo. */
    public const SIGNING_STAGES = ['supervisor', 'chair'];

    protected function moduleKey(): string
    {
        return 'hardbound_appeal';
    }

    public function create(Request $request)
    {
        return view('jason::hardbound_appeal.form', [
            'student' => $request->user(),
            'open' => $this->openRequest($request->user()->id),
            // The memo is signed with whatever they uploaded at "My
            // Signature"; without one it would go out over a blank rule.
            'signature' => HardboundSignature::forUser($request->user()->id),
        ]);
    }

    public function store(Request $request, WorkflowEngine $engine)
    {
        // One at a time. A second memo while the first is still moving would
        // reach CGS as two appeals for the same deadline.
        if ($open = $this->openRequest($request->user()->id)) {
            return back()->with('error',
                "Appeal #{$open->id} is still being processed. Wait for its outcome before filing another.");
        }

        // The memo is addressed to the Dean and carries the candidate's own
        // "Yours sincerely" block. Letting it go out over a blank rule was
        // the one thing this design could get wrong, so it is gated the same
        // way an endorser's approval is: upload the signature, then file.
        if (! HardboundSignature::forUser($request->user()->id)) {
            return redirect()->route('hardbound.signature')
                ->with('error', 'Upload your signature first. It is stamped onto the memo that goes to the '
                    .'Dean, under your name and student ID.');
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:4000'],
            'original_deadline' => ['nullable', 'date'],
            'requested_until' => ['required', 'date', 'after:today'],
        ], [
            'reason.required' => 'Say why you cannot submit by the deadline. This is the body of the memo.',
            'requested_until.required' => 'Give the date you are asking to submit by.',
            'requested_until.after' => 'The extension date has to be in the future.',
        ]);

        $application = DB::transaction(function () use ($request, $data, $engine) {
            $application = Application::create([
                'student_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            HardboundAppealDetail::create([
                'application_id' => $application->id,
                'reason' => $data['reason'],
                'original_deadline' => $data['original_deadline'] ?? null,
                'requested_until' => $data['requested_until'],
            ]);

            // Nothing is archived: the memo is these three fields plus the
            // signatures on file, and is drawn from them whenever it is read.
            return $engine->submit($application);
        });

        return redirect()
            ->route('applications.index')
            ->with('status', "Appeal #{$application->id} filed. Your memo has gone to your supervisor for endorsement.");
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents', 'student']);

        return view('jason::hardbound_appeal.queue', $queue + [
            'details' => HardboundAppealDetail::whereIn('application_id', $queue['applications']->pluck('id'))
                ->get()
                ->keyBy('application_id'),
        ]);
    }

    /**
     * Overrides the trait's decide() for the two things this chain does that
     * the generic one cannot: an endorsement stamps a signature into the
     * memo, and CGS receiving the memo has to be announced as a receipt
     * rather than as the approval the engine would otherwise imply.
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $stage = $engine->currentStage($application);
        $refusing = $request->input('decision') === 'reject';

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        // Refusing to endorse ends the appeal, so the candidate has to be
        // told why. Checked here rather than as a validation rule because
        // the shared decision form labels remarks "(optional)" and nothing
        // renders the error bag; a flashed error the layout does render.
        if ($refusing && blank($data['remarks'] ?? null)) {
            return back()->with('error',
                'Refusing an appeal needs remarks — tell the candidate why, then press Reject again.');
        }

        // Endorsing stamps a signature into the memo, so there has to be one.
        if (! $refusing && in_array($stage?->key, self::SIGNING_STAGES, true)
            && ! HardboundSignature::forUser($request->user()->id)) {
            return redirect()->route('hardbound.signature')
                ->with('error', 'Upload your signature first. Endorsing an appeal stamps it onto the memo.');
        }

        try {
            $application = $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That appeal has already been decided.');
        }

        if (! $refusing && $stage?->key === 'cgs_review') {
            $application->student?->notify(new ExtensionMemoReceived($application));
        }

        return back()->with('status', match (true) {
            $refusing => "Appeal #{$application->id} was not endorsed, and the candidate has been told why.",
            $stage?->key === 'cgs_review' => "Memo received for appeal #{$application->id}. "
                .'The candidate has been told to wait for further notification.',
            default => "Appeal #{$application->id} endorsed, and your signature is on the memo.",
        });
    }

    /**
     * The memo, drawn on demand.
     *
     * Nothing is archived: everything the document says lives in the
     * appeal's own row and in the signatures on file, so it is rendered
     * fresh each time rather than stored. An appeal filed before
     * 2026-10-14 carries the candidate's uploaded file instead, and that is
     * served as it always was.
     *
     * Readable by the candidate and by the three roles that hold a stage in
     * this chain -- with nothing in the documents list, this is the only way
     * an endorser reads what they are endorsing.
     */
    public function memo(Request $request, Application $application)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $user = $request->user();
        $stageRoles = array_map(fn ($stage) => $stage->role, app(WorkflowEngine::class)->stagesFor($application));

        abort_unless(
            $application->student_id === $user->id || in_array($user->role, $stageRoles, true),
            403
        );

        // Filed under the upload version: their own file is the memo.
        if ($uploaded = ApplicationDocument::where('application_id', $application->id)
            ->where('doc_type', self::DOC_MEMO)
            ->latest('id')
            ->first()) {
            return redirect()->route('documents.show', $uploaded);
        }

        $pdf = Pdf::loadView('jason::hardbound_appeal.memo', $this->memoData($application));

        return response()->streamDownload(
            fn () => print($pdf->output()),
            "Appeal-for-Extension-{$application->id}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Everything the memo prints.
     *
     * The endorsements come from the `approval_history` rows the engine
     * wrote and each endorser's own uploaded signature, so the stamped memo
     * and the audit trail cannot disagree about who signed or when. The
     * candidate's signature comes from the same place as theirs.
     *
     * The Dean's "Approved / Not Approved" block stays blank: the Dean signs
     * the memo itself, off-portal, and CGS emails the candidate the outcome.
     *
     * @return array<string, mixed>
     */
    protected function memoData(Application $application): array
    {
        $detail = HardboundAppealDetail::where('application_id', $application->id)->firstOrFail();
        $student = $application->student;

        $signatures = [];

        foreach ($application->history()->with('approver')->get() as $entry) {
            if (! in_array($entry->stage_key, self::SIGNING_STAGES, true) || $entry->decision === 'rejected') {
                continue;
            }

            $signature = $entry->approver_id ? HardboundSignature::forUser($entry->approver_id) : null;

            $signatures[$entry->stage_key] = [
                'name' => $entry->approver?->name ?? '',
                'date' => $entry->created_at?->format('j F Y') ?? '',
                'image' => $signature?->dataUri(),
            ];
        }

        return [
            'application' => $application,
            'detail' => $detail,
            'student' => $student,
            'dean' => User::where('role', Role::DEAN_PGR)->orderBy('name')->first(),
            'supervisorName' => $this->nameOfRole($student, Role::SUPERVISOR),
            'chairName' => $this->nameOfRole($student, Role::CHAIR),
            'signatures' => $signatures,
            'studentSignature' => $student ? HardboundSignature::forUser($student->id)?->dataUri() : null,
            'issuedAt' => $application->created_at ?? now(),
        ];
    }

    /**
     * The name that goes on a "Through:" line. The memo names the people who
     * route it, and the portal knows them only by role and department.
     */
    protected function nameOfRole(?User $student, string $role): string
    {
        if ($role === Role::SUPERVISOR && $student?->supervisor_id) {
            return User::find($student->supervisor_id)?->name ?? '';
        }

        return User::where('role', $role)
            ->where('department', $student?->department)
            ->orderBy('name')
            ->value('name') ?? '';
    }

    /**
     * The candidate's appeal that is still moving, if any.
     *
     * "Still moving" means a stage actually owns it. An application left on
     * a stage key this module no longer declares -- as every appeal filed
     * under the old meaning of this module was, and as anything would be
     * after a future stage rename -- resolves to no stage, so no queue can
     * ever reach it and no decision can ever close it. Counting one of those
     * as open would lock the candidate out of the module permanently, which
     * is worse than letting them file again.
     */
    protected function openRequest(int $studentId): ?Application
    {
        $stages = array_map(fn ($stage) => $stage->key, app(WorkflowEngine::class)->stagesFor(
            Application::make(['module_type' => $this->moduleKey()])
        ));

        return Application::where('module_type', $this->moduleKey())
            ->where('student_id', $studentId)
            ->where('status', Application::STATUS_PENDING)
            ->whereIn('current_stage', $stages)
            ->latest('id')
            ->first();
    }
}
