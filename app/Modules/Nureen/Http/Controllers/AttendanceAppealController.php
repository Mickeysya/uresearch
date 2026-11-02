<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Nureen\Support\SubmissionNotifier;
use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Nureen\Models\AttendanceAppealDetail;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Support\AttendanceEarlyWarning;
use App\Modules\Nureen\Support\AttendanceRiskEvaluator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\UnauthorizedException;

class AttendanceAppealController extends Controller
{
    use ApprovesApplications;

    protected function moduleKey(): string
    {
        return 'attendance_appeal';
    }

    public function create(Request $request)
    {
        $requiredDocuments = config('nureen.attendance_appeal.required_documents');

        // Every period of the student's own with sessions still missed --
        // any of them can be appealed, not only one the original rule
        // flagged (a student warned by the projection alone has none).
        $periods = AttendanceRecord::where('student_id', $request->user()->id)
            ->orderByDesc('period_end')
            ->get()
            ->filter(fn (AttendanceRecord $r) => $r->sessionsMissed() > 0)
            ->values();

        $default = $periods->firstWhere('at_risk', true) ?? $periods->first();

        return view('nureen::attendance_appeal.form', [
            'requiredDocuments' => $requiredDocuments,
            'periods' => $periods,
            'defaultPeriodId' => $default?->id,
        ]);
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        $requiredDocuments = config('nureen.attendance_appeal.required_documents');

        $rules = [
            'attendance_record_id' => ['nullable', 'exists:attendance_records,id'],
            'sessions_requested' => ['nullable', 'required_with:attendance_record_id', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:20', 'max:2000'],
            // Anything else the student wants CGS to see -- still optional.
            'supporting_document' => DocumentStore::rules(),
        ];
        $messages = [
            'sessions_requested.required_with' => 'Say how many of that period\'s missed sessions this appeal covers.',
        ];

        // CGS requires both a justification letter and a monthly activity
        // breakdown -- each its own required multi-file upload, through the
        // same helper and limits GA Extension uses. See
        // config('nureen.attendance_appeal.required_documents').
        foreach ($requiredDocuments as $field => $label) {
            $rules += DocumentStore::manyRules($field);
            $messages["{$field}.required"] = "Attach your {$label}.";
            $messages += DocumentStore::manyMessages($field);
        }

        $data = $request->validate($rules, $messages);

        // If a specific record was named, it must actually be this student's,
        // and the appeal cannot ask for more sessions than were missed.
        if (! empty($data['attendance_record_id'])) {
            $record = AttendanceRecord::where('id', $data['attendance_record_id'])
                ->where('student_id', $request->user()->id)
                ->first();

            abort_unless($record, 403);

            if ((int) $data['sessions_requested'] > $record->sessionsMissed()) {
                return back()->withInput()->withErrors([
                    'sessions_requested' => "That period has {$record->sessionsMissed()} missed ".Str::plural('session', $record->sessionsMissed()).'; an appeal cannot cover more than that.',
                ]);
            }
        } else {
            $data['sessions_requested'] = null;
        }

        $application = DB::transaction(function () use ($request, $data, $requiredDocuments, $engine, $documents) {
            $application = Application::create([
                'student_id' => $request->user()->id,
                'submitted_by_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            AttendanceAppealDetail::create([
                'application_id' => $application->id,
                'attendance_record_id' => $data['attendance_record_id'] ?? null,
                'sessions_requested' => $data['sessions_requested'] ?? null,
                'reason' => $data['reason'],
            ]);

            foreach ($requiredDocuments as $field => $label) {
                $documents->attachMany($application, $request->file($field), $label);
            }

            if ($request->hasFile('supporting_document')) {
                $documents->attach($application, $request->file('supporting_document'), 'Supporting Document');
            }

            return $engine->submit($application);
        });

        // Whoever acts first is told now -- Core's engine only notifies a
        // stage when an earlier one decides. See Support\SubmissionNotifier.
        app(SubmissionNotifier::class)->notify($application);

        return redirect()
            ->route('applications.index')
            ->with('status', "Attendance appeal #{$application->id} submitted.");
    }

    /**
     * Overrides the trait's decide(): approving an appeal that names a
     * period EXCUSES the missed sessions it covers -- they count as
     * attended from then on -- and immediately re-runs that student's risk
     * check, so a student who won their appeal is not still flagged (or
     * emailed) for those absences. Rejecting, or approving a general appeal
     * that names no period, changes no figures. See DecidesOneAtATime on
     * AttendanceAppealWorkflow: Core's bulk decide must not skip this.
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $detail = AttendanceAppealDetail::where('application_id', $application->id)->first();
        $requested = $detail?->attendance_record_id ? (int) $detail->sessions_requested : 0;

        // CGS may approve FEWER sessions than the student asked for, never
        // more; left blank it defaults to the number requested.
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'sessions_approved' => ['nullable', 'integer', 'min:0', 'max:'.$requested],
        ], [
            'sessions_approved.max' => "No more than the {$requested} ".Str::plural('session', $requested).' the student asked for can be approved.',
        ]);

        try {
            $decided = $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application has already been decided.');
        }

        $status = "Application #{$application->id} ".($data['decision'] === 'approve' ? 'approved' : 'rejected').'.';

        if ($decided->status === Application::STATUS_APPROVED && $requested > 0) {
            $approved = isset($data['sessions_approved']) ? (int) $data['sessions_approved'] : $requested;
            $excused = $this->excuse($decided, $approved);

            $status .= " {$excused} of {$requested} requested ".Str::plural('session', $requested).' excused; attendance and risk status recalculated.';
        }

        return back()->with('status', $status);
    }

    /**
     * Records how many sessions CGS approved (alongside the number the
     * student requested), adds them to the period's excused sessions, and
     * re-runs the student's risk check.
     *
     * @return int sessions actually excused
     */
    protected function excuse(Application $application, int $approved): int
    {
        $detail = AttendanceAppealDetail::where('application_id', $application->id)->first();
        $record = $detail?->attendanceRecord;

        if (! $record) {
            return 0;
        }

        $excused = DB::transaction(function () use ($record, $detail, $approved) {
            $record = AttendanceRecord::lockForUpdate()->find($record->id);
            $excused = min($approved, $record->sessionsMissed());

            $detail->update(['sessions_approved' => $excused]);
            $record->update(['sessions_excused' => (int) $record->sessions_excused + $excused]);

            // The original rule reads earlier periods too, so every period of
            // this student's is re-flagged in order, not just the one changed.
            AttendanceRecord::where('student_id', $record->student_id)
                ->orderBy('period_end')
                ->get()
                ->each(fn (AttendanceRecord $r) => $r->update(['at_risk' => AttendanceRiskEvaluator::isAtRisk($r)]));

            return $excused;
        });

        // The full check (rule + projection); a student brought back above
        // the line is simply no longer at risk, and is not emailed.
        app(AttendanceEarlyWarning::class)->check($application->student);

        return $excused;
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents']);

        $details = AttendanceAppealDetail::with('attendanceRecord')
            ->whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        return view('nureen::attendance_appeal.queue', $queue + ['details' => $details]);
    }
}
