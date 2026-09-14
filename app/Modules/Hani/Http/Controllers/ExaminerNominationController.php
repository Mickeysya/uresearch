<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\UnauthorizedException;
use Illuminate\Validation\ValidationException;

class ExaminerNominationController extends Controller
{
    use ApprovesApplications;

    protected function moduleKey(): string
    {
        return 'examiner_nomination';
    }

    public function create(Request $request)
    {
        return view('hani::examiner_nomination.form', [
            // Only the supervisor's own candidates may be nominated for.
            'candidates' => $request->user()->supervisees()->where('role', Role::STUDENT)->get(),
            'examiners' => Examiner::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, WorkflowEngine $engine)
    {
        $data = $request->validate([
            'student_id' => [
                'required',
                Rule::exists('users', 'id')->where('supervisor_id', $request->user()->id),
            ],
            'thesis_title' => ['required', 'string', 'max:255'],
            'main_examiner_id' => ['required', 'exists:examiners,id'],
            'backup_examiner_id' => ['nullable', 'different:main_examiner_id', 'exists:examiners,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'student_id.exists' => 'You may only nominate examiners for your own candidates.',
            'backup_examiner_id.different' => 'The backup examiner must differ from the main examiner.',
        ]);

        // Touchpoint 1: both nominees must be eligible at the exact moment of
        // nomination -- not assigned, not on gap, not marked unavailable.
        foreach (['main_examiner_id' => 'Main', 'backup_examiner_id' => 'Backup'] as $field => $which) {
            if (empty($data[$field])) {
                continue;
            }

            $examiner = Examiner::findOrFail($data[$field]);

            if (! $examiner->isEligible()) {
                throw ValidationException::withMessages([
                    $field => "{$which} examiner {$examiner->name} is not eligible. "
                        .$examiner->ineligibilityReason(),
                ]);
            }
        }

        $application = DB::transaction(function () use ($request, $data, $engine) {
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
                'main_examiner_id' => $data['main_examiner_id'],
                'backup_examiner_id' => $data['backup_examiner_id'] ?? null,
                'thesis_title' => $data['thesis_title'],
                'notes' => $data['notes'] ?? null,
            ]);

            return $engine->submit($application);
        });

        return redirect()
            ->route('examiner-nomination.create')
            ->with('status', "Nomination #{$application->id} submitted to the Academic Executive.");
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['submittedBy']);

        $nominations = ExaminerNomination::with('mainExaminer', 'backupExaminer')
            ->whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        return view('hani::examiner_nomination.queue', $queue + ['nominations' => $nominations]);
    }

    /**
     * Overrides ApprovesApplications::decide() to close the loop the trait
     * cannot know about: on approval, both nominated examiners become tied
     * up. This is the only place that writes Examiner::assigned_until --
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

        if ($data['decision'] === 'approve') {
            $this->tieUpExaminers($application);
        }

        $verb = $data['decision'] === 'approve' ? 'approved' : 'rejected';

        return back()->with('status', "Application #{$application->id} {$verb}.");
    }

    /** Nominations approved but not yet marked as evaluated, for the Academic Executive to close out. */
    public function pendingEvaluation()
    {
        $nominations = ExaminerNomination::with('mainExaminer', 'backupExaminer', 'application.student')
            ->whereHas('application', fn ($q) => $q->where('module_type', $this->moduleKey())
                ->where('status', Application::STATUS_APPROVED))
            ->where(function ($q) {
                $q->whereHas('mainExaminer', fn ($e) => $e->whereNotNull('assigned_until'))
                    ->orWhereHas('backupExaminer', fn ($e) => $e->whereNotNull('assigned_until'));
            })
            ->get();

        return view('hani::examiner_nomination.pending_evaluation', ['nominations' => $nominations]);
    }

    /**
     * Marks the evaluation as actually finished: clears the tie-up and
     * starts the real 90-day gap from today, on both examiners. This is the
     * other half of the lifecycle -- the gap Examiner::state() enforces
     * never begins until someone confirms the evaluation happened.
     */
    public function markComplete(ExaminerNomination $nomination)
    {
        abort_unless($nomination->application->module_type === $this->moduleKey(), 404);
        abort_unless($nomination->application->status === Application::STATUS_APPROVED, 404);

        DB::transaction(function () use ($nomination) {
            foreach ([$nomination->mainExaminer, $nomination->backupExaminer] as $examiner) {
                $examiner?->update([
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
        $nomination = ExaminerNomination::where('application_id', $application->id)->first();

        if (! $nomination) {
            return;
        }

        $assignedUntil = now()->addDays(Examiner::DEFAULT_ASSIGNMENT_DAYS);

        foreach ([$nomination->mainExaminer, $nomination->backupExaminer] as $examiner) {
            $examiner?->update(['assigned_until' => $assignedUntil]);
        }
    }
}
