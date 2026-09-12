<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Exports\AttendanceTemplateExport;
use App\Modules\Nureen\Imports\AttendanceSheetImport;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Notifications\AttendanceAtRisk;
use App\Modules\Nureen\Support\AttendanceRiskEvaluator;
use App\Modules\Nureen\Support\AttendanceSheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        return view('nureen::attendance.upload', [
            'columns' => AttendanceSheet::COLUMNS,
        ]);
    }

    /**
     * A blank, correctly-shaped sheet for CGS to fill in.
     *
     * The upload requires an exact header row, and before this the only way
     * to learn that was to get it wrong and read the error. Both formats come
     * from AttendanceSheet, so the template cannot drift from what upload()
     * accepts.
     *
     * .xlsx is the default and the one to prefer: Excel keeps `period_end` as
     * a real date cell. A .csv filled in and re-saved through Excel is where
     * dates get rewritten into the local format.
     */
    public function template(Request $request)
    {
        $stamp = now()->format('Y-m-d');

        if ($request->query('format') === 'csv') {
            return response()->streamDownload(function () {
                $out = fopen('php://output', 'w');

                fputcsv($out, AttendanceSheet::COLUMNS);

                foreach (AttendanceSheet::sampleRows() as $row) {
                    fputcsv($out, $row);
                }

                fclose($out);
            }, "attendance-template-{$stamp}.csv", ['Content-Type' => 'text/csv']);
        }

        return Excel::download(new AttendanceTemplateExport(), "attendance-template-{$stamp}.xlsx");
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

        $expected = AttendanceSheet::COLUMNS;

        try {
            $sheets = Excel::toArray(new AttendanceSheetImport(), $request->file('csv_file'));
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'That file could not be read. Upload a .csv or .xlsx export from UTrace, '
                .'or start from the template on this page.');
        }

        $rows = $sheets[0] ?? [];
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($rows) ?? []);

        if (array_slice($header, 0, 4) !== $expected) {
            return back()->with('error', 'The first row must be exactly: '.implode(', ', $expected)
                .'. Download the template on this page to start from a correct file.');
        }

        $processed = 0;
        $newlyAtRisk = [];
        // Why each row was skipped, so staff can repair the file instead of
        // being told only how many rows failed.
        $problems = [];

        DB::transaction(function () use ($rows, &$processed, &$problems, &$newlyAtRisk) {
            foreach ($rows as $i => $row) {
                // +2: the sheet's own numbering, counting the header as row 1.
                $line = $i + 2;

                [$matricNo, $periodEnd, $attended, $total] = array_pad(array_values($row), 4, null);

                $matricNo = trim((string) $matricNo);

                // A wholly blank row is trailing whitespace, not a mistake.
                if ($matricNo === '' && ($periodEnd === null || trim((string) $periodEnd) === '')) {
                    continue;
                }

                // An .xlsx date cell, an Excel serial, or a string in any of
                // the formats Excel writes — all normalised to Y-m-d here.
                $periodEnd = AttendanceSheet::parseDate($periodEnd);

                $student = User::where('matric_no', $matricNo)
                    ->where('role', Role::STUDENT)
                    ->first();

                if (! $student) {
                    $problems[] = "Row {$line}: no student with matric number \"{$matricNo}\".";

                    continue;
                }

                if ($periodEnd === null) {
                    $problems[] = "Row {$line}: period_end is not a date the importer recognises (use YYYY-MM-DD).";

                    continue;
                }

                if (! is_numeric($attended) || ! is_numeric($total)) {
                    $problems[] = "Row {$line}: sessions_attended and sessions_total must both be numbers.";

                    continue;
                }

                if ((int) $total <= 0) {
                    $problems[] = "Row {$line}: sessions_total must be greater than zero.";

                    continue;
                }

                if ((int) $attended > (int) $total) {
                    $problems[] = "Row {$line}: sessions_attended ({$attended}) is greater than sessions_total ({$total}).";

                    continue;
                }

                $wasAtRisk = AttendanceRecord::where('student_id', $student->id)
                    ->whereDate('period_end', '<', $periodEnd)
                    ->orderByDesc('period_end')
                    ->value('at_risk') ?? false;

                // Replaces the period if it is already on file. See the note
                // on recordPeriod() for why the lookup cannot be a plain
                // equality on the date string.
                $record = AttendanceRecord::recordPeriod(
                    $student->id,
                    $periodEnd,
                    (int) $attended,
                    (int) $total,
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

        $summary = "Processed {$processed} ".Str::plural('record', $processed).'. '
            .count($newlyAtRisk).' newly flagged at-risk.';

        if ($problems === []) {
            return back()->with('status', $summary);
        }

        // The good rows are already saved — a bad row skips itself rather
        // than costing the whole upload — so this is a warning, not an error.
        // Listing the first few beats a bare count: the fix is in the file,
        // and staff need to know which line to open.
        $shown = array_slice($problems, 0, 5);
        $extra = count($problems) - count($shown);

        return back()
            ->with('status', $summary)
            ->with('warning', count($problems).' '.Str::plural('row', $problems).' skipped — '
                .implode(' ', $shown)
                .($extra > 0 ? " (and {$extra} more)" : ''));
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
