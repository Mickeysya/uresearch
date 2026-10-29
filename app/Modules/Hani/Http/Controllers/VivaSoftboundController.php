<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaSoftboundDetail;
use App\Modules\Hani\Notifications\VivaSoftboundReturned;
use App\Modules\Hani\Notifications\VivaSoftboundSubmitted;
use App\Modules\Hani\Support\VivaProgress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

/**
 * Softbound thesis (CGS Online Form #019): the student files it once their
 * notice is acknowledged; the Academic Executive verifies it or returns it.
 */
class VivaSoftboundController extends Controller
{
    use ApprovesApplications;

    /** Thesis PDF. Larger than DocumentStore's 10 MB: a full thesis with figures. */
    protected const THESIS_MAX_KB = 20480;

    protected const REPORT_MAX_KB = 5120;

    protected function moduleKey(): string
    {
        return VivaProgress::SOFTBOUND;
    }

    public function create(Request $request)
    {
        $progress = VivaProgress::for($request->user());

        if ($reason = $progress->softboundBlockReason()) {
            return redirect()->route('viva.eligibility')->with('error', $reason);
        }

        $level = VivaDetail::where('application_id', $progress->notice->id)->value('programme_level');

        return view('hani::viva.softbound_form', [
            'wordLimit' => VivaSoftboundDetail::wordLimitFor($level),
            'similarityMax' => VivaSoftboundDetail::SIMILARITY_MAX,
            'returnReason' => $progress->softboundReturnReason(),
        ]);
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents): RedirectResponse
    {
        $student = $request->user();
        $progress = VivaProgress::for($student);

        if ($reason = $progress->softboundBlockReason()) {
            return redirect()->route('viva.eligibility')->with('error', $reason);
        }

        $notice = $progress->notice;
        $wordLimit = VivaSoftboundDetail::wordLimitFor(
            VivaDetail::where('application_id', $notice->id)->value('programme_level')
        );

        $data = $request->validate([
            'similarity_percent' => ['required', 'numeric', 'min:0', 'max:'.VivaSoftboundDetail::SIMILARITY_MAX],
            'word_count' => ['required', 'integer', 'min:1', 'max:'.$wordLimit],
            'abstract_bilingual' => ['accepted'],
            'copies_certified' => ['accepted'],
            'before_candidacy_end' => ['accepted'],
            'similarity_report' => ['required', 'file', 'mimes:pdf', 'max:'.self::REPORT_MAX_KB],
            'thesis' => ['required', 'file', 'mimes:pdf', 'max:'.self::THESIS_MAX_KB],
        ], [
            'similarity_percent.max' => 'Form #019 needs a similarity index of '.VivaSoftboundDetail::SIMILARITY_MAX.'% or below.',
            'word_count.max' => 'Your thesis must be '.number_format($wordLimit).' words or fewer.',
            'abstract_bilingual.accepted' => 'The thesis needs an abstract in both English and Bahasa Melayu.',
            'copies_certified.accepted' => 'Two copies must be certified by your supervisor.',
            'before_candidacy_end.accepted' => 'The softbound must be submitted before your candidacy ends.',
            'similarity_report.mimes' => 'The similarity report must be a PDF.',
            'similarity_report.max' => 'The similarity report must be 5 MB or smaller.',
            'thesis.mimes' => 'The thesis must be a PDF.',
            'thesis.max' => 'The thesis must be 20 MB or smaller.',
        ]);

        [$application, $detail] = DB::transaction(function () use ($request, $student, $notice, $data, $engine, $documents) {
            $application = Application::create([
                'student_id' => $student->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            $detail = VivaSoftboundDetail::create([
                'application_id' => $application->id,
                'viva_application_id' => $notice->id,
                'similarity_percent' => $data['similarity_percent'],
                'word_count' => $data['word_count'],
                'abstract_bilingual' => true,
                'copies_certified' => true,
                'before_candidacy_end' => true,
            ]);

            $documents->attach($application, $request->file('thesis'), 'Softbound Thesis');
            $documents->attach($application, $request->file('similarity_report'), 'Similarity Report');

            return [$engine->submit($application), $detail];
        });

        // After the commit and best effort; see WorkflowEngine::notifyStudent().
        try {
            $student->notify(new VivaSoftboundSubmitted($application, $detail));
        } catch (\Throwable $e) {
            report($e);
        }

        return redirect()
            ->route('viva.eligibility')
            ->with('status', "Softbound thesis #{$application->id} submitted. A confirmation has been sent to your email.");
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents']);

        $details = VivaSoftboundDetail::with('vivaApplication')
            ->whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        $nominations = VivaProgress::nominationsFor($queue['applications']->pluck('student_id'));

        return view('hani::viva.softbound_queue', $queue + [
            'details' => $details,
            'nominations' => $nominations,
        ]);
    }

    /**
     * Approve verifies the thesis. Reject RETURNS it: that attempt closes,
     * the notice stays open, and the student files again. A return without
     * a reason leaves the student nothing to act on, so it is refused here
     * (as a flashed error, since the shared form labels remarks optional and
     * renders no validation bag; the same approach as Jason's hardbound).
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $returning = $data['decision'] === 'reject';

        if ($returning && blank($data['remarks'] ?? null)) {
            return back()->with('error', 'Returning a softbound thesis needs remarks. Say what the student has to fix, then press Reject again.');
        }

        try {
            $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That softbound thesis has already been decided.');
        }

        if ($returning) {
            try {
                $application->student?->notify(
                    new VivaSoftboundReturned($application, $request->user()->name, $data['remarks'])
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return back()->with('status', $returning
            ? "Softbound thesis #{$application->id} returned to the student."
            : "Softbound thesis #{$application->id} verified.");
    }
}
