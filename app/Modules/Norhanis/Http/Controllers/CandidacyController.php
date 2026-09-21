<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\Candidacy;
use App\Modules\Norhanis\Models\RpdAppealDetail;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The RPD masterlist — the screen norhanis.md keeps referring to as the thing
 * appeals "update".
 *
 * CGS sees every candidacy with its deadline, tone and remaining extension, and
 * opens dismissals from here. A student sees only their own clock: the same
 * data, one row, plus whether they can still appeal.
 */
class CandidacyController extends Controller
{
    /** CGS: the whole masterlist, filterable. */
    public function index(Request $request)
    {
        $filter = $request->query('filter', 'active');

        $query = Candidacy::with('student')->orderBy('rpd_deadline');

        match ($filter) {
            'overdue' => $query->whereIn('status', [Candidacy::STATUS_ACTIVE, Candidacy::STATUS_EXTENDED])
                ->whereDate('rpd_deadline', '<', now()->startOfDay()),
            'due_soon' => $query->whereIn('status', [Candidacy::STATUS_ACTIVE, Candidacy::STATUS_EXTENDED])
                ->whereDate('rpd_deadline', '>=', now()->startOfDay())
                ->whereDate('rpd_deadline', '<=', now()->startOfDay()->addMonthsNoOverflow(3)),
            'closed' => $query->whereIn('status', [Candidacy::STATUS_DEFENDED, Candidacy::STATUS_DISMISSED]),
            default => $query->whereIn('status', [Candidacy::STATUS_ACTIVE, Candidacy::STATUS_EXTENDED]),
        };

        $all = Candidacy::selectRaw('status, rpd_deadline')->get();

        return view('norhanis::candidacy.index', [
            'candidacies' => $query->paginate(20)->withQueryString(),
            'filter' => $filter,
            'counts' => [
                'active' => $all->whereIn('status', [Candidacy::STATUS_ACTIVE, Candidacy::STATUS_EXTENDED])->count(),
                'due_soon' => $all->whereIn('status', [Candidacy::STATUS_ACTIVE, Candidacy::STATUS_EXTENDED])
                    ->filter(fn ($c) => $c->rpd_deadline >= now()->startOfDay()
                        && $c->rpd_deadline <= now()->startOfDay()->addMonthsNoOverflow(3))->count(),
                'overdue' => $all->whereIn('status', [Candidacy::STATUS_ACTIVE, Candidacy::STATUS_EXTENDED])
                    ->filter(fn ($c) => $c->rpd_deadline < now()->startOfDay())->count(),
                'closed' => $all->whereIn('status', [Candidacy::STATUS_DEFENDED, Candidacy::STATUS_DISMISSED])->count(),
            ],
        ]);
    }

    /** CGS: register a student's candidature so the clock starts. */
    public function create()
    {
        return view('norhanis::candidacy.form', [
            'students' => $this->studentsWithoutCandidacy(),
            'programmeTypes' => Candidacy::programmeTypes(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => [
                'required', 'integer',
                // unique is the schema's rule too -- checked here so the
                // student gets a message instead of a 500 from the index.
                Rule::exists('users', 'id')->where('role', Role::STUDENT),
                Rule::unique('candidacies', 'student_id'),
            ],
            'programme_type' => ['required', Rule::in(array_keys(Candidacy::programmeTypes()))],
            'candidature_start_date' => ['required', 'date', 'before_or_equal:today'],
        ], [
            'student_id.unique' => 'That student already has a candidacy on record. Edit it instead of adding another.',
            'candidature_start_date.before_or_equal' => 'Candidature cannot start in the future.',
        ]);

        // The deadline is computed once, here, and stored. After this the
        // stored date is the truth -- see the Candidacy model for why.
        $candidacy = Candidacy::create($data + [
            'rpd_deadline' => Candidacy::initialDeadline($data['programme_type'], $data['candidature_start_date']),
            'status' => Candidacy::STATUS_ACTIVE,
        ]);

        return redirect()
            ->route('candidacies.index')
            ->with('status', "Candidacy registered. RPD deadline: {$candidacy->rpd_deadline->format('j M Y')}.");
    }

    /** CGS: record that the defence happened, which stops the clock. */
    public function markDefended(Request $request, Candidacy $candidacy)
    {
        $data = $request->validate([
            'defended_on' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $candidacy->update([
            'defended_on' => $data['defended_on'],
            'status' => Candidacy::STATUS_DEFENDED,
        ]);

        // Nothing left to remind them about.
        $candidacy->reminderLogs()->delete();

        return back()->with('status', "{$candidacy->student->name}'s RPD is recorded as defended. Reminders have stopped.");
    }

    /** The student's own view of their clock. */
    public function mine(Request $request)
    {
        $candidacy = Candidacy::where('student_id', $request->user()->id)->first();

        $appeals = $candidacy
            ? Application::with('history')
                ->whereIn('id', RpdAppealDetail::where('candidacy_id', $candidacy->id)->pluck('application_id'))
                ->latest()
                ->get()
            : collect();

        return view('norhanis::candidacy.mine', [
            'candidacy' => $candidacy,
            'appeals' => $appeals,
            'details' => $candidacy
                ? RpdAppealDetail::whereIn('application_id', $appeals->pluck('id'))->get()->keyBy('application_id')
                : collect(),
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    protected function studentsWithoutCandidacy()
    {
        return User::where('role', Role::STUDENT)
            ->whereNotIn('id', Candidacy::pluck('student_id'))
            ->orderBy('name')
            ->get();
    }
}
