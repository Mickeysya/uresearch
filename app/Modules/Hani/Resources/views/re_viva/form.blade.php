@extends('core::layouts.app')

@section('title', 'Log Re-viva Submission')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Log Re-viva Submission"
        subtitle="Log the corrected thesis once it reaches CGS. The moment you submit is recorded as the formal resubmission timestamp and is checked against the resubmission window." />

    @if ($readyToLog->isNotEmpty())
        <div class="card card-wide">
            <h3>Expected resubmissions ({{ $readyToLog->count() }})</h3>
            <p class="fstep-hint">Category 4 viva results, and level 4 re-viva outcomes, with no round logged yet. Earliest window close first.</p>
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>Student</th><th>Resubmission window</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($readyToLog as $row)
                            <tr>
                                <td>{{ $row['student']->name }} @if ($row['student']->matric_no)<br><span class="fstep-hint">{{ $row['student']->matric_no }}</span>@endif</td>
                                <td>
                                    {{ $row['window']->label() }}<br>
                                    <span class="fstep-hint">{{ $row['window']->sourceLabel() }}</span>
                                    @unless ($row['window']->contains(now()))
                                        <br><span class="status-badge {{ now()->lt($row['window']->opens) ? 'pending' : 'rejected' }}">{{ now()->lt($row['window']->opens) ? 'Not open yet' : 'Window closed' }}</span>
                                    @endunless
                                </td>
                                <td><a href="{{ route('reviva.create', ['student' => $row['student']->id]) }}" class="btn">Select</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="card card-wide">
        @if ($preselectedStudent)
            <div class="reviva-selected">
                <p><b>{{ $preselectedStudent->name }}</b></p>
                @if ($window)
                    <p>Resubmission window: {{ $window->label() }} <span class="fstep-hint">({{ $window->sourceLabel() }})</span></p>
                    @if ($window->viva)
                        <p class="fstep-hint">Linked viva: result {{ $window->viva->resultLabel() }}@if ($window->viva->viva_at), held {{ $window->viva->viva_at->format('j M Y') }}@endif.</p>
                    @endif
                    @if ($warning = $window->warningFor(now()))
                        <p class="message-warning">{{ $warning }} You can still log it.</p>
                    @endif
                @else
                    <p class="message-warning">No category 4 viva result is recorded in UResearch for this student. Enter the date of the viva result below.</p>
                @endif
            </div>
        @endif

        <form method="POST" action="{{ route('reviva.store') }}" enctype="multipart/form-data">
            @csrf

            <label for="student_id">Student</label>
            <select name="student_id" id="student_id" required
                    class="@error('student_id') is-invalid @enderror">
                <option value="">Select</option>
                @foreach ($students as $student)
                    <option value="{{ $student->id }}"
                            @selected(old('student_id', $preselected ?? null) == $student->id)
                            @disabled($blockedReasons[$student->id] !== null)>
                        {{ $student->name }} @if ($student->matric_no)({{ $student->matric_no }})@endif
                        @if ($blockedReasons[$student->id])
                            {{ $blockedReasons[$student->id] }}
                        @endif
                    </option>
                @endforeach
            </select>
            @error('student_id') <p class="field-error">{{ $message }}</p> @enderror

            <label for="thesis">Re-corrected Thesis</label>
            <input type="file" name="thesis" id="thesis" required
                   class="@error('thesis') is-invalid @enderror">
            @error('thesis') <p class="field-error">{{ $message }}</p> @enderror

            <label for="viva_result_date">Viva result date <span style="color: var(--text-grey)">(only if the result is not in UResearch)</span></label>
            <input type="date" name="viva_result_date" id="viva_result_date" value="{{ old('viva_result_date') }}" max="{{ today()->toDateString() }}"
                   class="@error('viva_result_date') is-invalid @enderror">
            <p class="fstep-hint">Leave empty when UResearch has the category 4 viva result: the window is taken from it.</p>
            @error('viva_result_date') <p class="field-error">{{ $message }}</p> @enderror

            <button type="submit">Log Submission</button>
        </form>
    </div>
</div>
@endsection
