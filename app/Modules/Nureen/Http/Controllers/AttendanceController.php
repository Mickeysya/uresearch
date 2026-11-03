<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Exports\AttendanceTemplateExport;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Models\AttendanceRiskState;
use App\Modules\Nureen\Models\AttendanceSyncRun;
use App\Modules\Nureen\Support\AttendanceEarlyWarning;
use App\Modules\Nureen\Support\AttendanceIngestor;
use App\Modules\Nureen\Support\AttendanceLeave;
use App\Modules\Nureen\Support\AttendanceRiskEvaluator;
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

        // Same as after an automated sync: the full risk check and the
        // automatic early warnings -- see Support\AttendanceEarlyWarning.
        $warnings = app(AttendanceEarlyWarning::class)->run();

        $message = "Processed {$summary->processed()} ".Str::plural('record', $summary->processed()).'. '
            .$warnings['newly_at_risk'].' newly flagged at-risk.';

        // The sheet's leave_days put someone over the allowance: warn, the
        // leave still counts (AttendanceLeave).
        if ($summary->leaveWarnings !== []) {
            session()->flash('leave_warnings', $summary->leaveWarnings);
        }

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

    /**
     * The logged-in student's current standing: their latest period, their
     * attendance so far this semester (the figure the 80% requirement and
     * the early-warning email use), and the at-risk banner. Never the
     * projection or the trend -- those are CGS's alone.
     */
    public function overview(Request $request)
    {
        $latest = AttendanceRecord::where('student_id', $request->user()->id)
            ->orderByDesc('period_end')
            ->first();

        return view('nureen::attendance.overview', [
            'latest' => $latest,
            'semesterSoFar' => $latest ? AttendanceRiskEvaluator::assess($request->user()->id)['current'] : null,
        ]);
    }

    /** The logged-in student's full period-by-period attendance record. */
    public function history(Request $request)
    {
        $records = AttendanceRecord::where('student_id', $request->user()->id)
            ->orderByDesc('period_end')
            ->get();

        return view('nureen::attendance.history', ['records' => $records]);
    }

    /**
     * CGS's at-risk list: every student whose latest risk check flagged
     * them -- by the original rule, the end-of-semester projection, or both
     * -- with the projection, the trend and when they were last emailed.
     * The ONLY screen the projection appears on; see
     * Support\AttendanceEarlyWarning for where the figures come from.
     *
     * ?sort=projected (default, lowest first) | current | name
     * ?q= matric number or name
     */
    public function atRisk(Request $request)
    {
        $sort = in_array($request->query('sort'), ['projected', 'current', 'name'], true) ? $request->query('sort') : 'projected';
        $search = trim((string) $request->query('q', ''));

        $states = AttendanceRiskState::query()
            ->where('at_risk', true)
            ->with('student')
            ->withMax('alerts as last_emailed_at', 'sent_at')
            ->when($search !== '', fn ($q) => $q->whereHas('student', fn ($s) => $s
                ->where('name', 'like', "%{$search}%")
                ->orWhere('matric_no', 'like', "%{$search}%")))
            ->get();

        // Sorted in PHP: a handful of rows, and "no projection yet" must sort
        // after every real figure whichever database this runs on.
        $states = match ($sort) {
            'current' => $states->sortBy(fn ($s) => $s->current_percentage ?? INF),
            'name' => $states->sortBy(fn ($s) => mb_strtolower($s->student?->name ?? '')),
            default => $states->sortBy(fn ($s) => $s->projected_percentage ?? INF),
        };

        return view('nureen::attendance.at_risk', [
            // Students over a leave allowance this year -- AttendanceLeave.
            'leaveOver' => app(AttendanceLeave::class)->overview()->filter(fn ($row) => $row['problems'] !== [])->values(),
            'states' => $states->values(),
            'sort' => $sort,
            'search' => $search,
            'threshold' => AttendanceRiskEvaluator::THRESHOLD,
            'semesterPeriods' => (int) config('nureen.attendance.semester_periods'),
        ]);
    }

    /**
     * Approved annual leave: what each student has on record this year
     * against the allowance (config('nureen.attendance.leave')), and a form
     * for CGS to record days for a period already on file. Leave approved
     * by the Chair via UCampus never counts as absence.
     */
    public function leave(Request $request, AttendanceLeave $leave)
    {
        $year = (int) $request->query('year', now()->year);

        return view('nureen::attendance.leave', [
            'year' => $year,
            'rows' => $leave->overview($year),
            'config' => config('nureen.attendance.leave'),
        ]);
    }

    /**
     * Record approved leave days for one student's period. Through
     * AttendanceRecord::recordLeave() -- the same column and recount as
     * the sheet's leave_days -- then the same risk check an upload runs.
     */
    public function recordLeave(Request $request, AttendanceLeave $leave)
    {
        $data = $request->validate([
            'matric_no' => ['required', 'string', 'max:30'],
            'period_end' => ['required', 'date'],
            'leave_days' => ['required', 'integer', 'min:0', 'max:31'],
        ]);

        $student = User::where('matric_no', trim($data['matric_no']))->where('role', Role::STUDENT)->first();

        if (! $student) {
            return back()->withInput()->withErrors(['matric_no' => 'No student has that matric number.']);
        }

        $periodEnd = \Illuminate\Support\Carbon::parse($data['period_end'])->toDateString();
        $record = AttendanceRecord::where('student_id', $student->id)->whereDate('period_end', $periodEnd)->first();

        if (! $record) {
            return back()->withInput()->withErrors(['period_end' => "{$student->name} has no attendance on file for the period ending {$periodEnd}. Upload that period first, or include leave_days in the sheet."]);
        }

        if ((int) $data['leave_days'] > $record->leaveCapacity()) {
            return back()->withInput()->withErrors(['leave_days' => "That period has {$record->leaveCapacity()} missed ".Str::plural('session', $record->leaveCapacity())
                .' not already excused, so at most that many leave days.']);
        }

        AttendanceRecord::recordLeave($student->id, $periodEnd, (int) $data['leave_days']);
        app(AttendanceEarlyWarning::class)->run([$student->id]);

        $problems = $leave->warningsFor([$student->id]);

        return redirect()->route('attendance.leave', ['year' => \Illuminate\Support\Carbon::parse($periodEnd)->year])
            ->with('status', "Recorded {$data['leave_days']} leave ".Str::plural('day', (int) $data['leave_days'])." for {$student->name}, period ending {$periodEnd}.")
            ->with('leave_warnings', $problems);
    }
}
