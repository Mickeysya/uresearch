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
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Support\PanelSeats;
use App\Modules\Hani\Support\ReVivaWindow;
use App\Modules\Hani\Support\VivaProgress;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReVivaController extends Controller
{
    use ApprovesApplications;

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

        return view('hani::re_viva.form', [
            'students' => $students,
            'blockedReasons' => $blockedReasons,
            'preselected' => $preselected,
            'preselectedStudent' => $preselectedStudent,
            'window' => $window,
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

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        $data = $request->validate([
            'student_id' => ['required', Rule::exists('users', 'id')->where('role', Role::STUDENT)],
            'thesis' => DocumentStore::rules(required: true),
            'viva_result_date' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $student = User::findOrFail($data['student_id']);
        $latest = $this->latestCycle($student->id);

        if ($reason = $this->blockReason($latest)) {
            throw ValidationException::withMessages(['student_id' => $reason]);
        }

        // Round 1 takes the category-4 viva's window; round N+1 runs from the
        // level-4 outcome. A student with no viva result in UResearch (logged
        // on paper before the portal) needs the result date from CGS.
        $window = ReVivaWindow::for($student, $latest);

        if (! $window) {
            if (empty($data['viva_result_date'])) {
                throw ValidationException::withMessages([
                    'viva_result_date' => 'No category 4 viva result is recorded in UResearch for this student. Enter the date of the viva result so the resubmission window can be worked out.',
                ]);
            }
            $window = ReVivaWindow::fromResultDate(Carbon::parse($data['viva_result_date']));
        }

        $resubmittedAt = now();
        $warning = $window->warningFor($resubmittedAt);
        $round = $latest ? $latest->cycle_number + 1 : 1;

        $application = DB::transaction(function () use ($request, $data, $student, $engine, $documents, $latest, $window, $resubmittedAt, $warning, $round) {
            $application = Application::create([
                'student_id' => $student->id,
                // The CGS staff member logging it, not the student.
                'submitted_by_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            // correction_deadline is set by the outcome (levels 1-3) and
            // hardbound_deadline is no longer used; see the 2026_11_06
            // migration for how the original columns map.
            ReVivaDetail::create([
                'application_id' => $application->id,
                'cycle_number' => $round,
                'previous_cycle_id' => $latest?->id,
                'viva_application_id' => $window->viva?->application_id ?? $latest?->viva_application_id,
                'resubmission_at' => $resubmittedAt,
                'resubmission_opens_at' => $window->opens,
                'resubmission_closes_at' => $window->closes,
                'viva_result_date' => $window->source === ReVivaWindow::LEGACY ? $data['viva_result_date'] : null,
                'logged_outside_window' => $warning !== null,
            ]);

            $documents->attach($application, $request->file('thesis'), 'Re-corrected Thesis');

            $this->holdReexaminers($window->viva, $resubmittedAt->copy()->addMonthsNoOverflow((int) config('hani.reviva.hold_months', 12)), $request->user());

            return $engine->submit($application);
        });

        $redirect = redirect()
            ->route('reviva.create')
            ->with('status', "Re-viva round {$round} logged for {$student->name}.");

        return $warning ? $redirect->with('warning', $warning.' It was logged anyway; check that CGS agreed the late or early submission.') : $redirect;
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents']);

        $details = ReVivaDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        return view('hani::re_viva.queue', $queue + ['details' => $details]);
    }

    /** Applications that finished the stepper but have no outcome recorded yet. */
    public function outcomesIndex()
    {
        $details = ReVivaDetail::with(['application.student', 'application.documents'])
            ->whereNull('outcome_level')
            ->whereHas('application', fn ($q) => $q->where('module_type', $this->moduleKey())
                ->where('status', Application::STATUS_APPROVED))
            ->get();

        return view('hani::re_viva.outcomes', ['details' => $details]);
    }

    public function recordOutcome(Request $request, ReVivaDetail $detail)
    {
        abort_if($detail->hasOutcome(), 404);
        abort_unless($detail->application->status === Application::STATUS_APPROVED, 404);

        $data = $request->validate([
            'outcome_level' => ['required', 'integer', 'between:1,5'],
            'outcome_remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $detail->update($data);

        return redirect()
            ->route('reviva.outcomes.index')
            ->with('status', "Outcome recorded for re-viva #{$detail->application_id}.");
    }

    /**
     * The same main examiners re-examine, so keep them Assigned for the round
     * (until $until at most); the outcome releases them (PanelSeats). Nothing
     * to do with no linked viva, or once the window-closed sweep freed them.
     */
    protected function holdReexaminers(?VivaDetail $viva, \DateTimeInterface $until, User $actor): void
    {
        if (! $viva || $viva->examiners_freed_at !== null) {
            return;
        }

        $panel = VivaProgress::forNotices(collect([$viva->application]))->first()?->panel();

        if ($panel) {
            app(PanelSeats::class)->holdForReexamination($panel, $until, $actor);
        }
    }

    /**
     * The student's most recent cycle across all their re-viva applications,
     * whatever its outcome -- used both to gate a new submission and, for a
     * loop-back, to link the new cycle to the one that produced it.
     */
    protected function latestCycle(int $studentId): ?ReVivaDetail
    {
        return ReVivaDetail::whereHas('application', fn ($q) => $q
            ->where('module_type', $this->moduleKey())
            ->where('student_id', $studentId))
            ->latest('cycle_number')
            ->first();
    }

    /**
     * Why $latest blocks a new submission, or null if one may be filed.
     * A cycle still mid-stepper always blocks; a resolved cycle blocks
     * unless its outcome was level 4 (loop back into another cycle).
     */
    protected function blockReason(?ReVivaDetail $latest): ?string
    {
        if (! $latest) {
            return null;
        }

        if (! $latest->hasOutcome()) {
            return 'A re-viva cycle is already in progress; wait for it to reach an outcome first.';
        }

        if (! $latest->requiresLoopBack()) {
            return 'Your re-viva has already reached a final outcome; a new cycle is not open.';
        }

        return null;
    }
}
