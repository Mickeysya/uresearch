<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaEligibility;
use App\Modules\Hani\Notifications\VivaNoticeSubmitted;
use App\Modules\Hani\Support\VivaChecklist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VivaController extends Controller
{
    use ApprovesApplications;

    /** Publication proof: PDF only, 5 MB. Tighter than DocumentStore's default. */
    protected const PROOF_MAX_KB = 5120;

    protected function moduleKey(): string
    {
        return 'viva_voce';
    }

    // ---- Eligibility checklist ---------------------------------------------

    public function eligibility(Request $request)
    {
        $student = $request->user();
        $record = VivaChecklist::recordFor($student);

        return view('hani::viva.eligibility', [
            'level' => VivaChecklist::levelFor($student),
            'items' => VivaChecklist::itemsFor($student),
            'record' => $record,
            'eligible' => VivaChecklist::isEligible($student, $record),
            'openNotice' => VivaChecklist::openNotice($student),
        ]);
    }

    public function saveEligibility(Request $request): RedirectResponse
    {
        $student = $request->user();
        $items = VivaChecklist::itemsFor($student);

        abort_if($items === [], 403, 'Your programme does not go through a viva voce.');

        // Once the notice is filed the checklist is what it was filed on.
        if (VivaChecklist::openNotice($student)) {
            return redirect()->route('viva.eligibility')
                ->with('error', 'Your notice has already been submitted, so the checklist is locked.');
        }

        // Only the checks for this student's level are accepted; an MSc
        // student cannot post rcs_passed and have it stored.
        $rules = array_fill_keys(array_keys($items), ['nullable', 'boolean']);
        $request->validate($rules + ['continue' => ['nullable', 'boolean']]);

        $values = [];
        foreach (array_keys($items) as $column) {
            $values[$column] = $request->boolean($column);
        }

        $record = VivaEligibility::updateOrCreate(['student_id' => $student->id], $values);

        if ($request->boolean('continue')) {
            return VivaChecklist::isEligible($student, $record)
                ? redirect()->route('viva.notice')
                : redirect()->route('viva.eligibility')
                    ->with('error', 'Tick every requirement before submitting the notice.');
        }

        return redirect()->route('viva.eligibility')->with('status', 'Checklist saved.');
    }

    // ---- Notice of Thesis Submission ---------------------------------------

    public function notice(Request $request)
    {
        if ($redirect = $this->noticeGuard($request->user())) {
            return $redirect;
        }

        return view('hani::viva.notice', [
            'level' => VivaChecklist::levelFor($request->user()),
            'earliestSoftbound' => $this->earliestSoftbound(),
            'proofMaxMb' => self::PROOF_MAX_KB / 1024,
        ]);
    }

    public function storeNotice(Request $request, WorkflowEngine $engine, DocumentStore $documents): RedirectResponse
    {
        $student = $request->user();

        if ($redirect = $this->noticeGuard($student)) {
            return $redirect;
        }

        $data = $request->validate([
            'thesis_title' => ['required', 'string', 'max:255'],
            'expected_softbound_date' => ['required', 'date', 'after_or_equal:'.$this->earliestSoftbound()->toDateString()],
            'publication_proof' => ['required', 'file', 'mimes:pdf', 'max:'.self::PROOF_MAX_KB],
        ], [
            'expected_softbound_date.after_or_equal' => 'The notice must be submitted at least 3 months before softbound submission, so the earliest date is '
                .$this->earliestSoftbound()->format('j M Y').'.',
            'publication_proof.mimes' => 'Publication proof must be a PDF.',
            'publication_proof.max' => 'Publication proof must be 5 MB or smaller.',
        ]);

        $application = DB::transaction(function () use ($request, $student, $data, $engine, $documents) {
            $application = Application::create([
                'student_id' => $student->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            VivaDetail::create([
                'application_id' => $application->id,
                'programme_level' => VivaChecklist::levelFor($student),
                'thesis_title' => $data['thesis_title'],
                'expected_softbound_date' => $data['expected_softbound_date'],
            ]);

            $documents->attach($application, $request->file('publication_proof'), 'Publication Proof');

            return $engine->submit($application);
        });

        // After the commit and best effort, for the reasons in
        // WorkflowEngine::notifyStudent(): the notice is filed either way,
        // and a mail outage must not turn that into an error page.
        try {
            $student->notify(new VivaNoticeSubmitted($application, VivaDetail::where('application_id', $application->id)->sole()));
        } catch (\Throwable $e) {
            report($e);
        }

        return redirect()
            ->route('applications.index')
            ->with('status', "Notice of Thesis Submission #{$application->id} submitted. A confirmation has been sent to your email.");
    }

    /**
     * The server-side gate on both notice routes. The disabled buttons on the
     * checklist and the form are a convenience; this is the rule.
     */
    protected function noticeGuard(User $student): ?RedirectResponse
    {
        if ($open = VivaChecklist::openNotice($student)) {
            return redirect()->route('applications.show', $open)
                ->with('warning', 'You have already submitted a Notice of Thesis Submission.');
        }

        if (! VivaChecklist::isEligible($student)) {
            return redirect()->route('viva.eligibility')
                ->with('error', 'Complete the eligibility checklist before submitting the notice.');
        }

        return null;
    }

    protected function earliestSoftbound(): \Carbon\CarbonInterface
    {
        return now()->startOfDay()->addMonthsNoOverflow(3);
    }

    // ---- Academic Executive queue -----------------------------------------

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents']);

        $details = VivaDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        return view('hani::viva.queue', $queue + ['details' => $details]);
    }
}
