<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Nureen\Exports\AttendanceTemplateExport;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Models\AttendanceSyncRun;
use App\Modules\Nureen\Support\AttendanceIngestor;
use App\Modules\Nureen\Support\AttendanceSheet;
use App\Modules\Nureen\Support\AttendanceSourceFactory;
use App\Modules\Nureen\Support\AttendanceSyncRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * The non-workflow half of Attendance: getting UTrace data in and the two
 * dashboards built from it. Automated ingestion (attendance:sync and the
 * sources it reads from) lives in Support\AttendanceSourceFactory and
 * Support\AttendanceSources; this controller keeps the manual upload as a
 * fallback and shows CGS the automated side's status. The appeal chain
 * itself lives in AttendanceAppealController.
 */
class AttendanceController extends Controller
{
    public function uploadForm()
    {
        return view('nureen::attendance.upload', [
            'columns' => AttendanceSheet::COLUMNS,
            'lastSync' => AttendanceSyncRun::latest('started_at')->first(),
            'activeSource' => AttendanceSourceFactory::make()->label(),
        ]);
    }

    /**
     * Runs a sync on demand, for a CGS member who does not want to wait for
     * the 02:00 schedule -- recovering from a missed night, or
     * demonstrating the automated path live. Calls AttendanceSyncRunner
     * directly rather than `Artisan::call('attendance:sync')`: the command
     * is only registered for console requests (see ModuleProvider::boot()),
     * so a web request cannot find it that way.
     */
    public function syncNow(AttendanceSyncRunner $runner)
    {
        $run = $runner->run();

        return back()->with(
            'status',
            "[{$run->source}] Read {$run->rows_read}. Added {$run->rows_added}, "
                ."updated {$run->rows_updated}, rejected {$run->rows_rejected}. "
                ."{$run->rows_newly_at_risk} newly flagged at-risk."
        );
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

        $parsed = AttendanceSheet::readFile($request->file('csv_file'));

        if ($parsed['error']) {
            return back()->with('error', $parsed['error']);
        }

        $summary = (new AttendanceIngestor())->ingest(collect($parsed['rows']));

        $message = "Processed {$summary->processed()} ".Str::plural('record', $summary->processed()).'. '
            .$summary->newlyAtRisk.' newly flagged at-risk.';

        if ($summary->rejected === 0) {
            return back()->with('status', $message);
        }

        // The good rows are already saved — a bad row skips itself rather
        // than costing the whole upload — so this is a warning, not an error.
        // Listing the first few beats a bare count: the fix is in the file,
        // and staff need to know which line to open.
        $shown = array_slice($summary->reasons, 0, 5);
        $extra = $summary->rejected - count($shown);

        return back()
            ->with('status', $message)
            ->with('warning', $summary->rejected.' '.Str::plural('row', $summary->rejected).' skipped. '
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
