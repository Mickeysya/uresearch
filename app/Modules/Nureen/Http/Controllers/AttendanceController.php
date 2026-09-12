<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Notifications\AttendanceAtRisk;
use App\Modules\Nureen\Support\AttendanceRiskEvaluator;
use Illuminate\Http\Request;
use App\Modules\Nureen\Imports\AttendanceSheetImport;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

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
     * Columns: matric_no, period_end (YYYY-MM-DD), sessions_attended,
     * sessions_total. One row per student per period; re-uploading a period
     * already on file updates it rather than duplicating it.
     *
     * Reads through maatwebsite/excel rather than fgetcsv, because CGS
     * exports from UTrace as .xlsx — hand-parsing CSV meant someone had to
     * convert the file first, every time. .csv still works unchanged.
     *
     * Not routed through DocumentStore: this file is not attached to any one
     * student's application, it is parsed once into attendance_records and
     * then discarded, the same way a bulk import would be anywhere else.
     */
    public function upload(Request $request)
    {
        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120'],
        ]);

        $expected = ['matric_no', 'period_end', 'sessions_attended', 'sessions_total'];

        try {
            $sheets = Excel::toArray(new AttendanceSheetImport(), $request->file('csv_file'));
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'That file could not be read. Upload a .csv or .xlsx export from UTrace.');
        }

        $rows = $sheets[0] ?? [];
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($rows) ?? []);

        if (array_slice($header, 0, 4) !== $expected) {
            return back()->with('error', 'The first row must be exactly: '.implode(', ', $expected));
        }

        $processed = 0;
        $skipped = 0;
        $newlyAtRisk = [];

        DB::transaction(function () use ($rows, &$processed, &$skipped, &$newlyAtRisk) {
            foreach ($rows as $row) {
                [$matricNo, $periodEnd, $attended, $total] = array_pad(array_values($row), 4, null);

                // A spreadsheet may hand back a date object where a CSV gave
                // a string; normalise before it reaches the database.
                if ($periodEnd instanceof \DateTimeInterface) {
                    $periodEnd = $periodEnd->format('Y-m-d');
                }

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
        // latestPerStudent() lives on the model: the CGS dashboard and the
        // admin average both need the same "one row per student, their most
        // recent period" join, and all three had built it separately.
        $records = AttendanceRecord::latestPerStudent()
            ->where('attendance_records.at_risk', true)
            ->with('student')
            ->orderBy('attendance_records.percentage')
            ->get();

        return view('nureen::attendance.at_risk', ['records' => $records]);
    }
}
