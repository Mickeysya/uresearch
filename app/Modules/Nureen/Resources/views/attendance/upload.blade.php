@extends('core::layouts.app')

@section('title', 'Upload Attendance CSV')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Upload Attendance CSV</h2>
        <div class="card-divider"></div>

        <p class="queue-meta">
            Export attendance from UTrace as a CSV with exactly these columns,
            in this order: <code>matric_no, period_end, sessions_attended, sessions_total</code>.
            <code>period_end</code> is the date the covered period ends
            (YYYY-MM-DD). Re-uploading a period a student already has on file
            replaces that row rather than duplicating it.
        </p>

        <form method="POST" action="{{ route('attendance.upload') }}" enctype="multipart/form-data">
            @csrf

            <label for="csv_file">Attendance CSV</label>
            <input type="file" name="csv_file" id="csv_file" accept=".csv,.txt,.xlsx,.xls" required
                   class="@error('csv_file') is-invalid @enderror">
            @error('csv_file') <p class="field-error">{{ $message }}</p> @enderror

            <button type="submit">Upload &amp; Process</button>
        </form>
    </div>
</div>
@endsection
