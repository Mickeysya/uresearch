<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Models\VivaExaminerProgress;
use App\Modules\Hani\Support\ReVivaCorrections;
use App\Modules\Hani\Support\ReVivaLetters;
use App\Modules\Hani\Support\ReVivaLogging;
use App\Modules\Hani\Support\ReVivaOutcome;
use App\Modules\Hani\Support\ReVivaProcess;
use App\Modules\Hani\Support\ReVivaSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The Senior Executive's and the Dean's re-viva actions. */
class ReVivaController extends Controller
{
    use ApprovesApplications;

    public const DOC_THESIS = ReVivaLogging::DOC_THESIS;

    public const DOC_SUMMARY = ReVivaLogging::DOC_SUMMARY;

    public function __construct(protected ReVivaProcess $process) {}

    protected function moduleKey(): string
    {
        return 're_viva';
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents', 'student']);

        $details = ReVivaDetail::with(['staffDocuments.examiner', 'evaluations.examiner', 'evaluations.report', 'documentReturns.returnedBy'])
            ->whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->each(fn (ReVivaDetail $d) => $d->setRelation('application', $queue['applications']->firstWhere('id', $d->application_id)))
            ->keyBy('application_id');

        return view('hani::re_viva.queue', $queue + [
            'details' => $details,
            'mains' => $details->map(fn (ReVivaDetail $d) => $d->currentMains()),
            'suggestions' => $details->map(fn (ReVivaDetail $d) => ReVivaOutcome::suggest($d)),
        ]);
    }

    /** One POST per action; the engine and ReVivaProcess re-check role and stage. */
    public function act(Request $request, Application $application, string $action)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        $user = $request->user();

        if ($action === 'send-to-panel') {
            $result = app(ReVivaLetters::class)->send($application, $user);
            $lines = array_merge(array_map(fn ($n) => "Email sent to {$n}.", $result['emailed']), array_map(fn ($p) => "Not sent: {$p}", $result['skipped']));

            return $result['moved']
                ? back()->with('status', implode(' ', $lines).' The round is now Under Panel Review.')
                : back()->with('error', implode(' ', $lines).' The round stays at Ready to Send. Fix the problem and press Send to panel again: only the examiners not yet emailed are sent to.');
        }

        $message = match ($action) {
            'return' => $this->returnDocuments($request, $application, $user),
            'accept' => [$this->process->accept($application, $user), 'Documents accepted. The letters and report forms have been generated.'][1],
            'regenerate' => [$this->process->regenerate($application, $user), 'The letters and report forms have been regenerated.'][1],
            'send-to-dean' => [$this->process->sendToDean($application, $user), 'Sent to the Dean of PGR.'][1],
            'dean-approve' => [$this->process->deanApprove($application, $user), 'Approved. The Senior Executive has been told.'][1],
            'dean-return' => $this->deanReturn($request, $application, $user),
            'start-review' => [$this->process->startReview($application, $user), 'The round is now at CGS Review.'][1],
            'decide-outcome' => $this->outcome($request, $application, $user, false),
            'schedule-meeting' => $this->meeting($request, $application, $user),
            'meeting-result' => $this->outcome($request, $application, $user, true),
            default => abort(404),
        };

