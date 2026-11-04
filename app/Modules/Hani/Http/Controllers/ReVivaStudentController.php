<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Models\ReVivaSummary;
use App\Modules\Hani\Support\ReVivaCorrections;
use App\Modules\Hani\Support\ReVivaLogging;
use App\Modules\Hani\Support\ReVivaWindow;
use App\Modules\Hani\Support\SummaryOfCorrections;
use Illuminate\Http\Request;

/**
 * The student's own re-viva rounds: where each stands, the outcome level,
 * the final-thesis deadline and the upload (levels 1-3).
 *
 * Never an examiner's name, an individual recommendation or a staff-only
 * file: this page reads re_viva_details and the student's own DocumentStore
 * files only.
 */
class ReVivaStudentController extends Controller
{
    public function mine(Request $request)
    {
        $rounds = ReVivaDetail::with('application.documents')
            ->whereHas('application', fn ($q) => $q->where('module_type', 're_viva')->where('student_id', $request->user()->id))
            ->orderByDesc('cycle_number')
            ->get();

        return view('hani::re_viva.mine', [
            'rounds' => $rounds,
            'expecting' => $this->expecting($request->user()),
            'summary' => ReVivaSummary::openFor($request->user()),
        ]);
    }

    /**
     * The digital Summary of Corrections (nice-to-have c2): open while a
     * corrected thesis is expected (a category-4 viva or a level-4 round,
     * with no round logged yet).
     */
    public function summary(Request $request)
    {
        $student = $request->user();
        abort_unless($this->expecting($student), 403, 'There is no re-viva resubmission expected from you at the moment.');

        $summary = ReVivaSummary::openFor($student) ?? new ReVivaSummary(['student_id' => $student->id, 'rows' => []]);

        return view('hani::re_viva.summary', [
            'summary' => $summary,
            'result' => $summary->exists ? SummaryOfCorrections::check($summary) : null,
            'window' => $this->expecting($student),
        ]);
    }

    public function saveSummary(Request $request)
    {
        $student = $request->user();
        abort_unless($this->expecting($student), 403);

        $data = $request->validate([
            'rows' => ['nullable', 'array', 'max:200'],
            'rows.*.examiner' => ['required', 'integer', 'in:1,2'],
            'rows.*.comment' => ['nullable', 'string', 'max:3000'],
            'rows.*.action' => ['nullable', 'string', 'max:3000'],
            'rows.*.pages' => ['nullable', 'string', 'max:100'],
            'thesis' => [...DocumentStore::rules(), 'mimes:pdf'],
            'intent' => ['required', 'in:save,submit'],
        ]);

        $summary = ReVivaSummary::openFor($student) ?? new ReVivaSummary(['student_id' => $student->id]);

        // A row is a comment; blank lines the form offers are dropped.
        $summary->rows = collect($data['rows'] ?? [])
            ->filter(fn ($row) => filled($row['comment'] ?? null))
            ->map(fn ($row) => [
                'examiner' => (int) $row['examiner'],
                'comment' => trim($row['comment']),
                'action' => trim((string) ($row['action'] ?? '')),
                'pages' => trim((string) ($row['pages'] ?? '')),
            ])
            ->sortBy('examiner')
            ->values()
            ->all();

        // Only the page count is kept: the thesis itself goes to CGS with the summary.
        if ($file = $request->file('thesis')) {
            $summary->thesis_pages = SummaryOfCorrections::pageCount((string) file_get_contents($file->getRealPath()));
            $summary->thesis_name = $file->getClientOriginalName();
        }

        $result = SummaryOfCorrections::check($summary);
        $submit = $data['intent'] === 'submit';

        if ($submit && ! $summary->thesis_name) {
            $result['ok'] = false;
            $result['checks'][] = ['status' => SummaryOfCorrections::PROBLEM, 'text' => 'Upload the corrected thesis so the pages can be checked.'];
        }

        $summary->submitted_at = $submit && $result['ok'] ? now() : null;
        $summary->save();

        return redirect()->route('reviva.summary.edit')->with(
            $submit && ! $result['ok'] ? 'error' : 'status',
            match (true) {
                $submit && $result['ok'] => 'Summary of Corrections checked and ready. Download the PDF for your supervisor to endorse; CGS can attach it when they log your corrected thesis.',
                $submit => 'Not ready yet: see the checks below.',
                default => 'Saved.',
            },
        );
    }

    public function summaryPdf(Request $request)
    {
        $summary = ReVivaSummary::where('student_id', $request->user()->id)->whereNotNull('submitted_at')->latest('id')->firstOrFail();

        return response(SummaryOfCorrections::render($summary), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="summary-of-corrections.pdf"',
        ]);
    }

    /** The window a corrected thesis is expected in, or null if none is. */
    protected function expecting(User $student): ?ReVivaWindow
    {
        $latest = ReVivaLogging::latestCycle($student->id);

        return ReVivaLogging::blockReason($latest) === null ? ReVivaWindow::for($student, $latest) : null;
    }

    public function submitFinalThesis(Request $request, ReVivaDetail $detail, ReVivaCorrections $corrections)
    {
        abort_unless($detail->application->module_type === 're_viva' && $detail->application->student_id === $request->user()->id, 404);

        $request->validate([
            'final_thesis' => [...DocumentStore::rules(required: true), 'mimes:pdf'],
        ]);

        $corrections->submit($detail, $request->file('final_thesis'), $request->user());

        return redirect()->route('reviva.mine')->with('status', 'Final thesis submitted. Your Academic Executive has been told.');
    }
}
