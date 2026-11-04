<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaExaminerProgress;
use App\Modules\Hani\Support\ModuleTracker;
use App\Modules\Hani\Support\VivaProgress;
use App\Modules\Hani\Support\VivaResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Step 5: the AE records the result from the chairman's viva report, and
 * accepts the corrected final thesis; the student uploads it. See
 * Support\VivaResult for what each category does.
 */
class VivaResultController extends Controller
{
    /** Chairman's viva report: PDF, 10 MB. Corrected thesis: PDF, 50 MB, like the softbound. */
    protected const REPORT_MAX_KB = 10240;

    protected const THESIS_MAX_KB = 51200;

    public function index(Request $request)
    {
        $notices = Application::query()
            ->where('module_type', VivaProgress::NOTICE)
            ->where('status', Application::STATUS_APPROVED)
            ->whereHas('student', fn ($q) => $q->where('department', $request->user()->department))
            ->whereIn('id', VivaDetail::whereNotNull('viva_at')->select('application_id'))
            ->with('student', 'documents')
            ->get();

        $groups = VivaProgress::forNotices($notices)->groupBy(function (VivaProgress $p) {
            $d = $p->detail;

            return match (true) {
                ! $d->hasResult() => $d->vivaHeld() ? 'awaiting' : 'upcoming',
                $d->needsCorrections() && $d->corrections_accepted_at === null => $d->corrections_submitted_at ? 'submitted' : 'corrections',
                $d->result_category === VivaDetail::REEXAMINATION => 'reexamination',
                default => 'closed',
            };
        });

        return view('hani::viva.results', [
            'groups' => $groups,
            'categories' => VivaExaminerProgress::CATEGORIES,
        ]);
    }

    public function record(Request $request, Application $application, VivaResult $results): RedirectResponse
    {
        $this->authoriseAe($request, $application);

        $data = $request->validate([
            'result_category' => ['required', 'integer', 'between:1,5'],
            'viva_report' => ['required', 'file', 'mimes:pdf', 'max:'.self::REPORT_MAX_KB],
        ], [
            'viva_report.required' => 'Upload the chairman\'s viva report.',
            'viva_report.mimes' => 'The viva report must be a PDF.',
        ]);

        $detail = $results->record($application, (int) $data['result_category'], $request->file('viva_report'), $request->user());

        return redirect()->route('viva.results.index')->with('status', match (true) {
            $detail->needsCorrections() => "Result {$detail->resultLabel()} recorded for {$application->student->name}. Corrections are due by {$detail->corrections_due_at->format('j M Y')}; the student has been told, and the examiners' evaluation is marked complete.",
            $detail->result_category === VivaDetail::REEXAMINATION => "Result 4 (Re-examination) recorded for {$application->student->name}. The Senior Executive has been asked to log the re-viva, and the main examiners stay assigned for it.",
            default => "Result 5 (Fail) recorded for {$application->student->name}. This is final; the examiners' evaluation is marked complete.",
        });
    }

    public function accept(Request $request, Application $application, VivaResult $results): RedirectResponse
    {
        $this->authoriseAe($request, $application);
        $results->acceptCorrections($application, $request->user());

        return redirect()->route('viva.results.index')
            ->with('status', "Corrections accepted for {$application->student->name}. Their viva is complete.");
    }

    // ---- Student ---------------------------------------------------------

    public function corrections(Request $request)
    {
        $progress = VivaProgress::for($request->user());

        if (! $progress->detail?->needsCorrections() || $progress->detail->corrections_accepted_at) {
            return redirect()->route('viva.eligibility')->with('error', 'There are no corrections to submit.');
        }

        return view('hani::viva.corrections', [
            'progress' => $progress,
            'tracker' => ModuleTracker::viva($progress, true),
            'maxMb' => self::THESIS_MAX_KB / 1024,
        ]);
    }

    public function submitCorrections(Request $request, VivaResult $results): RedirectResponse
    {
        $progress = VivaProgress::for($request->user());
        abort_unless($progress->notice && $progress->detail, 404);

        $request->validate([
            'corrected_thesis' => ['required', 'file', 'mimes:pdf', 'max:'.self::THESIS_MAX_KB],
        ], [
            'corrected_thesis.mimes' => 'The corrected thesis must be a PDF.',
            'corrected_thesis.max' => 'The corrected thesis must be '.(self::THESIS_MAX_KB / 1024).' MB or smaller.',
        ]);

        $results->submitCorrections($progress->notice, $request->file('corrected_thesis'), $request->user());

        return redirect()->route('viva.eligibility')
            ->with('status', 'Corrected final thesis submitted. Your Academic Executive has been notified.');
    }

    protected function authoriseAe(Request $request, Application $application): void
    {
        abort_unless($application->module_type === VivaProgress::NOTICE && $application->status === Application::STATUS_APPROVED, 404);
        abort_unless($application->student?->department === $request->user()->department, 403, 'That student is not in your department.');
    }
}
