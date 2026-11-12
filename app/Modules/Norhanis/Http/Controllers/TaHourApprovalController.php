<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Norhanis\Models\TaHourLog;
use App\Modules\Norhanis\Notifications\TaHoursDecided;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The course lecturer's side of Daily Log TA Hours: the submitted rows that
 * name them, approved or rejected row by row or a student's month at once.
 * A lecturer only ever sees, or decides, rows that name them.
 */
class TaHourApprovalController extends Controller
{
    public function index(Request $request)
    {
        $lecturer = $request->user();

        $pending = TaHourLog::with('student')
            ->where('course_lecturer_id', $lecturer->id)
            ->where('state', TaHourLog::PENDING)
            ->orderBy('activity_date')->orderBy('time_in')
            ->get()
            ->groupBy(fn (TaHourLog $log) => $log->student_id.'|'.$log->activity_date->format('Y-m'))
            ->map(fn (Collection $rows) => [
                'student' => $rows->first()->student,
                'month' => $rows->first()->activity_date->format('F Y'),
                'rows' => $rows,
                'hours' => (float) $rows->sum('hours'),
            ])
            ->values();

        $decided = TaHourLog::with('student')
            ->where('course_lecturer_id', $lecturer->id)
            ->whereIn('state', [TaHourLog::APPROVED, TaHourLog::REJECTED])
            ->latest('decided_at')
            ->limit(20)
            ->get();

        return view('norhanis::ta_hours.approvals', [
            'groups' => $pending,
            'decided' => $decided,
        ]);
    }

    public function decide(Request $request)
    {
        $lecturer = $request->user();

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:1000'],
        ], [
            'reason.required_if' => 'Give the student a reason when rejecting hours.',
        ]);

        $logs = TaHourLog::whereIn('id', $data['ids'])
            ->where('course_lecturer_id', $lecturer->id)
            ->where('state', TaHourLog::PENDING)
            ->get();

        if ($logs->isEmpty()) {
            return back()->withErrors(['ids' => 'Those hours are no longer waiting for you.']);
        }

        $approved = $data['decision'] === 'approve';

        DB::transaction(function () use ($logs, $approved, $data) {
            TaHourLog::whereIn('id', $logs->pluck('id'))->update([
                'state' => $approved ? TaHourLog::APPROVED : TaHourLog::REJECTED,
                'decided_at' => now(),
                'decision_reason' => $approved ? null : $data['reason'],
            ]);
        });

        $logs->load('student')->groupBy('student_id')->each(function (Collection $rows) use ($approved, $data, $lecturer) {
            $rows->first()->student?->notify(new TaHoursDecided(
                $rows, $approved, $lecturer->name, $approved ? null : $data['reason']
            ));
        });

        return redirect()->route('ta-hours.approvals')->with('status', sprintf(
            '%d %s %s. The student has been emailed.',
            $logs->count(), $logs->count() === 1 ? 'row' : 'rows', $approved ? 'approved' : 'rejected'
        ));
    }
}
