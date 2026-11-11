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
use App\Modules\Hani\Notifications\VivaAwaitingAcademicExec;
use App\Modules\Hani\Workflows\VivaVoceWorkflow;
use App\Modules\Hani\Notifications\VivaNominateExaminers;
use App\Modules\Hani\Notifications\VivaNoticeDecision;
use App\Modules\Hani\Notifications\VivaNoticeSubmitted;
use App\Modules\Hani\Support\Eligibility\EligibilityResult;
use App\Modules\Hani\Support\ModuleTracker;
use App\Modules\Hani\Support\VivaChecklist;
use App\Modules\Hani\Support\VivaProgress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

class VivaController extends Controller
{
    use ApprovesApplications;

    /** Each notice document: 10 MB (UAT 11 Nov 2026). */
    protected const DOC_MAX_KB = 10240;

    /** Publication papers on one notice. */
    protected const PAPERS_MAX = 5;

    protected function moduleKey(): string
    {
        return 'viva_voce';
    }

    // ---- Eligibility checklist + Notice of Thesis Submission (one page) -----

    /**
     * The student's viva home: the checklist on top and the notice below it,
     * sent together. Once a notice is filed, the same page shows the
     * progress panel and the checklist locked.
     */
    public function eligibility(Request $request)
    {
        $student = $request->user();
        $record = VivaChecklist::recordFor($student);
        $openNotice = VivaChecklist::openNotice($student);

        // Once filed, show what the notice was filed on; before, check live.
        $checks = $openNotice && $record?->system_checks
            ? $record->systemResults()
            : VivaChecklist::systemChecks($student);
        $eligible = VivaChecklist::isEligible($student, $record, $checks);
        $progress = VivaProgress::for($student);

        return view('hani::viva.eligibility', [
            'level' => VivaChecklist::levelFor($student),
            'items' => VivaChecklist::itemsFor($student),
            'record' => $record,
            'eligible' => $eligible,
            'checks' => $checks,
            'openNotice' => $openNotice,
            'progress' => $progress,
            'tracker' => ModuleTracker::viva($progress, $eligible),
            'earliestSoftbound' => $this->earliestSoftbound(),
            'docMaxMb' => self::DOC_MAX_KB / 1024,
            'papersMax' => self::PAPERS_MAX,
            'titleMaxWords' => VivaDetail::TITLE_MAX_WORDS,
            'limits' => config('hani.notice'),
            'returned' => $returned = VivaChecklist::returnedNotice($student),
            'draft' => $returned ? VivaDetail::where('application_id', $returned->id)->first() : null,
            'returnComment' => $returned?->history()->where('decision', 'returned')->latest('id')->value('remarks'),
        ]);
    }

    /** "Save progress": the ticks only, so a student can come back to it. */
    public function saveEligibility(Request $request): RedirectResponse
    {
        $student = $request->user();

        abort_if(VivaChecklist::itemsFor($student) === [], 403, 'Your programme does not go through a viva voce.');

        if (VivaChecklist::openNotice($student)) {
            return redirect()->route('viva.eligibility')
                ->with('error', 'Your notice has already been submitted, so the checklist is locked.');
        }

        $this->saveTicks($request, $student);

        return redirect()->route('viva.eligibility')->with('status', 'Checklist saved.');
    }

    /** The notice now lives on the eligibility page; the old URL still lands there. */
    public function notice(): RedirectResponse
    {
        return redirect()->to(route('viva.eligibility').'#notice');
    }

