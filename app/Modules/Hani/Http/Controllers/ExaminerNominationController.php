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

    public function store(Request $request, WorkflowEngine $engine)
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
            if ($expected === Examiner::TYPE_INTERNAL && $examiner->department !== $student->department) {
                throw ValidationException::withMessages([
                    $slot => "{$which} must come from {$student->name}'s own department ("
                        .($student->department ?: 'none recorded').'). '
                        ."{$examiner->name} is in {$examiner->department}.",
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

            ExaminerNomination::create([
                'application_id' => $application->id,
                'thesis_title' => $data['thesis_title'],
                'notes' => $data['notes'] ?? null,
            ] + array_combine($slots, array_map(fn ($slot) => $data[$slot] ?? null, $slots)));

            return $engine->submit($application);
        });

        return redirect()
            ->route('examiner-nomination.create')
            ->with('status', "Nomination #{$application->id} submitted to the Academic Executive.");
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['submittedBy']);

        $nominations = ExaminerNomination::with(ExaminerNomination::slotRelations())
            ->whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        return view('hani::examiner_nomination.queue', $queue + [
            'nominations' => $nominations,
            // Only the two stages above the compilation may send a list back
            // to the department; everyone else gets the plain two buttons.
            'canReturn' => in_array($queue['stage']->key, self::RETURNING_STAGES, true),
        ]);
    }

    /**
     * Overrides ApprovesApplications::decide() to close the loop the trait
     * cannot know about: on approval, every examiner on the panel becomes
     * tied up. This is the only place that writes Examiner::assigned_until --
     * nothing else in the module ever will, so an examiner cannot silently
     * drift back to "Available" while still on an active case.
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application has already been decided.');
        }

        // Tie the examiners up only when the chain has actually FINISHED, not
        // merely when this approver said yes -- six stages now sit between
        // the supervisor and the final release, and tying a panel up at the
        // first approval would hold four examiners for 180 days while the
        // list was still being argued over.
        if ($application->refresh()->status === Application::STATUS_APPROVED) {
            $this->tieUpExaminers($application);
        }

        $verb = $data['decision'] === 'approve' ? 'approved' : 'rejected';

        return back()->with('status', "Application #{$application->id} {$verb}.");
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
        $clashes = $nominations
            ->flatMap(fn (ExaminerNomination $n) => $n->examiners()->map(fn ($e) => [
                'examiner_id' => $e->id,
                'department' => $n->application?->student?->department,
            ]))
            ->groupBy('examiner_id')
            ->map(fn ($rows) => $rows->pluck('department')->filter()->unique()->values())
            ->filter(fn ($departments) => $departments->count() > 1);

        return view('hani::examiner_nomination.report', [
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

    /** Nominations approved but not yet marked as evaluated, for the Academic Executive to close out. */
    public function pendingEvaluation()
    {
        $nominations = ExaminerNomination::with(array_merge(
            ExaminerNomination::slotRelations(),
            ['application.student']
        ))
            ->whereHas('application', fn ($q) => $q->where('module_type', $this->moduleKey())
                ->where('status', Application::STATUS_APPROVED))
            ->get()
            // Any seat still tied up means this panel has not been closed out.
            ->filter(fn (ExaminerNomination $n) => $n->examiners()->contains(
                fn (Examiner $e) => $e->assigned_until !== null
            ))
            ->values();

        return view('hani::examiner_nomination.pending_evaluation', ['nominations' => $nominations]);
    }

    /**
     * Marks the evaluation as actually finished: clears the tie-up and
     * starts the real 90-day gap from today, on every examiner on the panel.
     * This is the other half of the lifecycle -- the gap Examiner::state()
     * enforces never begins until someone confirms the evaluation happened.
     */
    public function markComplete(ExaminerNomination $nomination)
    {
        abort_unless($nomination->application->module_type === $this->moduleKey(), 404);
        abort_unless($nomination->application->status === Application::STATUS_APPROVED, 404);

        DB::transaction(function () use ($nomination) {
            foreach ($nomination->examiners() as $examiner) {
                $examiner->update([
                    'assigned_until' => null,
                    'last_examination_date' => now(),
                ]);
            }
        });

        return redirect()
            ->route('examiner-nomination.pending-evaluation')
            ->with('status', "Evaluation marked complete for nomination #{$nomination->application_id}.");
    }

    protected function tieUpExaminers(Application $application): void
    {
        $nomination = ExaminerNomination::with(ExaminerNomination::slotRelations())
            ->where('application_id', $application->id)
            ->first();

        if (! $nomination) {
            return;
        }

        $assignedUntil = now()->addDays(Examiner::DEFAULT_ASSIGNMENT_DAYS);

        foreach ($nomination->examiners() as $examiner) {
            $examiner->update(['assigned_until' => $assignedUntil]);
        }
    }
}
