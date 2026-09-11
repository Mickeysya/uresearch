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

    public function create()
    {
        $students = User::where('role', Role::STUDENT)->orderBy('name')->get();

        // Same pattern as the examiner dropdown on the nomination form: show
        // every student, but flag which ones CGS cannot log a new cycle for
        // right now, and why.
        $blockedReasons = $students->mapWithKeys(
            fn (User $student) => [$student->id => $this->blockReason($this->latestCycle($student->id))]
        );

        return view('hani::re_viva.form', compact('students', 'blockedReasons'));
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        $data = $request->validate([
            'student_id' => ['required', Rule::exists('users', 'id')->where('role', Role::STUDENT)],
            'thesis' => DocumentStore::rules(required: true),
        ]);

        $latest = $this->latestCycle($data['student_id']);

        if ($reason = $this->blockReason($latest)) {
            throw ValidationException::withMessages(['student_id' => $reason]);
        }

        $application = DB::transaction(function () use ($request, $data, $engine, $documents, $latest) {
            $application = Application::create([
                'student_id' => $data['student_id'],
                // The CGS staff member logging it, not the student.
                'submitted_by_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            $resubmittedAt = now();

            ReVivaDetail::create([
                'application_id' => $application->id,
                'cycle_number' => $latest ? $latest->cycle_number + 1 : 1,
                'previous_cycle_id' => $latest?->id,
                'resubmission_at' => $resubmittedAt,
                'correction_deadline' => $resubmittedAt->copy()->addMonths(6),
                'hardbound_deadline' => $resubmittedAt->copy()->addMonths(12),
            ]);

            $documents->attach($application, $request->file('thesis'), 'Re-corrected Thesis');

            return $engine->submit($application);
        });

        return redirect()
            ->route('reviva.create')
            ->with('status', "Re-viva monitoring #{$application->id} logged for {$application->student->name}.");
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