        return back()->with('status', $message);
    }

    protected function returnDocuments(Request $request, Application $application, User $user): string
    {
        $data = $request->validate([
            'documents' => ['required', 'array', 'min:1'],
            'documents.*' => [Rule::in(ReVivaLogging::REQUIRED)],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $this->process->returnToStudent($application, $user, $data['documents'], $data['reason']);

        return 'Returned to the student.';
    }

    protected function deanReturn(Request $request, Application $application, User $user): string
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->process->deanReturn($application, $user, $data['reason']);

        return 'Returned to the Senior Executive.';
    }

    protected function outcome(Request $request, Application $application, User $user, bool $afterMeeting): string
    {
        $data = $request->validate([
            'outcome_level' => ['required', 'integer', 'between:1,5'],
            'outcome_remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $afterMeeting
            ? $this->process->recordMeetingResult($application, $user, (int) $data['outcome_level'], $data['outcome_remarks'] ?? null)
            : $this->process->decideWithoutMeeting($application, $user, (int) $data['outcome_level'], $data['outcome_remarks'] ?? null);

        return 'Result recorded. The student and supervisor have been told.';
    }

    protected function meeting(Request $request, Application $application, User $user): string
    {
        $this->process->scheduleMeeting($application, $user, $request->validate([
            'meeting_kind' => ['required', Rule::in([ReVivaProcess::CONSOLIDATION, ReVivaProcess::RE_VIVA])],
            'meeting_at' => ['required', 'date', 'after:now'],
            'meeting_venue' => ['required', 'string', 'max:255'],
            // Only when the linked viva has no chairman on record.
            'meeting_chair_name' => ['nullable', 'required_with:meeting_chair_email', 'string', 'max:150'],
            'meeting_chair_email' => ['nullable', 'required_with:meeting_chair_name', 'email', 'max:150'],
        ]));

        return 'Meeting scheduled and invitations sent.';
    }

    /** The Senior Executive records a return on an examiner's behalf, or corrects one. */
    public function recordEvaluation(Request $request, Application $application, string $seat)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless(array_key_exists($seat, VivaExaminerProgress::SEATS), 404);
        $detail = ReVivaDetail::where('application_id', $application->id)->firstOrFail();
        $existing = $detail->currentEvaluations()->get($seat);

        $evaluation = $this->process->recordEvaluation($detail, $seat, self::validateReturn($request, $existing !== null), $request->file('report'), $request->file('signed_letter'), $request->user(), false);

        return back()->with('status', $evaluation->sideLabel().' examiner\'s re-evaluation recorded: '.$evaluation->label().'.');
    }

    public static function validateReturn(Request $request, bool $hasExisting): array
    {
        $file = [$hasExisting ? 'nullable' : 'required', 'file', 'mimes:pdf', 'max:'.((int) config('hani.reviva.upload_max_mb', 20) * 1024)];

        return $request->validate([
            'category' => ['required', 'integer', 'between:1,4'],
            'viva_again' => ['nullable', 'required_if:category,4', 'boolean'],
            'report' => $file,
            'signed_letter' => $file,
        ], ['viva_again.required_if' => 'For a fail, record whether the candidate must attend the viva again.']);
    }

    /** Levels 1-3 waiting for the final thesis, and past results. */
    public function outcomesIndex()
    {
        $with = ['application.student', 'application.documents', 'evaluations.examiner', 'evaluations.report', 'staffDocuments.examiner'];

        return view('hani::re_viva.outcomes', [
            'finalTheses' => ReVivaDetail::with($with)->whereIn('outcome_level', ReVivaDetail::OUTCOME_CORRECTIONS)->whereNull('corrections_accepted_at')
                ->whereHas('application', fn ($q) => $q->where('module_type', $this->moduleKey()))->orderBy('correction_deadline')->get(),
            'results' => ReVivaDetail::with($with)->whereNotNull('outcome_level')
                ->whereHas('application', fn ($q) => $q->where('module_type', $this->moduleKey()))->latest('outcome_recorded_at')->limit(50)->get(),
        ]);
    }

    public function acceptCorrections(Request $request, ReVivaDetail $detail, ReVivaCorrections $corrections)
    {
        abort_unless($detail->application->module_type === $this->moduleKey(), 404);
        $corrections->accept($detail, $request->user());

        return redirect()->route('reviva.outcomes.index')->with('status', "Corrections accepted for {$detail->application->student->name}.");
    }

    public function settings()
    {
        $levels = collect(ReVivaSettings::LEVELS)->map(fn ($label, $level) => [
            'label' => $label,
            'days' => ReVivaSettings::reminderDays($level),
            'configured' => ReVivaSettings::configured($level),
            'limit' => (int) config("hani.reviva.panel_working_days.{$level}", 20),
        ])->all();

        return view('hani::re_viva.settings', ['levels' => $levels]);
    }

    public function saveSettings(Request $request)
    {
        $rules = [];
        foreach (array_keys(ReVivaSettings::LEVELS) as $level) {
            $max = (int) config("hani.reviva.panel_working_days.{$level}", 20);
            $rules["days.{$level}"] = ['required', 'array', 'min:1', 'max:5'];
            $rules["days.{$level}.*"] = ['nullable', 'integer', 'between:1,'.$max];
        }
        $data = $request->validate($rules);

        $saved = [];
        foreach (ReVivaSettings::LEVELS as $level => $label) {
            $days = collect($data['days'][$level])->filter()->map(fn ($d) => (int) $d)->unique()->sortDesc()->values()->all();
            if ($days === []) {
                return back()->withErrors(["days.{$level}" => "Enter at least one reminder for a {$label} thesis."])->withInput();
            }
            ReVivaSettings::saveReminderDays($level, $days, $request->user()->id);
            $saved[] = "{$label}: ".implode(', ', $days);
        }

        return back()->with('status', 'Reminders saved, in working days before the deadline. '.implode('. ', $saved).'.');
    }

    /** For a route's ->missing(): the record in the URL no longer exists. */
    public static function gone(string $fallback): \Closure
    {
        return fn () => back(302, [], route($fallback))->with('warning', 'That re-viva record no longer exists, so nothing was changed. The page below is current.');
    }
}
