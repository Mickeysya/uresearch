<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\SupervisionDetail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

class SupervisionController extends Controller
{
    use ApprovesApplications;

    protected function moduleKey(): string
    {
        return 'supervision';
    }

    public function create()
    {
        $supervisors = User::where('role', Role::SUPERVISOR)->orderBy('name')->get();

        return view('nureen::supervision.form', ['supervisors' => $supervisors]);
    }

    public function store(Request $request, WorkflowEngine $engine)
    {
        $data = $request->validate([
            'requested_supervisor_id' => ['required', 'exists:users,id'],
            'justification' => ['required', 'string', 'min:20', 'max:2000'],
        ], [
            'justification.min' => 'Please give a sentence or two on why you are requesting this supervisor.',
        ]);

        // The dropdown only ever lists supervisors, but the id is still
        // user-supplied -- re-check role server-side rather than trusting it.
        $supervisor = User::where('id', $data['requested_supervisor_id'])
            ->where('role', Role::SUPERVISOR)
            ->firstOrFail();

        $application = DB::transaction(function () use ($request, $supervisor, $data, $engine) {
            $application = Application::create([
                'student_id' => $request->user()->id,
                'submitted_by_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            SupervisionDetail::create([
                'application_id' => $application->id,
                'requested_supervisor_id' => $supervisor->id,
                'justification' => $data['justification'],
            ]);

            return $engine->submit($application);
        });

        return redirect()
            ->route('applications.index')
            ->with('status', "Supervision request #{$application->id} submitted.");
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine);

        $details = SupervisionDetail::with('requestedSupervisor')
            ->whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        // The engine's queue is role-scoped, not person-scoped: every
        // supervisor-role user is handed every pending request at the
        // "supervisor" stage, because the student has no supervisor_id yet
        // for the engine to filter on -- this request is what sets it. Cut
        // the list down to the ones actually addressed to this supervisor so
        // nobody sees, or is tempted to act on, someone else's request.
        if ($queue['stage']->key === 'supervisor') {
            $queue['applications'] = $queue['applications']->filter(
                fn ($application) => ($details[$application->id]->requested_supervisor_id ?? null) === $request->user()->id
            )->values();
        }

        return view('nureen::supervision.queue', $queue + ['details' => $details]);
    }

    /**
     * Overrides the trait's decide(): a supervisor stage decision must come
     * from the specific supervisor the student named, not merely from
     * someone holding the supervisor role -- see the note in queue() above.
     * On CGS's approval, the request is what makes the appointment real, so
     * users.supervisor_id is set here as a side effect of the decision.
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $stage = $engine->currentStage($application);
        $detail = SupervisionDetail::where('application_id', $application->id)->first();

        if ($stage?->key === 'supervisor' && $detail?->requested_supervisor_id !== $request->user()->id) {
            abort(403, 'This request was not addressed to you.');
        }

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $decided = $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application has already been decided.');
        }

        if ($decided->status === Application::STATUS_APPROVED && $detail) {
            $decided->student->update(['supervisor_id' => $detail->requested_supervisor_id]);
        }

        $verb = $data['decision'] === 'approve' ? 'approved' : 'rejected';

        return back()->with('status', "Application #{$application->id} {$verb}.");
    }
}