    /**
     * One submit for the whole page: the ticks are saved first (so they are
     * kept even if a notice field needs fixing), then every requirement must
     * hold, then the notice is filed. Also the target of the old
     * POST /viva/notice, which posts no ticks and relies on the saved ones.
     */
    public function storeNotice(Request $request, WorkflowEngine $engine, DocumentStore $documents): RedirectResponse
    {
        $student = $request->user();

        if ($open = VivaChecklist::openNotice($student)) {
            return redirect()->route('applications.show', $open)
                ->with('warning', 'You have already submitted a Notice of Thesis Submission.');
        }

        if (VivaChecklist::itemsFor($student) === []) {
            return redirect()->route('viva.eligibility')
                ->with('error', 'Your programme does not go through a viva voce, so no notice can be filed.');
        }

        if ($request->hasAny(array_keys(VivaChecklist::itemsFor($student)))) {
            $this->saveTicks($request, $student);
        }

        $checks = VivaChecklist::systemChecks($student);

        if ($reason = VivaChecklist::blockReason($student, null, $checks)) {
            return redirect()->route('viva.eligibility')->withInput()->with('error', $reason);
        }

        $limits = config('hani.notice');
        $minWords = (int) $limits['abstract_min_words'];
        $maxWords = (int) $limits['abstract_max_words'];
        $minKeywords = (int) $limits['keywords_min'];
        $maxKeywords = (int) $limits['keywords_max'];

        $data = $request->validate([
            'thesis_title' => ['required', 'string', 'max:255', VivaDetail::titleWordsRule()],
            'abstract' => ['required', 'string', 'max:6000', function ($attribute, $value, $fail) use ($minWords, $maxWords) {
                $words = VivaDetail::wordCount($value);
                if ($words < $minWords || $words > $maxWords) {
                    $fail("The abstract must be between {$minWords} and {$maxWords} words; it has {$words}.");
                }
            }],
            'keywords' => ['required', 'string', 'max:1000', function ($attribute, $value, $fail) use ($minKeywords, $maxKeywords) {
                $terms = VivaDetail::parseKeywords($value);
                if (count($terms) < $minKeywords || count($terms) > $maxKeywords) {
                    $fail("Give between {$minKeywords} and {$maxKeywords} keywords, separated by semicolons; you gave ".count($terms).'.');
                } elseif (collect($terms)->contains(fn ($t) => mb_strlen($t) > 80)) {
                    $fail('Each keyword must be 80 characters or fewer. Separate keywords with semicolons.');
                }
            }],
            'expected_softbound_date' => ['required', 'date', 'after_or_equal:'.$this->earliestSoftbound()->toDateString()],
            'publication_papers' => ['required', 'array', 'min:1', 'max:'.self::PAPERS_MAX],
            'publication_papers.*' => ['file', 'mimes:pdf', 'max:'.self::DOC_MAX_KB],
            'research_methodology_result' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:'.self::DOC_MAX_KB],
        ], [
            'expected_softbound_date.after_or_equal' => 'The notice must be submitted at least 3 months before softbound submission, so the earliest date is '
                .$this->earliestSoftbound()->format('j M Y').'.',
            'publication_papers.required' => 'Attach at least one publication paper.',
            'publication_papers.max' => 'Attach at most '.self::PAPERS_MAX.' publication papers.',
            'publication_papers.*.mimes' => 'Each publication paper must be a PDF.',
            'publication_papers.*.max' => 'Each publication paper must be 10 MB or smaller.',
            'research_methodology_result.required' => 'Attach your Research Methodology result.',
            'research_methodology_result.mimes' => 'The Research Methodology result must be a PDF, PNG or JPG.',
            'research_methodology_result.max' => 'The Research Methodology result must be 10 MB or smaller.',
        ]);

        $application = DB::transaction(function () use ($request, $student, $data, $engine, $documents, $checks) {
            // What the notice was filed on: the system's results, and a
            // verified item recorded as met.
            $verified = collect($checks)->filter(fn (EligibilityResult $r) => $r->status === EligibilityResult::VERIFIED)
                ->map(fn () => true)->all();
            VivaEligibility::updateOrCreate(['student_id' => $student->id], $verified + [
                'system_checks' => array_map(fn (EligibilityResult $r) => $r->toArray(), $checks),
                'system_checked_at' => now(),
                'verified_by_id' => null,
                'verified_at' => null,
            ]);

            // Returned by the supervisor: the same notice, edited and
            // resubmitted to the same stage (WorkflowEngine::resubmit()).
            $returned = VivaChecklist::returnedNotice($student);
            $application = $returned ?? Application::create([
                'student_id' => $student->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            VivaDetail::updateOrCreate(['application_id' => $application->id], [
                'programme_level' => VivaChecklist::levelFor($student),
                'thesis_title' => $data['thesis_title'],
                'abstract' => trim($data['abstract']),
                'keywords' => VivaDetail::parseKeywords($data['keywords']),
                'expected_softbound_date' => $data['expected_softbound_date'],
            ]);

            if ($returned) {
                // The new files replace the returned notice's.
                $old = $application->documents()->whereIn('doc_type', [VivaDetail::DOC_PAPER, VivaDetail::DOC_METHODOLOGY, VivaDetail::DOC_LEGACY_PROOF])->get();
                \Illuminate\Support\Facades\Storage::disk('local')->delete($old->pluck('path')->all());
                $old->each->delete();
            }

            foreach ($request->file('publication_papers') as $paper) {
                $documents->attach($application, $paper, VivaDetail::DOC_PAPER);
            }
            $documents->attach($application, $request->file('research_methodology_result'), VivaDetail::DOC_METHODOLOGY);

            return $returned ? $engine->resubmit($application) : $engine->submit($application);
        });

        // After the commit and best effort, for the reasons in
        // WorkflowEngine::notifyStudent(): the notice is filed either way,
        // and a mail outage must not turn that into an error page.
        try {
            $student->notify(new VivaNoticeSubmitted($application, VivaDetail::where('application_id', $application->id)->sole()));
        } catch (\Throwable $e) {
            report($e);
        }

        // The papers' automatic checks run on the queue, after the commit.
        \App\Modules\Hani\Jobs\RunNoticeChecks::dispatch($application->id);

        // With the supervisor first (UAT 11 Nov 2026); the AE hears of it
        // only once the supervisor endorses it. No supervisor on record: AE.
        $withSupervisor = $application->current_stage === VivaVoceWorkflow::SUPERVISOR_STAGE;

        if ($withSupervisor && $student->supervisor) {
            try {
                $student->supervisor->notify(new VivaAwaitingAcademicExec($application, 'Notice of Thesis Submission to endorse', 'viva.queue', $application->history()->where('decision', 'returned')->exists()));
            } catch (\Throwable $e) {
                report($e);
            }
        } else {
            VivaProgress::notifyAcademicExecs($student,
                new VivaAwaitingAcademicExec($application, 'Notice of Thesis Submission', 'viva.queue'));
        }

        return redirect()
            ->route('viva.eligibility')
            ->with('status', "Notice of Thesis Submission #{$application->id} submitted. "
                .($withSupervisor ? 'It is with your supervisor to endorse first' : 'Your Academic Executive has been notified')
                .', and a confirmation has been sent to your email.');
    }

    /**
     * Store the ticks for this student's level. Only that level's checks are
     * accepted; an MSc student cannot post rcs_passed and have it stored.
     */
    protected function saveTicks(Request $request, User $student): VivaEligibility
    {
        $items = VivaChecklist::itemsFor($student);
        $request->validate(array_fill_keys(array_keys($items), ['nullable', 'boolean']));

        $values = [];
        foreach (array_keys($items) as $column) {
            $values[$column] = $request->boolean($column);
        }

        return VivaEligibility::updateOrCreate(['student_id' => $student->id], $values);
    }

    protected function earliestSoftbound(): \Carbon\CarbonInterface
    {
        return now()->startOfDay()->addMonthsNoOverflow(3);
    }

    // ---- Supervisor (view only) ---------------------------------------------

    /**
     * The supervisor's own candidates' vivas. Read only: their action is
     * proposing examiners, which stays in Examiner Nomination.
     */
    public function supervisees(Request $request)
    {
        $notices = Application::query()
            ->where('module_type', $this->moduleKey())
            ->whereIn('status', [Application::STATUS_PENDING, Application::STATUS_APPROVED])
            ->whereIn('student_id', $request->user()->supervisees()->select('id'))
            ->with('student')
            ->orderBy('submitted_at')
            ->get();

        return view('hani::viva.supervisees', [
            'progress' => VivaProgress::forNotices($notices),
        ]);
    }

    // ---- Academic Executive queue -----------------------------------------

    /**
     * The AE's Verification, matching UCampus (UAT 11 Nov 2026):
     *   Verified  every eligibility item marked Verified; the engine's
     *             approve, stamping verified_by_id / verified_at, and the
     *             supervisor is asked to nominate examiners.
     *   Rejected  comment required; the engine's reject, and the student is
     *             emailed the comment (VivaNoticeDecision). The checklist
     *             unlocks and a new notice may be filed.
     * The per-item marks are kept on the notice (viva_details.ae_item_checks).
     * Authorisation and the stage move stay the engine's.
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $student = $application->student;
        $items = $student ? VivaChecklist::itemsFor($student) : [];

        $data = $request->validate([
            'verification' => ['required', 'in:verified,rejected'],
            'items' => ['nullable', 'array'],
            'items.*' => ['nullable', 'in:verified,not_met'],
            'comment' => ['nullable', 'required_if:verification,rejected', 'string', 'max:2000'],
        ], [
            'verification.required' => 'Choose Verified or Rejected.',
            'comment.required_if' => 'Give a comment: the student is emailed it with the rejection.',
        ]);

        $marks = collect($items)->keys()->mapWithKeys(fn ($column) => [$column => $data['items'][$column] ?? null])->all();
        $verifying = $data['verification'] === 'verified';

        if ($verifying && collect($marks)->contains(fn ($mark) => $mark !== 'verified')) {
            return back()->withInput()->withErrors(['items' => 'Mark every eligibility item Verified before verifying the notice.']);
        }

        try {
            DB::transaction(function () use ($application, $request, $engine, $data, $marks, $verifying) {
                $eligibility = VivaEligibility::where('student_id', $application->student_id)->lockForUpdate()->first();

                VivaDetail::where('application_id', $application->id)->update([
                    'ae_item_checks' => json_encode($marks), 'ae_checked_by_id' => $request->user()->id, 'ae_checked_at' => now(),
                ]);

                $engine->decide($application, $request->user(), $verifying ? 'approve' : 'reject', $data['comment'] ?? null);

                if ($application->status === Application::STATUS_APPROVED && $eligibility) {
                    $eligibility->forceFill(['verified_by_id' => $request->user()->id, 'verified_at' => now()])->save();
                }
            });
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That notice has already been decided.');
        }

        if ($application->status !== Application::STATUS_APPROVED) {
            try {
                $student?->notify(new VivaNoticeDecision($application, VivaNoticeDecision::REJECTED, (string) $data['comment'], $request->user()->name));
            } catch (\Throwable $e) {
                report($e);
            }

            return back()->with('status', "Notice #{$application->id} rejected. {$student?->name} has been emailed your comment and may file a new notice.");
        }

        $supervisor = $student?->supervisor;

        if ($supervisor) {
            try {
                $supervisor->notify(new VivaNominateExaminers(
                    $application,
                    (string) VivaDetail::where('application_id', $application->id)->value('thesis_title'),
                    $request->user()->name,
                ));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return back()->with('status', "Notice #{$application->id} verified."
            .($supervisor ? " {$supervisor->name} has been asked to nominate examiners." : ' No supervisor is recorded for this student, so nobody was asked to nominate examiners.'));
    }

    /**
     * The supervisor's endorsement (UAT 11 Nov 2026), before the AE's
     * verification. Their own students only: the engine checks the role,
     * not which supervisor a notice names, so that is checked here.
     *   endorsed  the engine's approve; the AE is told now, not on submission
     *   returned  comment required; the engine's return, so the student
     *             edits the same notice and resubmits it to the supervisor
     */
    public function endorse(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless($application->student?->supervisor_id === $request->user()->id, 403, 'Only the student\'s own supervisor endorses this notice.');

        $data = $request->validate([
            'endorsement' => ['required', 'in:endorsed,returned'],
            'comment' => ['nullable', 'required_if:endorsement,returned', 'string', 'max:2000'],
        ], [
            'endorsement.required' => 'Choose Endorse or Return.',
            'comment.required_if' => 'Give a comment: the student is emailed it and edits the notice.',
        ]);

        if ($application->current_stage !== VivaVoceWorkflow::SUPERVISOR_STAGE || $application->status !== Application::STATUS_PENDING) {
            return back()->with('error', 'That notice is no longer waiting for your endorsement.');
        }

        $endorsing = $data['endorsement'] === 'endorsed';

        try {
            $engine->decide($application, $request->user(), $endorsing ? 'approve' : 'return', $data['comment'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That notice has already been decided.');
        }

        $student = $application->student;

        if ($endorsing) {
            VivaProgress::notifyAcademicExecs($student, new VivaAwaitingAcademicExec($application, 'Notice of Thesis Submission', 'viva.queue'));

            return back()->with('status', "Notice #{$application->id} endorsed. {$student->name}'s Academic Executive has been asked to verify it.");
        }

        try {
            $student->notify(new VivaNoticeDecision($application, VivaNoticeDecision::RETURNED, (string) $data['comment'], $request->user()->name));
        } catch (\Throwable $e) {
            report($e);
        }

        return back()->with('status', "Notice #{$application->id} returned to {$student->name} with your comment.");
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $user = $request->user();
        $queue = $this->queueFor($request, $engine, ['documents', 'student.supervisor'],
            // A supervisor sees their own students' notices only.
            fn ($query) => $user->role === \App\Modules\Core\Support\Role::SUPERVISOR
                ? $query->whereHas('student', fn ($s) => $s->where('supervisor_id', $user->id))
                : null);
        $ids = $queue['applications']->pluck('id');

        return view('hani::viva.notice_queue', $queue + [
            'endorsing' => $queue['stage']->key === VivaVoceWorkflow::SUPERVISOR_STAGE,
            'details' => VivaDetail::whereIn('application_id', $ids)->get()->keyBy('application_id'),
            'eligibility' => VivaEligibility::whereIn('student_id', $queue['applications']->pluck('student_id'))->get()->keyBy('student_id'),
        ]);
    }
}
