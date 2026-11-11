<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Hani\Jobs\RunSoftboundChecks;
use App\Modules\Hani\Models\VivaSoftboundCheck;
use App\Modules\Hani\Models\VivaSoftboundDetail;
use App\Modules\Hani\Notifications\VivaAwaitingAcademicExec;
use App\Modules\Hani\Notifications\VivaSoftboundReturned;
use App\Modules\Hani\Notifications\VivaSoftboundSubmitted;
use App\Modules\Hani\Support\ModuleTracker;
use App\Modules\Hani\Support\Similarity\SimilaritySource;
use App\Modules\Hani\Support\SoftboundChecks;
use App\Modules\Hani\Support\VivaProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

/**
 * Softbound thesis (CGS Online Form #019, UTP/CGS/V3/VV/019): the student files it once their
 * notice is verified; the Academic Executive verifies it or returns it.
 */
class VivaSoftboundController extends Controller
{
    use ApprovesApplications;

    protected function moduleKey(): string
    {
        return VivaProgress::SOFTBOUND;
    }

    public function create(Request $request)
    {
        $student = $request->user();
        $progress = VivaProgress::for($student);

        if ($reason = $progress->softboundBlockReason()) {
            return redirect()->route('viva.eligibility')->with('error', $reason);
        }

        $level = $progress->detail?->programme_level;

        return view('hani::viva.softbound_form', [
            'tracker' => ModuleTracker::viva($progress, true),
            'student' => $student,
            'level' => $level,
            'noticeTitle' => $progress->detail?->thesis_title,
            'academicExecs' => VivaProgress::academicExecsFor($student),
            'uploads' => VivaSoftboundDetail::uploadsFor($level),
            'wordLimit' => VivaSoftboundDetail::wordLimitFor($level),
            'similarityMax' => VivaSoftboundDetail::SIMILARITY_MAX,
            'returnReason' => $progress->softboundReturnReason(),
            'ulearnUrl' => config('hani.similarity.ulearn_url'),
        ]);
    }

    /**
     * Reads the Turnitin report the student has just chosen, before they
     * submit, so the form can fill in the similarity index for them to
     * confirm. The file is read in memory and never stored; the real reading
     * happens again on submit, from the stored file.
     */
    public function similarityPreview(Request $request, SimilaritySource $source): JsonResponse
    {
        abort_if(VivaProgress::for($request->user())->softboundBlockReason() !== null, 403);

        $request->validate([
            'turnitin_report' => ['required', 'file', 'mimes:pdf', 'max:'.VivaSoftboundDetail::UPLOADS['turnitin_report'][1]],
        ]);

        $reading = $source->read((string) file_get_contents($request->file('turnitin_report')->getRealPath()));

        return response()->json($reading->ok ? [
            'ok' => true,
            'simulated' => $reading->source === 'simulated',
            'index' => $reading->index,
            'internet' => $reading->internet,
            'publications' => $reading->publications,
            'student_papers' => $reading->studentPapers,
            'title' => $reading->title,
            'sources' => array_map(fn ($s) => "{$s['rank']}. {$s['source']} ({$s['type']}) {$s['percent']}", $reading->sources),
            'over_limit' => $reading->index > VivaSoftboundDetail::SIMILARITY_MAX,
        ] : [
            'ok' => false,
            'message' => $reading->failure,
        ]);
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents, SimilaritySource $source): RedirectResponse
    {
        $student = $request->user();
        $progress = VivaProgress::for($student);

        if ($reason = $progress->softboundBlockReason()) {
            return redirect()->route('viva.eligibility')->with('error', $reason);
        }

        $notice = $progress->notice;
        $resubmitting = $progress->softboundReturnReason() !== null;
        $level = $progress->detail?->programme_level;
        $wordLimit = VivaSoftboundDetail::wordLimitFor($level);
        $uploads = VivaSoftboundDetail::uploadsFor($level);

        $rules = [
            // Section 1: Student Details (the rest is read from the student's record)
            'field_of_study' => ['required', 'string', 'max:255'],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s]{7,30}$/'],
            'personal_email' => ['required', 'email', 'max:150'],
            // Section 2: Submission Details
            'session' => ['required', 'in:'.implode(',', VivaSoftboundDetail::SESSIONS)],
            'year' => ['required', 'integer', 'in:'.implode(',', VivaSoftboundDetail::years())],
            'thesis_title' => ['required', 'string', 'max:255', \App\Modules\Hani\Models\VivaDetail::titleWordsRule()],
            'similarity_percent' => ['required', 'numeric', 'min:0', 'max:'.VivaSoftboundDetail::SIMILARITY_MAX],
            'word_count' => ['required', 'integer', 'min:1', 'max:'.$wordLimit],
            // Section 3: Approval & Notifications
            'co_supervisor_email' => ['nullable', 'email', 'max:150', 'ends_with:@utp.edu.my'],
            // Section 4: Agreement
            'declaration' => ['accepted'],
        ];

