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
use App\Modules\Hani\Notifications\VivaNominateExaminers;
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

    /** Publication proof: PDF only, 5 MB. Tighter than DocumentStore's default. */
    protected const PROOF_MAX_KB = 5120;

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
            'proofMaxMb' => self::PROOF_MAX_KB / 1024,
            'limits' => config('hani.notice'),
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
            'thesis_title' => ['required', 'string', 'max:255'],
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
            'publication_proof' => ['required', 'file', 'mimes:pdf', 'max:'.self::PROOF_MAX_KB],
        ], [
            'expected_softbound_date.after_or_equal' => 'The notice must be submitted at least 3 months before softbound submission, so the earliest date is '
                .$this->earliestSoftbound()->format('j M Y').'.',
            'publication_proof.mimes' => 'Publication proof must be a PDF.',
            'publication_proof.max' => 'Publication proof must be 5 MB or smaller.',
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

            $application = Application::create([
                'student_id' => $student->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            VivaDetail::create([
                'application_id' => $application->id,
                'programme_level' => VivaChecklist::levelFor($student),
                'thesis_title' => $data['thesis_title'],
                'abstract' => trim($data['abstract']),
                'keywords' => VivaDetail::parseKeywords($data['keywords']),
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

        VivaProgress::notifyAcademicExecs($student,
            new VivaAwaitingAcademicExec($application, 'Notice of Thesis Submission', 'viva.queue'));

        return redirect()
            ->route('viva.eligibility')
            ->with('status', "Notice of Thesis Submission #{$application->id} submitted. Your Academic Executive has been notified, and a confirmation has been sent to your email.");
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
     * The shared decide, plus the guideline's next step: once the AE has
     * acknowledged the notice, the supervisor is asked to nominate examiners.
     * Acknowledging also confirms the eligibility summary on the card
     * (verified_by_id / verified_at); that is not a stage of its own.
     * Authorisation and the stage move stay the engine's.
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            DB::transaction(function () use ($application, $request, $engine, $data) {
                $eligibility = VivaEligibility::where('student_id', $application->student_id)->lockForUpdate()->first();

                $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);

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
            return back()->with('status', "Notice #{$application->id} rejected.");
        }

        $supervisor = $application->student?->supervisor;

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

        return back()->with('status', "Notice #{$application->id} acknowledged and eligibility confirmed."
            .($supervisor ? " {$supervisor->name} has been asked to nominate examiners." : ' No supervisor is recorded for this student, so nobody was asked to nominate examiners.'));
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents']);

        $details = VivaDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        $eligibility = VivaEligibility::whereIn('student_id', $queue['applications']->pluck('student_id'))
            ->get()
            ->keyBy('student_id');

        return view('hani::viva.queue', $queue + ['details' => $details, 'eligibility' => $eligibility]);
    }
}
