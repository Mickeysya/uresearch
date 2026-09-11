<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Nureen\Models\AttendanceAppealDetail;
use App\Modules\Nureen\Models\AttendanceRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceAppealController extends Controller
{
    use ApprovesApplications;

    protected function moduleKey(): string
    {
        return 'attendance_appeal';
    }

    public function create(Request $request)
    {
        $latestAtRisk = AttendanceRecord::where('student_id', $request->user()->id)
            ->where('at_risk', true)
            ->orderByDesc('period_end')
            ->first();

        return view('nureen::attendance_appeal.form', ['latestAtRisk' => $latestAtRisk]);
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        $data = $request->validate([
            'attendance_record_id' => ['nullable', 'exists:attendance_records,id'],
            'reason' => ['required', 'string', 'min:20', 'max:2000'],
            'supporting_document' => DocumentStore::rules(),
        ]);

        // If a specific record was named, it must actually be this student's.
        if (! empty($data['attendance_record_id'])) {
            $owned = AttendanceRecord::where('id', $data['attendance_record_id'])
                ->where('student_id', $request->user()->id)
                ->exists();

            abort_unless($owned, 403);
        }

        $application = DB::transaction(function () use ($request, $data, $engine, $documents) {
            $application = Application::create([
                'student_id' => $request->user()->id,
                'submitted_by_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            AttendanceAppealDetail::create([
                'application_id' => $application->id,
                'attendance_record_id' => $data['attendance_record_id'] ?? null,
                'reason' => $data['reason'],
            ]);

            if ($request->hasFile('supporting_document')) {
                $documents->attach($application, $request->file('supporting_document'), 'Supporting Document');
            }

            return $engine->submit($application);
        });

        return redirect()
            ->route('applications.index')
            ->with('status', "Attendance appeal #{$application->id} submitted.");
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents']);

        $details = AttendanceAppealDetail::with('attendanceRecord')
            ->whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        return view('nureen::attendance_appeal.queue', $queue + ['details' => $details]);
    }
}
