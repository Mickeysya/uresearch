<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Exports\ExaminerListExport;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\ExaminerReminderLog;
use App\Modules\Hani\Models\ExaminerSeatHistory;
use App\Modules\Hani\Support\PanelSeats;
use App\Modules\Hani\Notifications\ExaminersReleased;
use App\Modules\Hani\Notifications\NominationsNeedCompileReview;
use App\Modules\Hani\Support\ConflictOfInterest;
use App\Modules\Hani\Support\CrossDepartmentClashes;
use App\Modules\Hani\Support\Departments;
use App\Modules\Hani\Support\DuplicateDetector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\UnauthorizedException;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

class ExaminerNominationController extends Controller
{
    use ApprovesApplications;

    /**
     * The stage that can send a list back to the department, and the stage it
     * goes back to. Both the Senior Director and the Dean sign off a compiled
     * faculty list; neither of them rejects it outright, because the thing
     * that has to change is one department's choice of examiner.
     */
    protected const RETURNING_STAGES = ['senior_director', 'dean'];

    protected const RETURN_TARGET = 'academic_exec';

    /** The stage whose Approve also releases the list -- see finaliseAndRelease(). */
    public const FINALISE_STAGE = 'cgs_final';

    /** examiner_reminder_logs.kind for the compile-review notification. */
    public const COMPILE_REVIEW = 'compile_review';

    /** Who may read the compiled list, and whether they see every department or one. */
    protected const REPORT_ROLES = [
        Role::ACADEMIC_EXEC, Role::SENIOR_EXEC_CGS, Role::SENIOR_DIRECTOR_CGS,
        Role::DEAN_PGR, Role::NON_EXEC_CGS,
    ];

    protected function moduleKey(): string
    {
        return 'examiner_nomination';
    }

