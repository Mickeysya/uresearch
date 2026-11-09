<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\ExaminerSeatHistory;
use App\Modules\Hani\Support\ConflictOfInterest;
use App\Modules\Hani\Support\Departments;
use App\Modules\Hani\Support\Matching\ExaminerSuggestions;
use App\Modules\Hani\Support\PanelSeats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A released panel, after release: record a decline, see the backup promoted
 * (or the seat left empty), appoint a replacement from suggestions.
 *
 * Appointment letters go out from Jason's module and acceptance slips come
 * back on paper or by email, recorded nowhere -- so this is where a decline is
 * entered. The candidate's own Academic Executive or the Senior Executive
 * CGS, and only on a released (approved) nomination. Off-chain: nothing here
 * touches applications.status or current_stage.
 */
class PanelController extends Controller
{
    public function show(Request $request, ExaminerNomination $nomination, ExaminerSuggestions $suggestions, ConflictOfInterest $coi, Departments $departments)
    {
        $this->authorise($request->user(), $nomination);
        $nomination->load(array_merge(ExaminerNomination::slotRelations(), ['application.student']));
        $student = $nomination->application->student;

        $vacant = array_keys(array_filter(PanelSeats::MAINS, fn ($backup, $main) => ! $nomination->{$main}, ARRAY_FILTER_USE_BOTH));
        $onPanel = array_values(array_filter(array_map(fn ($s) => $nomination->{$s}, array_keys(ExaminerNomination::SLOTS))));

        $replacements = [];

        if ($vacant) {
            $suggestions->withFlags(fn ($e, $s) => $coi->flagsFor($e, $s));
            $suggested = $suggestions->forStudent($student, $nomination->thesis_title, $onPanel);

            foreach ($vacant as $seat) {
                $type = str_starts_with($seat, 'internal') ? Examiner::TYPE_INTERNAL : Examiner::TYPE_EXTERNAL;
                $replacements[$seat] = [
                    'suggested' => $suggested[$type],
                    'eligible' => Examiner::eligible()->where('type', $type)->whereNotIn('id', $onPanel)->orderBy('name')->get()
                        ->filter(fn ($e) => $type === Examiner::TYPE_EXTERNAL || $departments->same($e->department, $student->department))
                        ->values(),
                ];
            }
        }

        return view('hani::examiner_nomination.panel', [
            'lettersSent' => $this->lettersSent($nomination),
            'nomination' => $nomination,
            'student' => $student,
            'replacements' => $replacements,
            'history' => ExaminerSeatHistory::with('examiner', 'actor')->where('nomination_id', $nomination->id)->orderBy('id')->get(),
        ]);
    }

    public function decline(Request $request, ExaminerNomination $nomination, PanelSeats $seats): RedirectResponse
    {
        $this->authorise($request->user(), $nomination);

        $data = $request->validate([
            'seat' => ['required', Rule::in(array_keys(PanelSeats::MAINS))],
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Say why the examiner declined. It is kept on the panel\'s history.',
        ]);

        $result = $seats->decline($nomination, $data['seat'], $data['reason'], $request->user());

        return redirect()->route('examiner-panel.show', $nomination)->with('status', $result['promoted']
            ? "{$result['declined']->name} declined and has been freed. Backup {$result['promoted']->name} has been promoted, and Non-Executive CGS has been asked to send the appointment letter."
            : "{$result['declined']->name} declined and has been freed. There is no eligible backup, so choose a replacement below.");
    }

    public function replace(Request $request, ExaminerNomination $nomination, PanelSeats $seats): RedirectResponse
    {
        $this->authorise($request->user(), $nomination);

        $data = $request->validate([
            'seat' => ['required', Rule::in(array_keys(ExaminerNomination::SLOTS))],
            'examiner_id' => ['required', 'integer', 'exists:examiners,id'],
        ]);

        $examiner = Examiner::findOrFail($data['examiner_id']);
        $seats->replace($nomination, $data['seat'], $examiner, $request->user());

        return redirect()->route('examiner-panel.show', $nomination)
            ->with('status', "{$examiner->name} appointed to the ".ExaminerNomination::SLOTS[$data['seat']].' seat. Non-Executive CGS has been asked to send the appointment letter.');
    }

    /**
     * When Jason's module sent each main examiner their appointment pack,
     * through the Core contract in SHARED_CHANGES.md
     * (SuppliesAppointmentDispatch). Read only if something is bound to it;
     * null means "not known here", not "not sent".
     *
     * @return array<string, ?\DateTimeInterface>|null seat => sent at, or null when the contract is not available
     */
    protected function lettersSent(ExaminerNomination $nomination): ?array
    {
        $contract = 'App\\Modules\\Core\\Contracts\\SuppliesAppointmentDispatch';

        if (! interface_exists($contract) || ! app()->bound($contract)) {
            return null;
        }

        $dispatch = app($contract);
        $sent = [];

        foreach (array_keys(PanelSeats::MAINS) as $seat) {
            $sent[$seat] = $nomination->{$seat} ? $dispatch->packSentAt($nomination->application_id, $nomination->{$seat}) : null;
        }

        return $sent;
    }

    /** The candidate's own Academic Executive, or the Senior Executive; released panels only. */
    protected function authorise(User $user, ExaminerNomination $nomination): void
    {
        $application = $nomination->application;

        abort_unless($application?->module_type === 'examiner_nomination', 404);
        abort_unless($application->status === Application::STATUS_APPROVED, 404);

        $allowed = $user->role === Role::SENIOR_EXEC_CGS
            || ($user->role === Role::ACADEMIC_EXEC && filled($user->department) && $user->department === $application->student?->department);

        abort_unless($allowed, 403);
    }
}
