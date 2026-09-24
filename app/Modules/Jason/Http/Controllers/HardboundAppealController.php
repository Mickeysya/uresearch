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
 * The candidate downloads CGS's memo, pre-filled from the portal's records
 * but blank wherever they have to speak for themselves, writes their appeal
 * on it, signs it and uploads it back -- the same way the Hardbound Thesis
 * Submission form (UTP/CGS/021) works. Nothing they write passes through the
 * portal, so nothing they write can be reworded by it.
 *
 * The memo is then routed the way the paper one is: through the Supervisor,
 * then the HOD/Chair, then to CGS. Their endorsements cannot be written into
 * the candidate's own file -- stamping inside an uploaded PDF needs a
 * PDF-editing library this project does not carry, and composer.json belongs
 * to the whole team -- so they are stamped onto an endorsement slip that
 * travels with it, carrying the same signatures on file as the Confirmation
 * of Correction.
 *
 * The Dean's "Approved / Not Approved" block stays blank. CGS takes the pack
 * to the Dean off-portal and emails the candidate the outcome, so the portal
 * never claims to know whether the extension was granted.
 */
class HardboundAppealController extends Controller
{
    use ApprovesApplications;

    /** The candidate's own completed memo, as they uploaded it. */
    public const DOC_MEMO = 'Appeal Memo';

    /** The generated slip carrying the endorsements that memo collected. */
    public const DOC_ENDORSEMENT = 'Endorsement Slip';

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

        $data = $request->validate([
            'original_deadline' => ['nullable', 'date'],
            'requested_until' => ['required', 'date', 'after:today'],
            'memo' => DocumentStore::rules(required: true),
        ], [
            'requested_until.required' => 'Give the date you are asking to submit by.',
            'requested_until.after' => 'The extension date has to be in the future.',
            'memo.required' => 'Attach your completed memo. Download the blank above, fill it in and sign it first.',
        ]);

        $application = DB::transaction(function () use ($request, $data, $engine) {
            $application = Application::create([
                'student_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            HardboundAppealDetail::create([
                'application_id' => $application->id,
                'original_deadline' => $data['original_deadline'] ?? null,
                'requested_until' => $data['requested_until'],
            ]);

            // The candidate's own file, stored exactly as they uploaded it.
            app(DocumentStore::class)->attach($application, $request->file('memo'), self::DOC_MEMO);

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

        if (! $refusing && in_array($stage?->key, self::SIGNING_STAGES, true)) {
            // Re-issue the slip carrying this endorsement.
            $this->issueEndorsementSlip($application);
        }

        if (! $refusing && $stage?->key === 'cgs_review') {
            $application->student?->notify(new ExtensionMemoReceived($application));
        }

        return back()->with('status', match (true) {
            $refusing => "Appeal #{$application->id} was not endorsed, and the candidate has been told why.",
            $stage?->key === 'cgs_review' => "Memo received for appeal #{$application->id}. "
                .'The candidate has been told to wait for further notification.',
            default => "Appeal #{$application->id} endorsed, and your signature is on the endorsement slip.",
        });
    }

    /**
     * The blank memo, pre-filled with everything the portal already knows
     * about the candidate and left empty wherever they have to speak for
     * themselves. They complete it, sign it, and upload it back.
     */
    public function template(Request $request)
    {
        $student = $request->user();

        $pdf = Pdf::loadView('jason::hardbound_appeal.memo', [
            'application' => null,
            'student' => $student,
            'dean' => User::where('role', Role::DEAN_PGR)->orderBy('name')->first(),
            'supervisorName' => $this->nameOfRole($student, Role::SUPERVISOR),
            'chairName' => $this->nameOfRole($student, Role::CHAIR),
            'issuedAt' => now(),
        ]);

        return response()->streamDownload(
            fn () => print($pdf->output()),
            'Appeal-for-Extension-of-Hardbound-Thesis-Submission.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * The candidate's own copy of the memo, at whatever stage it has reached.
     */
    public function memo(Request $request, Application $application)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless($application->student_id === $request->user()->id, 403);

        $document = ApplicationDocument::where('application_id', $application->id)
            ->where('doc_type', self::DOC_MEMO)
            ->latest('id')
            ->firstOrFail();

        return redirect()->route('documents.show', $document);
    }

    /**
     * Generates the endorsement slip with every endorsement collected so far
     * and archives it, replacing the previous copy so the appeal carries
     * exactly one current version alongside the candidate's memo.
     *
     * Each endorsement's signature and date come from the approval_history
     * row the engine wrote and that endorser's own uploaded signature, so
     * the slip and the audit trail cannot disagree.
     */
    protected function issueEndorsementSlip(Application $application): void
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

        $pdf = Pdf::loadView('jason::hardbound_appeal.endorsement', [
            'application' => $application,
            'detail' => $detail,
            'student' => $student,
            'dean' => User::where('role', Role::DEAN_PGR)->orderBy('name')->first(),
            'signatures' => $signatures,
        ]);

        // One current version: the previous slip goes when the new one lands.
        // The candidate's uploaded memo is never touched.
        ApplicationDocument::where('application_id', $application->id)
            ->where('doc_type', self::DOC_ENDORSEMENT)
            ->get()
            ->each->delete();

        app(DocumentStore::class)->storeGenerated(
            $application,
            $pdf->output(),
            "Endorsement-Slip-{$application->id}.pdf",
            self::DOC_ENDORSEMENT,
        );
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
