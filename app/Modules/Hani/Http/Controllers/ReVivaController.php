<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Models\ReVivaEvaluation;
use App\Modules\Hani\Models\ReVivaStaffDocument;
use App\Modules\Hani\Models\ReVivaSummary;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaExaminerProgress;
use App\Modules\Hani\Support\ReVivaCorrections;
use App\Modules\Hani\Support\ReVivaLetters;
use App\Modules\Hani\Support\ReVivaLogging;
use App\Modules\Hani\Support\ReVivaOutcome;
use App\Modules\Hani\Support\ReVivaWindow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReVivaController extends Controller
{
    use ApprovesApplications {
        decide as decideThroughEngine;
    }

    /** doc_type of the two uploads at logging. Stored in rows: do not rename. */
    public const DOC_THESIS = ReVivaLogging::DOC_THESIS;

    public const DOC_SUMMARY = ReVivaLogging::DOC_SUMMARY;

    /** Stage 3: the panel's re-evaluations are recorded here. */
    public const EVALUATION_STAGE = 'report_received';

    public const REPORT_MAX_KB = 10240;

    protected function moduleKey(): string
    {
        return 're_viva';
    }

    public function create(Request $request)
    {
        $students = User::where('role', Role::STUDENT)->orderBy('name')->get();

        // Every student's latest cycle in one query. This used to call
        // latestCycle() inside the loop below, which cost one query per
        // postgraduate in the system every time the form was opened.
        // Ascending order matters: keyBy() keeps the last row it sees for a
        // repeated key, so the highest cycle_number per student wins.
        $latestCycles = ReVivaDetail::with('application:id,student_id')
            ->whereHas('application', fn ($q) => $q->where('module_type', $this->moduleKey()))
            ->orderBy('cycle_number')
            ->get()
            ->keyBy(fn (ReVivaDetail $detail) => $detail->application->student_id);

        // Same pattern as the examiner dropdown on the nomination form: show
        // every student, but flag which ones CGS cannot log a new cycle for
        // right now, and why.
        $blockedReasons = $students->mapWithKeys(
            fn (User $student) => [$student->id => $this->blockReason($latestCycles->get($student->id))]
        );

        // ?student= pre-selects the candidate, from the "re-viva to log"
        // notification sent when a viva result is category 4 (or level 4).
        $preselected = $request->integer('student') ?: null;
        $preselectedStudent = $preselected ? $students->firstWhere('id', $preselected) : null;
        $window = $preselectedStudent ? ReVivaWindow::for($preselectedStudent, $latestCycles->get($preselected)) : null;
        $digitalSummary = $preselectedStudent ? ReVivaSummary::openFor($preselectedStudent) : null;

        return view('hani::re_viva.form', [
            'students' => $students,
            'blockedReasons' => $blockedReasons,
            'preselected' => $preselected,
            'preselectedStudent' => $preselectedStudent,
            'window' => $window,
            'digitalSummary' => $digitalSummary?->submitted_at ? $digitalSummary : null,
            'readyToLog' => $this->readyToLog($students, $latestCycles, $blockedReasons),
        ]);
    }

    /**
     * Students CGS is expecting a corrected thesis from: a category-4 viva
     * with no round logged for it yet, or a level-4 round with no next one.
     *
     * @return \Illuminate\Support\Collection<int, array{student: User, window: ReVivaWindow}>
     */
    protected function readyToLog($students, $latestCycles, $blockedReasons)
    {
        $candidates = VivaDetail::with('application:id,student_id')
            ->where('result_category', VivaDetail::REEXAMINATION)
            ->whereNull('examiners_freed_at')
            ->get()
            ->pluck('application.student_id')
            ->merge($latestCycles->filter(fn (ReVivaDetail $d) => $d->requiresLoopBack())->keys())
            ->unique();

        return $students
            ->filter(fn (User $s) => $candidates->contains($s->id) && $blockedReasons[$s->id] === null)
            ->map(fn (User $s) => ['student' => $s, 'window' => ReVivaWindow::for($s, $latestCycles->get($s->id))])
            ->filter(fn (array $row) => $row['window'] !== null)
            ->sortBy(fn (array $row) => $row['window']->closes?->timestamp)
            ->values();
    }

    public function store(Request $request, ReVivaLogging $logging)
    {
        $data = $request->validate([
            'student_id' => ['required', Rule::exists('users', 'id')->where('role', Role::STUDENT)],
            // PDF only: examiners open both from their letter's link.
            'thesis' => [...DocumentStore::rules(required: true), 'mimes:pdf'],
            // Or the student's checked digital summary (use_digital_summary).
            'summary_of_corrections' => [...DocumentStore::rules(required: ! $request->boolean('use_digital_summary')), 'mimes:pdf'],
            'use_digital_summary' => ['nullable', 'boolean'],
            'viva_result_date' => ['nullable', 'date', 'before_or_equal:today'],
        ], [
            'summary_of_corrections.required' => 'Upload the Summary of Corrections, or use the one the student prepared in UResearch.',
        ]);

        $student = User::findOrFail($data['student_id']);
        $summary = $request->file('summary_of_corrections');

        if (! $summary) {
            $summary = ReVivaSummary::openFor($student);
            if (! $summary?->submitted_at) {
                throw ValidationException::withMessages(['summary_of_corrections' => 'This student has no checked Summary of Corrections in UResearch. Upload the PDF instead.']);
            }
        }

        $result = $logging->log($student, $request->file('thesis'), $summary, $data['viva_result_date'] ?? null, $request->user());

        $redirect = redirect()
            ->route('reviva.create')
            ->with('status', "Re-viva round {$result['round']} logged for {$student->name}.");

        return $result['warning'] ? $redirect->with('warning', $result['warning'].' It was logged anyway; check that CGS agreed the late or early submission.') : $redirect;
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents', 'student']);

        $details = ReVivaDetail::with(['staffDocuments.examiner', 'evaluations.examiner', 'evaluations.report'])
            ->whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->each(fn (ReVivaDetail $d) => $d->setRelation('application', $queue['applications']->firstWhere('id', $d->application_id)))
            ->keyBy('application_id');

        // Stage 1: who the letters will go to, and whose email is a problem.
        // Stages 3 and 4: whose re-evaluation is recorded, and whether they diverge.
        $mains = $details->map(fn (ReVivaDetail $d) => $d->currentMains());

        return view('hani::re_viva.queue', $queue + ['details' => $details, 'mains' => $mains]);
    }

    /**
     * Stage 1 in one action: letters per current main examiner, stored
     * staff-only, stage completed through the engine, then emailed.
     */
    public function sendToPanel(Request $request, Application $application, ReVivaLetters $letters)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless($application->student->department === $request->user()->department, 403);

        $result = $letters->send($application, $request->user());

        $redirect = back()->with('status', count($result['letters']).' correction '.Str::plural('letter', count($result['letters']))
            .' generated and stored. '.($result['emailed'] ? 'Emailed to '.implode(' and ', $result['emailed']).'. ' : '')
            .'The round is now Under Panel Review.');

        return $result['skipped'] ? $redirect->with('warning', implode(' ', $result['skipped'])) : $redirect;
    }

    /**
     * The generic decision, refused where the stage needs more than a click:
     * stage 1 sends the letters (sendToPanel). ReVivaWorkflow implements
     * DecidesOneAtATime, so Core's bulk decide cannot skip this either.
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        if ($application->status === Application::STATUS_PENDING && $application->current_stage === ReVivaLetters::STAGE) {
            return back()->with('error', 'Use "Send to panel": it generates and sends the correction letters and completes this step.');
        }

        if ($application->status === Application::STATUS_PENDING && $application->current_stage === self::EVALUATION_STAGE
            && $request->input('decision') === 'approve') {
            $detail = ReVivaDetail::where('application_id', $application->id)->firstOrFail();
            if (! $detail->evaluationsComplete()) {
                return back()->with('error', 'Record both main examiners\' Thesis Re-evaluation Reports (UTP/CGS/024) before completing this step.');
            }
        }

        return $this->decideThroughEngine($request, $application, $engine);
    }

    /**
     * Stage 3: one main examiner's Thesis Re-evaluation Report (UTP/CGS/024).
     * Recording again replaces the row until the stage completes. The signed
     * form goes to the private disk, staff-only (it comes from the examiner).
     */
    public function recordEvaluation(Request $request, Application $application, string $seat)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless(array_key_exists($seat, VivaExaminerProgress::SEATS), 404);
        abort_unless($application->student->department === $request->user()->department, 403);

        if ($application->status !== Application::STATUS_PENDING || $application->current_stage !== self::EVALUATION_STAGE) {
            return back()->with('error', 'Re-evaluations are recorded at the Report Received step.');
        }

        $detail = ReVivaDetail::where('application_id', $application->id)->firstOrFail();
        $existing = $detail->currentEvaluations()->get($seat);
        $ratingKeys = fn (string $group) => array_keys(config("hani.examiner_progress.{$group}"));

        $data = $request->validate([
            'category' => ['required', 'integer', 'between:1,4'],
            'viva_again' => ['nullable', 'required_if:category,4', 'boolean'],
            'report' => [$existing ? 'nullable' : 'required', 'file', 'mimes:pdf', 'max:'.self::REPORT_MAX_KB],
            'ratings' => ['nullable', 'array'],
            'ratings.criteria' => ['nullable', 'array'],
            'ratings.criteria.*' => ['nullable', 'integer', 'between:1,5'],
            'ratings.sections' => ['nullable', 'array'],
            'ratings.sections.*' => ['nullable', 'integer', 'between:1,5'],
        ], [
            'viva_again.required_if' => 'For a fail, record whether the examiner requires the candidate to attend the viva again.',
            'report.required' => 'Upload the signed Thesis Re-evaluation Report (UTP/CGS/024).',
        ]);

        $ratings = array_filter([
            'criteria' => array_filter(array_intersect_key($data['ratings']['criteria'] ?? [], array_flip($ratingKeys('criteria')))),
            'sections' => array_filter(array_intersect_key($data['ratings']['sections'] ?? [], array_flip($ratingKeys('sections')))),
        ]);
        $user = $request->user();

        $evaluation = DB::transaction(function () use ($detail, $seat, $data, $ratings, $request, $user) {
            $locked = ReVivaDetail::whereKey($detail->id)->lockForUpdate()->firstOrFail();
            $examiner = $locked->currentMains()[$seat] ?? null;

            if (! $examiner) {
                throw ValidationException::withMessages(['category' => 'That seat has no main examiner on the panel.']);
            }

            $fill = [
                'examiner_id' => $examiner->id,
                'category' => (int) $data['category'],
                'viva_again' => (int) $data['category'] === ReVivaEvaluation::FAIL ? (bool) $data['viva_again'] : null,
                'ratings' => $ratings ?: null,
                'recorded_by_id' => $user->id,
            ];

            if ($file = $request->file('report')) {
                // Private disk, random name, OUTSIDE DocumentStore.
                $document = ReVivaStaffDocument::create([
                    're_viva_detail_id' => $locked->id,
                    'kind' => ReVivaStaffDocument::RE_EVALUATION,
                    'seat' => $seat,
                    'examiner_id' => $examiner->id,
                    'path' => $file->store('hani/reviva-reports', 'local'),
                    'original_name' => $file->getClientOriginalName() ?: 'utp-cgs-024.pdf',
                    'created_by_id' => $user->id,
                ]);
                $fill['report_document_id'] = $document->id;

                activity('document')->causedBy($user)->performedOn($document)
                    ->withProperties(['action' => 'uploaded', 'kind' => $document->kind])
                    ->log('Re-evaluation report uploaded');
            }

            return ReVivaEvaluation::updateOrCreate(['re_viva_detail_id' => $locked->id, 'seat' => $seat], $fill);
        });

        return back()->with('status', $evaluation->sideLabel().' examiner\'s re-evaluation recorded: '.$evaluation->label().'.');
    }

    /**
     * Rounds that finished the stepper with no outcome yet, for the AE's own
     * department, each with the system's suggestion.
     */
    public function outcomesIndex(Request $request)
    {
        $details = ReVivaDetail::with(['application.student', 'application.documents', 'evaluations.examiner', 'evaluations.report', 'staffDocuments.examiner'])
            ->whereNull('outcome_level')
            ->whereHas('application', fn ($q) => $q->where('module_type', $this->moduleKey())
                ->where('status', Application::STATUS_APPROVED)
                ->whereHas('student', fn ($s) => $s->where('department', $request->user()->department)))
            ->get();

        $suggestions = $details->mapWithKeys(fn (ReVivaDetail $d) => [$d->id => ReVivaOutcome::suggest($d)]);

        // Levels 1-3 still waiting for the final thesis or its acceptance.
        $finalTheses = ReVivaDetail::with(['application.student', 'application.documents', 'staffDocuments.examiner'])
            ->whereIn('outcome_level', ReVivaDetail::OUTCOME_CORRECTIONS)
            ->whereNull('corrections_accepted_at')
            ->whereHas('application', fn ($q) => $q->where('module_type', $this->moduleKey())
                ->whereHas('student', fn ($s) => $s->where('department', $request->user()->department)))
            ->orderBy('correction_deadline')
            ->get();

        return view('hani::re_viva.outcomes', ['details' => $details, 'suggestions' => $suggestions, 'finalTheses' => $finalTheses]);
    }

    /** Levels 1-3: accept the final thesis and generate the Confirmation of Correction. */
    public function acceptCorrections(Request $request, ReVivaDetail $detail, ReVivaCorrections $corrections)
    {
        abort_unless($detail->application->module_type === $this->moduleKey(), 404);
        abort_unless($detail->application->student->department === $request->user()->department, 403);

        $corrections->accept($detail, $request->user());

        return redirect()->route('reviva.outcomes.index')->with('status',
            "Corrections accepted for {$detail->application->student->name}. The Confirmation of Correction is ready for the examiners' and chairman's signatures.");
    }

    public function recordOutcome(Request $request, ReVivaDetail $detail, ReVivaOutcome $outcomes)
    {
        abort_unless($detail->application->module_type === $this->moduleKey(), 404);
        abort_unless($detail->application->student->department === $request->user()->department, 403);

        // A confirm from a page that is no longer current (a second tab, a
        // double click, Back and resubmit) used to end on a bare 404 at this
        // POST-only URL. Say what happened instead; ReVivaOutcome::record()
        // re-checks both under a row lock.
        if ($stale = $this->staleOutcome($detail)) {
            return redirect()->route('reviva.outcomes.index')->with('warning', $stale);
        }

        $data = $request->validate([
            'outcome_level' => ['required', 'integer', 'between:1,5'],
            'outcome_remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $detail = $outcomes->record($detail, (int) $data['outcome_level'], $data['outcome_remarks'] ?? null, $request->user());

        return redirect()
            ->route('reviva.outcomes.index')
            ->with('status', "Outcome recorded for {$detail->application->student->name}, round {$detail->cycle_number}: {$detail->outcomeLabel()}. The student and supervisor have been told.");
    }

    /**
     * For a route's ->missing(): the round, application or file in the URL no
     * longer exists (deleted, or the demo re-seeded under an open page).
     * Back to the page the form was on, else $fallback.
     */
    public static function gone(string $fallback): \Closure
    {
        return fn () => back(302, [], route($fallback))->with('warning',
            'That re-viva record no longer exists, so nothing was changed. It may have been removed after the page was opened (for example, the demo data was re-seeded). The page below is current.');
    }

    /** Why a confirm for $detail no longer applies, or null if it does. */
    protected function staleOutcome(ReVivaDetail $detail): ?string
    {
        $who = "{$detail->application->student->name}, round {$detail->cycle_number}";

        if ($detail->hasOutcome()) {
            $by = $detail->outcome_recorded_by_id ? User::find($detail->outcome_recorded_by_id)?->name : null;

            return "The outcome for {$who} was already recorded: {$detail->outcomeLabel()}"
                .($detail->outcome_recorded_at ? ', on '.$detail->outcome_recorded_at->format('j F Y, g:i a') : '')
                .($by ? " by {$by}" : '').'. Nothing was changed.';
        }

        if ($detail->application->status !== Application::STATUS_APPROVED) {
            return "{$who} is not ready for an outcome: consolidation is not complete. Nothing was changed.";
        }

        return null;
    }

    protected function latestCycle(int $studentId): ?ReVivaDetail
    {
        return ReVivaLogging::latestCycle($studentId);
    }

    protected function blockReason(?ReVivaDetail $latest): ?string
    {
        return ReVivaLogging::blockReason($latest);
    }
}
