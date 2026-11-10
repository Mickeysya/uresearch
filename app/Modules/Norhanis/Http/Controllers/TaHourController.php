<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\TaCourseLecturer;
use App\Modules\Norhanis\Models\TaHourLog;
use App\Modules\Norhanis\Notifications\TaHoursAwaitingApproval;
use App\Modules\Norhanis\Support\Roles;
use App\Modules\Norhanis\Support\TaStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Daily Log TA Hours, as GA/GRA students know it from UCampus: pick the
 * claim year and month, add a row per session (date, time in, time out,
 * activity, course lecturer), then submit. The course lecturer each row
 * names approves it (TaHourApprovalController). Only approved hours count.
 *
 * Supervisors and CGS can see the hours but no longer enter them: in UAT,
 * students and lecturers said the student logs and the lecturer signs off.
 */
class TaHourController extends Controller
{
    /** 24-hour HH:MM, as UCampus asks for. */
    protected const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === Roles::LECTURER) {
            return redirect()->route('ta-hours.approvals');
        }

        if ($user->isStudent()) {
            return $request->filled('month')
                ? $this->openMonth($request)
                : $this->studentIndex($user);
        }

        abort_unless($user->isAdmin() || $user->isCgs() || $user->role === Role::SUPERVISOR, 403);

        $scopeToSupervisees = fn ($q) => $user->role === Role::SUPERVISOR
            ? $q->whereHas('student', fn ($sq) => $sq->where('supervisor_id', $user->id))
            : $q;

        $query = TaHourLog::with(['student', 'courseLecturer', 'lecturerCode'])
            ->where('state', '!=', TaHourLog::DRAFT)
            ->latest('activity_date');
        $scopeToSupervisees($query);

        return view('norhanis::ta_hours.index', [
            'logs' => $query->paginate(25),
            'byStudentThisMonth' => $this->byStudentThisMonth($scopeToSupervisees),
            'cap' => $this->cap(),
        ]);
    }

    protected function studentIndex(User $student)
    {
        $ta = app(TaStatus::class)->for($student);
        $months = TaHourLog::where('student_id', $student->id)->latest('activity_date')->get()
            ->groupBy(fn (TaHourLog $log) => $log->activity_date->format('Y-m'))
            ->map(fn (Collection $rows, string $ym) => [
                'year' => (int) substr($ym, 0, 4),
                'month' => (int) substr($ym, 5, 2),
                'label' => $rows->first()->activity_date->format('F Y'),
                'counts' => $rows->countBy('state'),
                'approved' => (float) $rows->where('state', TaHourLog::APPROVED)->sum('hours'),
            ])
            ->values();

        return view('norhanis::ta_hours.student', [
            'eligible' => $this->eligible($ta),
            'ta' => $ta,
            'months' => $months,
            'years' => $this->years(),
            'monthlyTrend' => $this->monthlyTrendFor($student),
            'cap' => $this->cap(),
            'hoursRequired' => (float) config('norhanis.ta.hours_required'),
        ]);
    }

    /** The Year / Month picker's "Next": on to that month's sheet. */
    protected function openMonth(Request $request)
    {
        $this->eligibleStudent($request);

        $data = $request->validate([
            'year' => ['required', 'integer', 'in:'.implode(',', $this->years())],
            'month' => ['required', 'integer', 'between:1,12'],
        ]);

        if (Carbon::create((int) $data['year'], (int) $data['month'], 1)->greaterThan(Carbon::today())) {
            return redirect()->route('ta-hours.index')->withInput()
                ->withErrors(['month' => 'You cannot log hours for a month that has not started yet.']);
        }

        return redirect()->route('ta-hours.sheet', [(int) $data['year'], (int) $data['month']]);
    }

    /** The sheet for one claim month: its rows, and the "+" to add one. */
    public function sheet(Request $request, int $year, int $month)
    {
        $student = $this->eligibleStudent($request);
        $this->assertClaimMonth($year, $month);

        $logs = TaHourLog::with(['courseLecturer', 'lecturerCode'])
            ->where('student_id', $student->id)
            ->inMonth($year, $month)
            ->orderBy('activity_date')->orderBy('time_in')
            ->get();

        return view('norhanis::ta_hours.sheet', [
            'year' => $year,
            'month' => $month,
            'monthName' => Carbon::create($year, $month, 1)->format('F'),
            'logs' => $logs,
            'activities' => config('norhanis.ta.activities'),
            'lecturers' => TaCourseLecturer::choices(),
            'cap' => $this->cap(),
            'counted' => (float) $logs->where('state', '!=', TaHourLog::REJECTED)->sum('hours'),
            'minDate' => Carbon::create($year, $month, 1)->toDateString(),
            'maxDate' => min(Carbon::create($year, $month, 1)->endOfMonth(), Carbon::today())->toDateString(),
        ]);
    }

    public function store(Request $request, int $year, int $month)
    {
        $student = $this->eligibleStudent($request);
        $this->assertClaimMonth($year, $month);

        $first = Carbon::create($year, $month, 1);
        $last = min($first->copy()->endOfMonth(), Carbon::today());

        $data = $request->validate([
            'activity_date' => ['required', 'date', 'after_or_equal:'.$first->toDateString(), 'before_or_equal:'.$last->toDateString()],
            'time_in' => ['required', 'regex:'.self::TIME],
            'time_out' => ['required', 'regex:'.self::TIME],
            'activity_type' => ['required', 'string', 'in:'.implode(',', array_keys(config('norhanis.ta.activities')))],
            'course_lecturer' => ['required', 'string', 'max:200'],
        ], [
            'activity_date.after_or_equal' => "The date must be in {$first->format('F Y')}.",
            'activity_date.before_or_equal' => "The date must be in {$first->format('F Y')}, and not later than today.",
            'time_in.regex' => 'Use the 24-hour format HH:MM, e.g. 13:45 for 1:45pm.',
            'time_out.regex' => 'Use the 24-hour format HH:MM, e.g. 13:45 for 1:45pm.',
        ]);

        if ($data['time_out'] <= $data['time_in']) {
            throw ValidationException::withMessages(['time_out' => 'Time out must be later than time in.']);
        }

        $lecturer = TaCourseLecturer::resolve($data['course_lecturer']);
        if (! $lecturer) {
            throw ValidationException::withMessages(['course_lecturer' => 'Choose a course lecturer from the list, by name or staff code.']);
        }

        $hours = TaHourLog::hoursBetweenTimes($data['time_in'], $data['time_out']);
        $date = Carbon::parse($data['activity_date'])->toDateString();

        $overlaps = TaHourLog::counting()
            ->where('student_id', $student->id)
            ->whereDate('activity_date', $date)
            ->whereNotNull('time_in')
            ->where('time_in', '<', $data['time_out'])
            ->where('time_out', '>', $data['time_in'])
            ->exists();
        if ($overlaps) {
            throw ValidationException::withMessages(['time_in' => 'These times overlap a row you have already logged on that date.']);
        }

        $counted = (float) TaHourLog::counting()->where('student_id', $student->id)->inMonth($year, $month)->sum('hours');
        if ($counted + $hours > $this->cap()) {
            throw ValidationException::withMessages([
                'time_out' => sprintf('This row would take %s past the %s-hour monthly cap (%s hrs logged already).',
                    $first->format('F'), $this->num($this->cap()), $this->num($counted)),
            ]);
        }

        TaHourLog::create([
            'student_id' => $student->id,
            'activity_type' => $data['activity_type'],
            'activity_date' => $date,
            'time_in' => $data['time_in'],
            'time_out' => $data['time_out'],
            'hours' => $hours,
            'course_lecturer_id' => $lecturer->user_id,
            'state' => TaHourLog::DRAFT,
            'recorded_by' => $student->id,
        ]);

        return redirect()->route('ta-hours.sheet', [$year, $month])
            ->with('status', 'Row added. Submit the month when every row is in.');
    }

    /** Sends every unsubmitted row of the month to the lecturer it names. */
    public function submit(Request $request, int $year, int $month)
    {
        $student = $this->eligibleStudent($request);
        $this->assertClaimMonth($year, $month);

        $drafts = TaHourLog::where('student_id', $student->id)
            ->inMonth($year, $month)
            ->where('state', TaHourLog::DRAFT)
            ->get();

        if ($drafts->isEmpty()) {
            return back()->withErrors(['submit' => 'There are no new rows to submit for this month.']);
        }

        DB::transaction(function () use ($drafts) {
            TaHourLog::whereIn('id', $drafts->pluck('id'))->update([
                'state' => TaHourLog::PENDING,
                'submitted_at' => now(),
            ]);
        });

        $monthLabel = Carbon::create($year, $month, 1)->format('F Y');
        $drafts->groupBy('course_lecturer_id')->each(function (Collection $rows, $lecturerId) use ($student, $monthLabel) {
            User::find($lecturerId)?->notify(new TaHoursAwaitingApproval(
                $student, $monthLabel, $rows->count(), (float) $rows->sum('hours')
            ));
        });

        return redirect()->route('ta-hours.sheet', [$year, $month])
            ->with('status', 'Submitted. Your course lecturer has been emailed to approve these hours.');
    }

    /** A row not yet submitted, or one the lecturer rejected, can be removed and logged again. */
    public function destroy(Request $request, TaHourLog $log)
    {
        $student = $request->user();
        abort_unless($student->isStudent() && $log->student_id === $student->id, 403);
        abort_unless(in_array($log->state, [TaHourLog::DRAFT, TaHourLog::REJECTED], true), 403, 'Submitted hours cannot be removed.');

        $year = $log->activity_date->year;
        $month = $log->activity_date->month;
        $log->delete();

        return redirect()->route('ta-hours.sheet', [$year, $month])->with('status', 'Row removed.');
    }

    /**
     * This calendar month's approved hours against the cap, for the shared
     * dashboard's "Postgraduate Progress" panel (Haziq's DashboardSupplier).
     *
     * @return array{hours: float, cap: float}
     */
    public function thisMonthFor(User $student): array
    {
        $hours = (float) TaHourLog::approved()
            ->where('student_id', $student->id)
            ->where('activity_date', '>=', Carbon::today()->startOfMonth())
            ->sum('hours');

        return ['hours' => $hours, 'cap' => $this->cap()];
    }

    /* ---------------- helpers ---------------- */

    /** A GA/GRA today, or nobody can tell (Haziq's module absent). */
    protected function eligible(array $ta): bool
    {
        return ! $ta['known'] || $ta['type'] !== null;
    }

    protected function eligibleStudent(Request $request): User
    {
        $student = $request->user();
        abort_unless($student->isStudent(), 403);
        abort_unless($this->eligible(app(TaStatus::class)->for($student)), 403, 'You are not a GA or GRA.');

        return $student;
    }

    /** A real month, this year or last, and not in the future. */
    protected function assertClaimMonth(int $year, int $month): void
    {
        abort_unless($month >= 1 && $month <= 12 && in_array($year, $this->years(), true), 404);
        abort_if(Carbon::create($year, $month, 1)->greaterThan(Carbon::today()), 404);
    }

    /** @return array<int, int> */
    protected function years(): array
    {
        $year = Carbon::today()->year;

        return [$year, $year - 1];
    }

    protected function cap(): float
    {
        return (float) config('norhanis.ta.monthly_cap', 21);
    }

    protected function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    /** The student's own approved hours over the last 6 months, against the cap. */
    protected function monthlyTrendFor(User $student): Collection
    {
        $thisMonth = Carbon::today()->startOfMonth();

        $totals = TaHourLog::approved()
            ->where('student_id', $student->id)
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

    /** Approved hours this month per student in the viewer's scope. */
    protected function byStudentThisMonth(callable $scope): Collection
    {
        $query = TaHourLog::approved()->with('student')->where('activity_date', '>=', Carbon::today()->startOfMonth());
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
}
