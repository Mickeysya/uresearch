@extends('core::layouts.app')

@section('title', 'Upload Attendance')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Upload Attendance</h2>
        <div class="card-divider"></div>

        <p class="queue-meta">
            Export attendance from UTrace, then upload it here. Re-uploading a
            period a student already has on file replaces that row rather than
            duplicating it, so a corrected export can simply be uploaded again.
        </p>

        <h3 style="margin-top: 24px;">1. Start from the template</h3>
        <p class="queue-meta">
            The first row has to match exactly, so it is easier to fill in a
            blank sheet than to reshape an export by hand. Both files already
            contain the header row and two filled-in examples.
        </p>

        <p style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 4px;">
            <a href="{{ route('attendance.template') }}" class="btn-secondary">
                Download Excel template (.xlsx)
            </a>
            <a href="{{ route('attendance.template', ['format' => 'csv']) }}" class="btn-secondary">
                Download CSV template (.csv)
            </a>
        </p>
        <p class="queue-meta" style="margin-top: -4px;">
            Prefer the Excel template. It keeps <code>period_end</code> as a real
            date, whereas a CSV re-saved through Excel often has its dates
            rewritten into the local format.
        </p>

        <h3 style="margin-top: 24px;">2. What each column means</h3>
        <div style="overflow-x: auto;">
            <table class="spec-table">
                <thead>
                    <tr><th>Column</th><th>Meaning</th><th>Example</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>matric_no</code></td>
                        <td>The student's matric number, exactly as it is recorded in the portal. A row whose matric number matches nobody is skipped and reported.</td>
                        <td>22001001</td>
                    </tr>
                    <tr>
                        <td><code>period_end</code></td>
                        <td>The last day of the period the row covers. <b>YYYY-MM-DD</b>.</td>
                        <td>{{ now()->startOfMonth()->subMonth()->endOfMonth()->format('Y-m-d') }}</td>
                    </tr>
                    <tr>
                        <td><code>sessions_attended</code></td>
                        <td>Sessions the student attended in that period.</td>
                        <td>18</td>
                    </tr>
                    <tr>
                        <td><code>sessions_total</code></td>
                        <td>Sessions held in that period. Must be more than zero, and not less than <code>sessions_attended</code>.</td>
                        <td>20</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="queue-meta" style="margin-top: -4px;">
            The percentage is worked out from the two session counts on every
            save — there is no column for it, and a figure typed into the sheet
            would be ignored. Columns after the fourth are ignored too, so a
            UTrace export with extra trailing columns still works.
        </p>

        <h3 style="margin-top: 24px;">3. Upload the filled-in file</h3>

        <form method="POST" action="{{ route('attendance.upload') }}" enctype="multipart/form-data">
            @csrf

            <label for="csv_file">Attendance file (.xlsx, .xls or .csv)</label>
            <input type="file" name="csv_file" id="csv_file" accept=".csv,.txt,.xlsx,.xls" required
                   class="@error('csv_file') is-invalid @enderror">
            @error('csv_file') <p class="field-error">{{ $message }}</p> @enderror

            <p class="queue-meta" style="margin-top: -8px;">
                Good rows are saved even when others are skipped, and anything
                skipped is listed back with the row number and the reason — so a
                partly-wrong file is worth uploading and correcting, not
                starting over.
            </p>

            <button type="submit">Upload &amp; Process</button>
        </form>
    </div>
</div>
@endsection
