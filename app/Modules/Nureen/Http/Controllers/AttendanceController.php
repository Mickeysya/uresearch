<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Notifications\AttendanceAtRisk;
use App\Modules\Nureen\Support\AttendanceRiskEvaluator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The non-workflow half of Attendance: getting UTrace data in (as a CSV
 * export, not a live API -- see the module README) and the two dashboards
 * built from it. The appeal chain itself lives in AttendanceAppealController.
 */
class AttendanceController extends Controller
{
    public function uploadForm()
    {
        return view('nureen::attendance.upload');
    }

    /**
     * CSV columns: matric_no, period_end (YYYY-MM-DD), sessions_attended,
     * sessions_total. One row per student per period; re-uploading a period
     * already on file updates it rather than duplicating it.
     *
     * Not routed through DocumentStore: this file is not attached to any
     * one student's application, it is parsed once into attendance_records
     * and then discarded, the same way a bulk import would be anywhere else
     * in the app.
     */
    public function upload(Request $request)
    {
        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $handle = fopen($request->file('csv_file')->getRealPath(), 'r');
        $header = fgetcsv($handle);

        $expected = ['matric_no', 'period_end', 'sessions_attended', 'sessions_total'];
        if ($header === false || array_map('strtolower', array_map('trim', $header)) !== $expected) {
            fclose($handle);

            return back()->with('error', 'CSV header must be exactly: '.implode(', ', $expected));
        }

        $processed = 0;
        $skipped = 0;
        $newlyAtRisk = [];

        DB::transaction(function () use ($handle, &$processed, &$skipped, &$newlyAtRisk) {
            while (($row = fgetcsv($handle)) !== false) {
                [$matricNo, $periodEnd, $attended, $total] = array_pad($row, 4, null);

                $student = User::where('matric_no', trim((string) $matricNo))
                    ->where('role', Role::STUDENT)
                    ->first();

                if (! $student || ! is_numeric($attended) || ! is_numeric($total)) {
                    $skipped++;

                    continue;
                }

                $wasAtRisk = AttendanceRecord::where('student_id', $student->id)
                    ->where('period_end', '<', $periodEnd)
                    ->orderByDesc('period_end')
                    ->value('at_risk') ?? false;

                $record = AttendanceRecord::updateOrCreate(
                    ['student_id' => $student->id, 'period_end' => $periodEnd],
                    ['sessions_attended' => (int) $attended, 'sessions_total' => (int) $total]
                );

                $record->update(['at_risk' => AttendanceRiskEvaluator::isAtRisk($record)]);

                if ($record->at_risk && ! $wasAtRisk) {
                    $newlyAtRisk[] = $record;
                }

                $processed++;
            }
        });

        fclose($handle);

        foreach ($newlyAtRisk as $record) {
            $record->student->notify(new AttendanceAtRisk($record));
            $record->student->supervisor?->notify(new AttendanceAtRisk($record));
        }

        return back()->with('status',
            "Processed {$processed} record(s), skipped {$skipped}. ".
            count($newlyAtRisk).' newly flagged at-risk.'
        );
    }

    /** The logged-in student's current standing: percentage + at-risk banner. */
    public function overview(Request $request)
    {
        $latest = AttendanceRecord::where('student_id', $request->user()->id)
            ->orderByDesc('period_end')
            ->first();

        return view('nureen::attendance.overview', ['latest' => $latest]);
    }

    /** The logged-in student's full period-by-period attendance record. */
    public function history(Request $request)
    {
        $records = AttendanceRecord::where('student_id', $request->user()->id)
            ->orderByDesc('period_end')
            ->get();

        return view('nureen::attendance.history', ['records' => $records]);
    }

    /** CGS's "At-Risk" list: the latest record for every currently at-risk student. */
    public function atRisk()
    {
        $latestPerStudent = AttendanceRecord::select('student_id', DB::raw('MAX(period_end) as max_period_end'))
            ->groupBy('student_id');

        $records = AttendanceRecord::joinSub($latestPerStudent, 'latest', function ($join) {
            $join->on('attendance_records.student_id', '=', 'latest.student_id')
                ->on('attendance_records.period_end', '=', 'latest.max_period_end');
        })
            ->where('attendance_records.at_risk', true)
            ->with('student')
            ->orderBy('attendance_records.percentage')
            ->get(['attendance_records.*']);

        return view('nureen::attendance.at_risk', ['records' => $records]);
    }
}