        foreach ($uploads as $field => [, $maxKb]) {
            $rules[$field] = ['required', 'file', 'mimes:pdf', 'max:'.$maxKb];
        }

        $messages = [
            'contact_number.regex' => 'Enter a phone number using digits, spaces, + or - only.',
            'similarity_percent.max' => 'The similarity index must be '.VivaSoftboundDetail::SIMILARITY_MAX.'% or below.',
            'word_count.max' => 'Your thesis must be '.number_format($wordLimit).' words or fewer.',
            'co_supervisor_email.ends_with' => 'Use your co-supervisor\'s UTP email address (ending in @utp.edu.my).',
            'declaration.accepted' => 'You must accept the declaration to submit.',
        ];

        foreach ($uploads as $field => [$label, $maxKb]) {
            $messages[$field.'.required'] = "Upload the {$label}.";
            $messages[$field.'.mimes'] = "The {$label} must be a PDF.";
            $messages[$field.'.max'] = "The {$label} must be ".($maxKb / 1024).' MB or smaller.';
        }

        $data = $request->validate($rules, $messages);

        // Read the report the student uploaded. If it can be read, its index
        // is held to the same limit as the declared one; if not, the declared
        // figure stands and is marked self-declared.
        $reading = $source->read((string) file_get_contents($request->file('turnitin_report')->getRealPath()));

        if ($reading->ok && $reading->index > VivaSoftboundDetail::SIMILARITY_MAX) {
            return back()->withInput()->withErrors([
                'similarity_percent' => 'Your Turnitin report shows a similarity index of '.SoftboundChecks::pc($reading->index)
                    .'%, above the '.VivaSoftboundDetail::SIMILARITY_MAX.'% limit. Revise the thesis and generate a new report before submitting.',
            ]);
        }

        [$application, $detail] = DB::transaction(function () use ($request, $student, $notice, $data, $uploads, $engine, $documents, $reading) {
            $application = Application::create([
                'student_id' => $student->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            $detail = VivaSoftboundDetail::create([
                'application_id' => $application->id,
                'viva_application_id' => $notice->id,
                'declaration_accepted' => true,
                'co_supervisor_email' => $data['co_supervisor_email'] ?? null,
                'similarity_source' => match (true) {
                    ! $reading->ok => 'self_declared',
                    $reading->source === 'simulated' => 'simulated',
                    default => 'extracted',
                },
                'similarity_extracted' => $reading->ok ? $reading->index : null,
            ] + Arr::only($data, [
                'field_of_study', 'contact_number', 'personal_email', 'session', 'year',
                'thesis_title', 'similarity_percent', 'word_count',
            ]));

            foreach ($uploads as $field => [$label]) {
                $documents->attach($application, $request->file($field), $label);
            }

            VivaSoftboundCheck::create([
                'application_id' => $application->id,
                'status' => VivaSoftboundCheck::QUEUED,
                'similarity' => $reading->toArray(),
            ]);

            return [$engine->submit($application), $detail];
        });

        // The thesis checks (up to 50 MB of PDF) run on the queue.
        RunSoftboundChecks::dispatch($application->id);

        // After the commit and best effort; see WorkflowEngine::notifyStudent().
        try {
            $student->notify(new VivaSoftboundSubmitted($application, $detail));
        } catch (\Throwable $e) {
            report($e);
        }

        VivaProgress::notifyAcademicExecs($student,
            new VivaAwaitingAcademicExec($application, 'softbound thesis', 'viva.softbound.queue', $resubmitting));

        return redirect()
            ->route('viva.eligibility')
            ->with('status', "Softbound thesis #{$application->id} submitted. Your Academic Executive has been notified, and a confirmation has been sent to your email.");
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents']);

        $details = VivaSoftboundDetail::with('vivaApplication')
            ->whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        $nominations = VivaProgress::nominationsFor($queue['applications']->pluck('student_id'));

        $checks = VivaSoftboundCheck::whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        return view('hani::viva.softbound_queue', $queue + [
            'details' => $details,
            'checks' => $checks,
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
