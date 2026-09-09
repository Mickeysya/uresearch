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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
}
