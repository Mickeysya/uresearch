<?php

namespace App\Modules\Jason\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Jason\Models\HardboundAppealDetail;
use App\Modules\Jason\Models\HardboundSubmissionDetail;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\UnauthorizedException;

class HardboundAppealController extends Controller
{
    use ApprovesApplications;

    protected function moduleKey(): string
    {
        return 'hardbound_appeal';
    }

    public function create(Request $request)
    {
        return view('jason::hardbound_appeal.form', [
            'submissions' => $this->appealable($request->user()->id),
        ]);
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        $appealable = $this->appealable($request->user()->id);

        $data = $request->validate([
            'hardbound_application_id' => ['required', Rule::in($appealable->pluck('id'))],
            'justification' => ['required', 'string', 'max:2000'],
            'appeal_memo' => DocumentStore::rules(required: true),
        ], [
            'hardbound_application_id.in' => 'You may only appeal your own rejected hardbound submission.',
            'appeal_memo.required' => 'Attach your appeal memo.',
        ]);

        $application = DB::transaction(function () use ($request, $data, $engine, $documents) {
            $application = Application::create([
                'student_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            HardboundAppealDetail::create([
                'application_id' => $application->id,
                'hardbound_application_id' => $data['hardbound_application_id'],
                'justification' => $data['justification'],
            ]);

            $documents->attach($application, $request->file('appeal_memo'), 'Appeal Memo');

            return $engine->submit($application);
        });

        return redirect()
            ->route('applications.index')
            ->with('status', "Appeal #{$application->id} filed and sent to CGS.");
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents', 'student']);

        $details = HardboundAppealDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        // The submission each appeal is against, so the reviewer can read
        // both sides on one screen.
        $originals = HardboundSubmissionDetail::whereIn('application_id', $details->pluck('hardbound_application_id'))
            ->get()
            ->keyBy('application_id');

        return view('jason::hardbound_appeal.queue', $queue + [
            'details' => $details,
            'originals' => $originals,
        ]);
    }

    /**
     * Overrides the trait's decide() so the Non-Executive's recommendation is
     * captured and compiled into the Dean PFR report before the Senior
     * Executive rules on it.
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $stage = $engine->currentStage($application);
        $compiling = $request->input('decision') === 'approve' && $stage?->key === 'cgs_review';

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            // The report is the thing the Senior Executive rules on, so the
            // recommendation that fills it cannot be blank.
            'remarks' => [$compiling ? 'required' : 'nullable', 'string', 'max:2000'],
        ], [
            'remarks.required' => 'Write the recommendation that goes into the Dean PFR report.',
        ]);

        $detail = HardboundAppealDetail::where('application_id', $application->id)->firstOrFail();

        try {
            $application = $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That appeal has already been decided.');
        }

        if ($compiling) {
            $detail->update(['pfr_recommendation' => $data['remarks']]);
            $this->compileDeanPfrReport($application, $detail->fresh());
        }

        return back()->with('status', match (true) {
            $compiling => "Dean PFR report compiled for appeal #{$application->id} and sent for ruling.",
            $data['decision'] === 'approve' => "Appeal #{$application->id} upheld. The student may now resubmit.",
            default => "Appeal #{$application->id} dismissed.",
        });
    }

    /**
     * The Dean PFR report: the appeal, the submission it is against and the
     * Non-Executive's recommendation on one document, archived against the
     * appeal so the Senior Executive rules on a fixed record.
     */
    protected function compileDeanPfrReport(Application $application, HardboundAppealDetail $detail): void
    {
        $original = $detail->hardboundApplication;

        $pdf = Pdf::loadView('jason::hardbound_appeal.pfr_report', [
            'application' => $application,
            'detail' => $detail,
            'student' => $application->student,
            'original' => $original,
            'originalDetail' => $detail->hardboundDetail(),
            'originalHistory' => $original?->history()->with('approver')->get() ?? collect(),
            'issuedAt' => now(),
        ]);

        app(DocumentStore::class)->storeGenerated(
            $application,
            $pdf->output(),
            "Dean-PFR-Report-{$application->id}.pdf",
            'Dean PFR Report',
        );
    }

    /**
     * A student's rejected hardbound submissions that have neither been
     * appealed already nor already replaced by a resubmission.
     */
    protected function appealable(int $studentId)
    {
        $alreadyAppealed = HardboundAppealDetail::query()->pluck('hardbound_application_id');
        $alreadyReplaced = HardboundSubmissionDetail::whereNotNull('resubmission_of_id')->pluck('resubmission_of_id');

        return Application::query()
            ->where('module_type', 'hardbound_submission')
            ->where('student_id', $studentId)
            ->where('status', Application::STATUS_REJECTED)
            ->whereNotIn('id', $alreadyAppealed)
            ->whereNotIn('id', $alreadyReplaced)
            ->orderByDesc('id')
            ->get();
    }
}
