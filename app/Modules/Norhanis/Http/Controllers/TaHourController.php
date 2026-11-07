<?php

namespace App\Modules\Haziq\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Models\TaHourLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * TA hours tracked as a plain activity log — see the migration's docblock
 * for why this stops short of the terms document's full per-activity
 * approver/claim-formula engine.
 */
class TaHourController extends Controller
{
    /** The one rule this whole screen exists to keep visible — see store()'s own validation. */
    protected const MONTHLY_CAP = 21.0;

    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->isStudent()) {
            $logs = TaHourLog::where('student_id', $user->id)->latest('activity_date')->paginate(25);
            $total = TaHourLog::where('student_id', $user->id)->sum('hours');

            return view('haziq::ta_hours.index', [
                'logs' => $logs,
                'total' => $total,
                'student' => $user,
                'monthlyTrend' => $this->monthlyTrendFor($user),
                'byStudentThisMonth' => null,
            ]);
        }

        abort_unless($user->isAdmin() || $user->isCgs() || $user->role === Role::SUPERVISOR, 403);

        $query = TaHourLog::with('student')->latest('activity_date');
        $scopeToSupervisees = fn ($q) => $user->role === Role::SUPERVISOR
            ? $q->whereHas('student', fn ($sq) => $sq->where('supervisor_id', $user->id))
            : $q;

        $scopeToSupervisees($query);

        $logs = $query->paginate(25);

        return view('haziq::ta_hours.index', [
            'logs' => $logs,
            'total' => null,
            'student' => null,
            'students' => $this->selectableStudents($user),
            'monthlyTrend' => null,
            'byStudentThisMonth' => $this->byStudentThisMonth($scopeToSupervisees),
        ]);
    }

    /** Scoped the same way store() enforces it — a supervisor can only ever log for their own supervisees. */
    protected function selectableStudents(User $user): Collection
    {
        $query = User::where('role', Role::STUDENT)->orderBy('name');

        if ($user->role === Role::SUPERVISOR) {
            $query->where('supervisor_id', $user->id);
        }

        return $query->get(['id', 'name', 'matric_no']);
    }

    /**
     * The one number the shared dashboard's "Postgraduate Progress" panel
     * needs — this calendar month's hours against the cap, for a quick
     * glance. Reuses nothing from monthlyTrendFor() since there's no
     * walk to drift from here — a single month's sum is either right or
     * it isn't.
     *
     * @return array{hours: float, cap: float}
     */
    public function thisMonthFor(User $student): array
    {
        $hours = (float) TaHourLog::where('student_id', $student->id)
            ->where('activity_date', '>=', Carbon::today()->startOfMonth())
            ->sum('hours');

        return ['hours' => $hours, 'cap' => self::MONTHLY_CAP];
    }

    /**
     * The student's own last 6 months, against the monthly cap — same
     * "visual trend, not a monitoring tool" spirit as Allowance's trend
     * chart.
     */
    protected function monthlyTrendFor(User $student): Collection
    {
        $thisMonth = Carbon::today()->startOfMonth();

        $totals = TaHourLog::where('student_id', $student->id)
            ->where('activity_date', '>=', $thisMonth->copy()->subMonthsNoOverflow(5))
            ->get()
            ->groupBy(fn (TaHourLog $log) => $log->activity_date->format('Y-m'))
            ->map(fn ($rows) => (float) $rows->sum('hours'));

        return collect(range(5, 0))->map(function (int $i) use ($thisMonth, $totals) {
            $month = $thisMonth->copy()->subMonthsNoOverflow($i);

            return [
                'month' => $month->format('M Y'),
                'hours' => (float) $totals->get($month->format('Y-m'), 0),
            ];
        });
    }

    /**
     * Who's closest to this month's 21hr cap right now — the one thing
     * an approver actually needs a glance at before logging more hours
     * for someone.
     */
    protected function byStudentThisMonth(callable $scope): Collection
    {
        $thisMonth = Carbon::today()->startOfMonth();

        $query = TaHourLog::with('student')->where('activity_date', '>=', $thisMonth);
        $scope($query);

        return $query->get()
            ->groupBy('student_id')
            ->map(fn ($rows) => [
                'student' => $rows->first()->student,
                'hours' => (float) $rows->sum('hours'),
            ])
            ->sortByDesc('hours')
            ->values();
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isCgs() || $user->role === Role::SUPERVISOR, 403);

        $data = $request->validate([
            'student_id' => ['required', 'exists:users,id'],
            'activity_type' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'activity_date' => ['required', 'date'],
            'hours' => ['required', 'numeric', 'min:0.25', 'max:21'],
            'approver_role' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $student = User::where('id', $data['student_id'])->where('role', Role::STUDENT)->firstOrFail();

        if ($user->role === Role::SUPERVISOR) {
            abort_unless($student->supervisor_id === $user->id, 403, 'Not your supervisee.');
        }

        TaHourLog::create($data + ['recorded_by' => $user->id]);

        return back()->with('status', 'TA hours recorded.');
    }
}