    public function create(Request $request)
    {
        return view('hani::examiner_nomination.form', [
            // Only the supervisor's own candidates may be nominated for.
            'candidates' => $request->user()->supervisees()->where('role', Role::STUDENT)->get(),
            'internals' => Examiner::where('type', Examiner::TYPE_INTERNAL)->orderBy('name')->get(),
            'externals' => Examiner::where('type', Examiner::TYPE_EXTERNAL)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, WorkflowEngine $engine, Departments $departments, ConflictOfInterest $coi)
    {
        $slots = array_keys(ExaminerNomination::SLOTS);

        $data = $request->validate([
            'student_id' => [
                'required',
                Rule::exists('users', 'id')->where('supervisor_id', $request->user()->id),
            ],
            'thesis_title' => ['required', 'string', 'max:255'],
            'internal_main_id' => ['required', 'exists:examiners,id'],
            'internal_backup_id' => ['nullable', 'different:internal_main_id', 'exists:examiners,id'],
            'external_main_id' => ['required', 'exists:examiners,id'],
            'external_backup_id' => ['nullable', 'different:external_main_id', 'exists:examiners,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'student_id.exists' => 'You may only nominate examiners for your own candidates.',
            'internal_backup_id.different' => 'The internal backup must differ from the internal main examiner.',
            'external_backup_id.different' => 'The external backup must differ from the external main examiner.',
        ]);

        $student = \App\Modules\Core\Models\User::findOrFail($data['student_id']);

        foreach ($slots as $slot) {
            if (empty($data[$slot])) {
                continue;
            }

            $examiner = Examiner::findOrFail($data[$slot]);
            $which = ExaminerNomination::SLOTS[$slot];

            // Touchpoint 1: eligible at the exact moment of nomination --
            // not assigned, not on gap, not marked unavailable.
            if (! $examiner->isEligible()) {
                throw ValidationException::withMessages([
                    $slot => "{$which} examiner {$examiner->name} is not eligible. "
                        .$examiner->ineligibilityReason(),
                ]);
            }

            // An internal slot takes an internal examiner and an external
            // slot an external one. Checked rather than assumed: the two
            // lists are rendered separately, so getting here with the wrong
            // type means the ids were posted by hand.
            $expected = str_starts_with($slot, 'internal')
                ? Examiner::TYPE_INTERNAL
                : Examiner::TYPE_EXTERNAL;

            if ($examiner->type !== $expected) {
                throw ValidationException::withMessages([
                    $slot => "{$which} must be an {$expected} examiner; {$examiner->name} is {$examiner->type}.",
                ]);
            }

            // THE DEPARTMENT RULE. A candidate is examined internally by
            // their own department and nobody else's -- that is what makes
            // the examiner "internal" in the first place. Enforced here
            // because no foreign key can express "the same department as the
            // student on the application this row belongs to", and because
            // this is the one place where the supervisor can still be told
            // which department was expected.
            if ($expected === Examiner::TYPE_INTERNAL && ! $departments->same($examiner->department, $student->department)) {
                throw ValidationException::withMessages([
                    $slot => "{$which} must come from {$student->name}'s own department ("
                        .($student->department ?: 'none recorded').'). '
                        ."{$examiner->name} is in {$examiner->department}.",
                ]);
            }
        }

        // UTP/CGS/026: the candidate's supervisor or co-supervisor can never
        // examine them. The other conflict-of-interest rules are warnings,
        // shown to everyone in the chain; this one is a refusal.
        foreach ($slots as $slot) {
            if (empty($data[$slot])) {
                continue;
            }

            $flags = $coi->flagsFor(Examiner::findOrFail($data[$slot]), $student);

            if ($coi->blocks($flags)) {
                throw ValidationException::withMessages([
                    $slot => collect($flags)->firstWhere('level', ConflictOfInterest::BLOCK)['message'],
                ]);
            }
        }

        // The same person cannot hold two seats on one panel, whichever two.
        $picked = array_filter(array_map(fn ($slot) => $data[$slot] ?? null, $slots));

        if (count($picked) !== count(array_unique($picked))) {
            throw ValidationException::withMessages([
                'internal_main_id' => 'Each seat on the panel must be a different examiner.',
            ]);
        }

        $application = DB::transaction(function () use ($request, $data, $slots, $engine) {
            $application = Application::create([
                // The candidate the nomination concerns, so it shows on their
                // tracking page and they receive the notifications.
                'student_id' => $data['student_id'],
                // The supervisor who actually filed it.
                'submitted_by_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            $nomination = ExaminerNomination::create([
                'application_id' => $application->id,
                'thesis_title' => $data['thesis_title'],
                'notes' => $data['notes'] ?? null,
            ] + array_combine($slots, array_map(fn ($slot) => $data[$slot] ?? null, $slots)));

            // The panel's history starts here (Support\PanelSeats).
            foreach ($slots as $slot) {
                if (! empty($data[$slot])) {
                    ExaminerSeatHistory::create([
                        'nomination_id' => $nomination->id, 'seat' => $slot, 'examiner_id' => $data[$slot],
                        'event' => 'nominated', 'actor_id' => $request->user()->id,
                    ]);
                }
            }

            return $engine->submit($application);
        });

        // Touchpoint 2, early: another department already has one of these
        // examiners on its list. A warning, not a refusal -- the lists are
        // compiled at CGS and the substitution is decided there.
        $clashes = collect($picked)
            ->flatMap(fn ($id) => $this->clashes()->flags((int) $id, $student->department, $application->id))
            ->pluck('message')
            ->unique();

        return redirect()
            ->route('examiner-nomination.create')
            ->with('status', "Nomination #{$application->id} submitted to the Academic Executive.")
            ->with('warning', $clashes->isEmpty() ? null : 'Please note: '.$clashes->implode(' '));
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['submittedBy']);

        $nominations = ExaminerNomination::with(array_merge(ExaminerNomination::slotRelations(), ['application.student']))
            ->whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        return view('hani::examiner_nomination.queue', $queue + [
            'nominations' => $nominations,
            // Compilation is where department lists meet, so it is where two
            // records of one person on different departments' lists can
            // first be seen (Support\DuplicateDetector).
            'coiFlags' => $this->coiFlags($nominations),
            'duplicates' => $queue['stage']->key === 'cgs_compile'
                ? app(DuplicateDetector::class)->acrossNominations()
                : [],
            // Only the two stages above the compilation may send a list back
            // to the department; everyone else gets the plain two buttons.
            'canReturn' => in_array($queue['stage']->key, self::RETURNING_STAGES, true),
        ]);
    }

    /**
     * Overrides ApprovesApplications::decide() to close the loop the trait
     * cannot know about: when the chain finishes, the panel is released and
     * its two main examiners tied up (Support\PanelSeats, the only code that
     * writes Examiner::assigned_until for a panel), so an examiner cannot
     * silently drift back to "Available" while still on an active case.
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $finalising = $data['decision'] === 'approve' && $application->current_stage === self::FINALISE_STAGE;

        try {
            $finalising
                ? $this->finaliseAndRelease($application, $request->user(), $engine, $data['remarks'] ?? null)
                : $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application has already been decided.');
        }

        $this->notifyCompileFindings($application);

        // Tie the examiners up only when the chain has actually FINISHED, not
        // merely when this approver said yes -- six stages now sit between
        // the supervisor and the final release, and tying a panel up at the
        // first approval would hold four examiners for 180 days while the
        // list was still being argued over.
        if ($application->refresh()->status === Application::STATUS_APPROVED) {
            $nomination = ExaminerNomination::where('application_id', $application->id)->first();

            if ($nomination) {
                app(PanelSeats::class)->release($nomination, $request->user());
            }

            $this->announceRelease($application);
        }

        $verb = $finalising
            ? 'finalised and released to CGS operations'
            : ($data['decision'] === 'approve' ? 'approved' : 'rejected');

        return back()->with('status', "Application #{$application->id} {$verb}.");
    }

    /**
     * A list arriving at cgs_compile with a cross-department clash or a likely
     * duplicate record: tell the Senior Executive, once per arrival, with the
     * reasons and a link to Conflict Detection. Best effort and after commit -- a mail
     * failure must never undo the decision that has just been recorded.
     */
    protected function notifyCompileFindings(Application $application): void
    {
        $application->refresh();

        if ($application->status !== Application::STATUS_PENDING || $application->current_stage !== 'cgs_compile') {
            return;
        }

        try {
            $nomination = ExaminerNomination::with(ExaminerNomination::slotRelations())
                ->where('application_id', $application->id)->first();
            $department = $application->student?->department;

            $findings = collect($nomination?->examiners() ?? [])
                ->flatMap(fn (Examiner $e) => collect($this->clashes()->flags($e->id, $department, $application->id))
                    ->map(fn ($flag) => "{$e->name}: {$flag['message']}"))
                ->merge(app(DuplicateDetector::class)->acrossNominations()[$application->id] ?? [])
                ->unique()
                ->values()
                ->all();

            if ($findings === []) {
                return;
            }

            // ONCE PER ARRIVAL at cgs_compile. An arrival is identified by
            // how many times the list has been sent back so far: the first
            // arrival is 0, the replay after one return is 1. A second
            // trigger for the same arrival (a double submit both requests got
            // through, a retry) hits the unique key and sends nothing --
            // the log row goes in first, as in examiners:sweep.
            $arrival = \App\Modules\Core\Models\ApprovalHistory::where('application_id', $application->id)
                ->where('decision', 'returned')
                ->count();

            try {
                ExaminerReminderLog::create([
                    'nomination_id' => $nomination->id,
                    'kind' => self::COMPILE_REVIEW,
                    'sequence' => $arrival,
                    'sent_at' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException) {
                return;
            }

            \App\Modules\Core\Models\User::where('role', Role::SENIOR_EXEC_CGS)->get()
                ->each(fn ($user) => $user->notify(new NominationsNeedCompileReview($application, $findings)));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Non-Executive CGS sends the appointment letters, so every one of them is
     * told the moment a list is released. Best effort, after commit.
     */
    protected function announceRelease(Application $application): void
    {
        try {
            \App\Modules\Core\Models\User::where('role', Role::NON_EXEC_CGS)->get()
                ->each(fn ($user) => $user->notify(new ExaminersReleased($application)));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function clashes(): CrossDepartmentClashes
    {
        return $this->clashes ??= app(CrossDepartmentClashes::class);
    }

    protected ?CrossDepartmentClashes $clashes = null;

    /**
     * "Finalise and release": the Senior Executive's one Approve at cgs_final
     * records BOTH remaining stages, cgs_final then cgs_release, each through
     * the engine, so approval_history keeps two rows and the audit trail reads
     * exactly as if she had clicked twice.
     *
     * One transaction, with the application row locked first. Two tabs (or a
     * double click) would otherwise both read cgs_final and both try to walk
     * the list forward; the second now waits, re-reads, finds it no longer at
     * cgs_final and stops. The engine's own transactions nest inside this one
     * as savepoints, and its after-commit notifications wait for this outer
     * commit -- so if the second decision fails, the first is undone too and
     * nobody is told anything.
     */
    protected function finaliseAndRelease(Application $application, \App\Modules\Core\Models\User $actor, WorkflowEngine $engine, ?string $remarks): void
    {
        DB::transaction(function () use ($application, $actor, $engine, $remarks) {
            $locked = Application::whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Application::STATUS_PENDING || $locked->current_stage !== self::FINALISE_STAGE) {
                throw new \LogicException("Application #{$application->id} is no longer awaiting finalisation.");
            }

            $engine->decide($locked, $actor, 'approve', $remarks);

            // The engine refreshed $locked; it now sits at cgs_release, which
            // the same role owns, so the second decision passes the same check.
            $engine->decide($locked, $actor, 'approve', $remarks ?? 'Released together with finalisation.');
        });

        $application->refresh();
    }

    /**
     * Send a list back to the department that chose it, from the Senior
     * Director's or the Dean's desk.
     *
     * Not a rejection: the candidate keeps one application, the audit trail
     * stays in one place, and the chain replays forward through the Academic
     * Executive and CGS -- which is the point, since both of them have to see
     * the replacement before it reaches the Dean again.
     */
    public function returnToDepartment(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $data = $request->validate([
            'remarks' => ['required', 'string', 'max:2000'],
        ], [
            'remarks.required' => 'Say what has to change — the department is being asked to choose again.',
        ]);

        try {
            $engine->returnTo($application, $request->user(), self::RETURN_TARGET, $data['remarks']);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application is not at a stage you can send back.');
        }

        return back()->with(
            'status',
            "Application #{$application->id} sent back to the Academic Executive. "
                .'Everyone who has to act on it again has been notified.'
        );
    }

    /**
     * The compiled list: every nomination in the pipeline, grouped the way it
     * is actually read -- faculty, then department.
     *
     * One screen for five roles. The Academic Executive sees their own
     * department (Role::isDepartmentScoped()); CGS, the Senior Director, the
     * Dean and the Non-Executive see everything, because the whole point of
     * their stages is that the departments have been merged. Nobody decides
     * anything here -- that stays on the queue, behind the stage checks.
     */
    public function report(Request $request)
    {
        abort_unless(in_array($request->user()->role, self::REPORT_ROLES, true), 403);

        $nominations = $this->reportRows($request->user());

        $byFaculty = $nominations
            ->groupBy(fn (ExaminerNomination $n) => $n->application?->student?->faculty ?: 'Unassigned faculty')
            ->map(fn ($rows) => $rows->groupBy(
                fn (ExaminerNomination $n) => $n->application?->student?->department ?: 'Unassigned department'
            ))
            ->sortKeys();

        // Touchpoint 2, inline: the same examiner named by more than one
        // department. Flagged, never auto-rejected -- the substitution is a
        // human call, and ConflictDetectionController is the screen for it.
        // Rejected lists hold nobody, so they cannot clash.
        $clashes = $this->clashes()->map($nominations->filter(
            fn (ExaminerNomination $n) => in_array($n->application?->status, [Application::STATUS_PENDING, Application::STATUS_APPROVED], true)
        ));

        return view('hani::examiner_nomination.report', [
            'coiFlags' => $this->coiFlags($nominations),
            'byFaculty' => $byFaculty,
            'total' => $nominations->count(),
            'clashes' => $clashes,
            'scopedToDepartment' => Role::isDepartmentScoped($request->user()->role)
                ? $request->user()->department
                : null,
        ]);
    }

    /**
     * The same rows as the report, as a file.
     *
     * ?format=csv or ?format=xlsx. Both come off one ExaminerListExport, so
     * the two downloads can never drift apart, and both are scoped by the
     * same reportRows() the screen uses -- an Academic Executive cannot
     * export a department they cannot read.
     */
    public function export(Request $request)
    {
        abort_unless(in_array($request->user()->role, self::REPORT_ROLES, true), 403);

        $format = $request->query('format') === 'csv' ? 'csv' : 'xlsx';
        $stamp = now()->format('Y-m-d');

        $scope = Role::isDepartmentScoped($request->user()->role)
            ? \Illuminate\Support\Str::slug($request->user()->department ?: 'department')
            : 'all-departments';

        $export = new ExaminerListExport($this->reportRows($request->user()));

        return $format === 'csv'
            ? Excel::download($export, "examiner-list-{$scope}-{$stamp}.csv", ExcelFormat::CSV)
            : Excel::download($export, "examiner-list-{$scope}-{$stamp}.xlsx");
    }

    /**
     * Everything in the pipeline this user is allowed to see, ordered the way
     * both the screen and the sheet want it.
     *
     * Draft rows are excluded — they have not been submitted — and rejected
     * ones are kept, because a list that was refused is exactly what the next
     * meeting asks about.
     *
     * @return \Illuminate\Support\Collection<int, ExaminerNomination>
     */
    protected function reportRows(\App\Modules\Core\Models\User $user): \Illuminate\Support\Collection
    {
        return ExaminerNomination::query()
            ->with(array_merge(
                ExaminerNomination::slotRelations(),
                ['application.student', 'application.submittedBy']
            ))
            ->whereHas('application', function ($q) use ($user) {
                $q->where('module_type', $this->moduleKey())
                    ->where('status', '!=', Application::STATUS_DRAFT);

                if (Role::isDepartmentScoped($user->role)) {
                    $q->whereHas('student', fn ($s) => $s->where('department', $user->department));
                }
            })
            ->get()
            ->sortBy(fn (ExaminerNomination $n) => [
                $n->application?->student?->department,
                $n->application?->student?->name,
            ])
            ->values();
    }

    /**
     * The conflict-of-interest pre-check, and other departments' claims on
     * the same examiner, for every seat shown, by application then examiner.
     * Staff screens only.
     *
     * @return array<int, array<int, array>>
     */
    protected function coiFlags(\Illuminate\Support\Collection $nominations): array
    {
        $coi = app(ConflictOfInterest::class);
        $flags = [];

        foreach ($nominations as $nomination) {
            $student = $nomination->application?->student;

            if (! $student) {
                continue;
            }

            foreach ($nomination->examiners() as $examiner) {
                $flags[$nomination->application_id][$examiner->id] = array_merge(
                    $coi->flagsFor($examiner, $student),
                    $this->clashes()->flags($examiner->id, $student->department, $nomination->application_id),
                );
            }
        }

        return $flags;
    }

    /**
     * Released panels whose evaluation has not been marked complete, for the
     * Academic Executive to close out -- their own department's only.
     */
    public function pendingEvaluation(Request $request)
    {
        $department = $request->user()->department;

        $nominations = ExaminerNomination::with(array_merge(
            ExaminerNomination::slotRelations(),
            ['application.student']
        ))
            ->whereHas('application', fn ($q) => $q->where('module_type', $this->moduleKey())
                ->where('status', Application::STATUS_APPROVED)
                ->whereHas('student', fn ($s) => $s->where('department', $department)))
            ->get()
            // Any seat still tied up, or a main seat left empty by a decline,
            // means this panel has not been closed out.
            ->filter(fn (ExaminerNomination $n) => $n->examiners()->contains(fn (Examiner $e) => $e->assigned_until !== null)
                || ! $n->internal_main_id || ! $n->external_main_id)
            ->values();

        return view('hani::examiner_nomination.pending_evaluation', ['nominations' => $nominations]);
    }

    /**
     * Marks the evaluation as actually finished: the examiners in the main
     * seats start the real 90-day gap from today; a backup this panel had
     * tied up is freed without one (Support\PanelSeats::complete()).
     * This is the other half of the lifecycle -- the gap Examiner::state()
     * enforces never begins until someone confirms the evaluation happened.
     */
    public function markComplete(Request $request, ExaminerNomination $nomination, PanelSeats $seats)
    {
        abort_unless($nomination->application->module_type === $this->moduleKey(), 404);
        abort_unless($nomination->application->status === Application::STATUS_APPROVED, 404);
        // Their own department's panels only.
        abort_unless($nomination->application->student?->department === $request->user()->department, 403);

        $seats->complete($nomination, $request->user());

        return redirect()
            ->route('examiner-nomination.pending-evaluation')
            ->with('status', "Evaluation marked complete for nomination #{$nomination->application_id}.");
    }
}
