<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\ExaminerSubstitutionRequest;
use App\Modules\Hani\Notifications\SubstitutionRequested;
use App\Modules\Hani\Support\ConflictOfInterest;
use App\Modules\Hani\Support\CrossDepartmentClashes;
use App\Modules\Hani\Support\Departments;
use App\Modules\Hani\Support\Matching\ExaminerSuggestions;
use App\Modules\Hani\Support\PanelSeats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\UnauthorizedException;

/**
 * "Ask department to substitute", from a Conflict Detection row, and the
 * department's answer.
 *
 *   store()    whoever holds the list asks. At cgs_compile the list stays where
 *              it is. At the Senior Director's or Dean's stage it also goes
 *              back through WorkflowEngine::returnTo(), as any send-back does.
 *   show()     the candidate's AE or supervisor sees the reason and suggestions
 *   replace()  they pick a replacement (Support\PanelSeats::substitute())
 *
 * returnTo() sends its remarks to the CANDIDATE, who must never learn who their
 * examiners are. So the remarks stay generic, and the examiner and the reason
 * are only on the request, which only staff can read.
 */
class ExaminerSubstitutionController extends Controller
{
    public const RETURN_REMARKS = 'A substitution is needed on this examiner list. The Academic Executive and the supervisor have the details.';

    public function store(Request $request, ExaminerNomination $nomination, WorkflowEngine $engine, PanelSeats $seats, CrossDepartmentClashes $clashes): RedirectResponse
    {
        $application = $nomination->application;
        abort_unless($application?->module_type === 'examiner_nomination', 404);

        $data = $request->validate([
            'seat' => ['required', Rule::in(array_keys(ExaminerNomination::SLOTS))],
            'reason' => ['required', 'string', 'max:2000'],
            'form_key' => ['nullable', 'string', 'max:60'],
        ], [
            'reason.required' => 'Say why the department should replace this examiner.',
        ]);

        $user = $request->user();

        if (! ExaminerSubstitutionRequest::canAsk($user, $application)) {
            return back()->with('error', "Application #{$application->id} is not at a stage you can ask from. It may have moved on since this page loaded.");
        }

        $holder = $nomination->{$data['seat']};

        if (! $holder || ! $clashes->map()->has($holder)) {
            return back()->with('error', 'That examiner is no longer named by another department, so there is nothing to substitute.');
        }

        try {
            $substitution = DB::transaction(function () use ($application, $nomination, $data, $user, $holder, $engine, $seats) {
                $stage = $application->current_stage;
                $locked = Application::whereKey($application->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== Application::STATUS_PENDING || $locked->current_stage !== $stage) {
                    throw new \LogicException("Application #{$application->id} has moved on since this page loaded.");
                }

                $exists = ExaminerSubstitutionRequest::open()
                    ->where('nomination_id', $nomination->id)->where('seat', $data['seat'])->exists();

                if ($exists) {
                    throw new \LogicException('The department has already been asked to replace this seat.');
                }

                $substitution = ExaminerSubstitutionRequest::create([
                    'nomination_id' => $nomination->id,
                    'seat' => $data['seat'],
                    'examiner_id' => $holder,
                    'requested_at_stage' => $stage,
                    'reason' => $data['reason'],
                    'requested_by_id' => $user->id,
                ]);

                $seats->log($nomination, $data['seat'], $holder, 'sub_requested', $data['reason'], $user);

                if (in_array($stage, ExaminerNominationController::RETURNING_STAGES, true)) {
                    $engine->returnTo($locked, $user, ExaminerNominationController::RETURN_TARGET, self::RETURN_REMARKS);
                }

                return $substitution;
            });
        } catch (UnauthorizedException|\LogicException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->tellDepartment($substitution);

        return back()->with('status', "The department has been asked to replace this seat on application #{$application->id}."
            .($application->refresh()->current_stage === ExaminerNominationController::RETURN_TARGET ? ' The list has gone back to the Academic Executive.' : ''));
    }

    public function show(Request $request, ExaminerSubstitutionRequest $substitution, ExaminerSuggestions $suggestions, ConflictOfInterest $coi, CrossDepartmentClashes $clashes, Departments $departments)
    {
        $this->authorise($request->user(), $substitution);

        $substitution->load('examiner', 'replacement', 'requestedBy', 'resolvedBy');
        $nomination = $substitution->nomination->load(ExaminerNomination::slotRelations());
        $student = $nomination->application->student;

        $suggested = [];
        $eligible = collect();

        if ($substitution->isReplaceable()) {
            $type = str_starts_with($substitution->seat, 'internal') ? Examiner::TYPE_INTERNAL : Examiner::TYPE_EXTERNAL;
            $onPanel = $nomination->examiners()->pluck('id')->all();

            $suggestions->withFlags(fn ($e, $s) => array_merge(
                $coi->flagsFor($e, $s),
                $clashes->flags($e->id, $s->department, $nomination->application_id),
            ));

            $suggested = $suggestions->forStudent($student, $nomination->thesis_title, $onPanel)[$type];
            $eligible = Examiner::eligible()->where('type', $type)->whereNotIn('id', $onPanel)->orderBy('name')->get()
                ->filter(fn ($e) => $type === Examiner::TYPE_EXTERNAL || $departments->same($e->department, $student->department))
                ->values();
        }

        return view('hani::conflict_detection.substitute', [
            'substitution' => $substitution,
            'nomination' => $nomination,
            'student' => $student,
            'suggested' => $suggested,
            'eligible' => $eligible,
        ]);
    }

    public function replace(Request $request, ExaminerSubstitutionRequest $substitution, PanelSeats $seats, CrossDepartmentClashes $clashes): RedirectResponse
    {
        $this->authorise($request->user(), $substitution);

        $data = $request->validate([
            'examiner_id' => ['required', 'integer', 'exists:examiners,id'],
        ]);

        $examiner = Examiner::findOrFail($data['examiner_id']);
        $seats->substitute($substitution, $examiner, $request->user());

        $application = $substitution->nomination->application;
        $alsoNamed = collect($clashes->flags($examiner->id, $application->student?->department, $application->id))->pluck('message');

        return redirect()->route('examiner-substitution.show', $substitution)
            ->with('status', "{$examiner->name} now holds the {$substitution->seatLabel()} seat.")
            ->with('warning', $alsoNamed->isEmpty() ? null : 'Please note: '.$alsoNamed->implode(' '));
    }

    /** The candidate's own Academic Executive or supervisor. */
    protected function authorise(User $user, ExaminerSubstitutionRequest $substitution): void
    {
        $student = $substitution->nomination?->application?->student;

        $allowed = $student && match ($user->role) {
            Role::ACADEMIC_EXEC => filled($user->department) && $user->department === $student->department,
            Role::SUPERVISOR => $student->supervisor_id === $user->id,
            default => false,
        };

        abort_unless($allowed, 403);
    }

    /** Best effort, after commit: the request stands even if the mail does not go. */
    protected function tellDepartment(ExaminerSubstitutionRequest $substitution): void
    {
        try {
            $student = $substitution->nomination->application->student;

            User::where(fn ($q) => $q
                ->where(fn ($ae) => $ae->where('role', Role::ACADEMIC_EXEC)->where('department', $student->department))
                ->orWhere('id', $student->supervisor_id))
                ->get()
                ->each(fn (User $user) => $user->notify(new SubstitutionRequested($substitution)));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
