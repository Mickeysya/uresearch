@extends('core::layouts.app')

@section('title', 'Log Re-viva Submission')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Log Re-viva Submission"
        subtitle="Log the re-corrected thesis once it reaches CGS. The moment you submit is recorded as the formal resubmission timestamp. The 6-month correction and 1-year hardbound deadlines are both counted from it." />

    <div class="card card-wide">
        <form method="POST" action="{{ route('reviva.store') }}" enctype="multipart/form-data">
            @csrf

            <label for="student_id">Student</label>
            <select name="student_id" id="student_id" required
                    class="@error('student_id') is-invalid @enderror">
                <option value="">Select</option>
                @foreach ($students as $student)
                    <option value="{{ $student->id }}"
                            @selected(old('student_id') == $student->id)
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

            <button type="submit">Log Submission</button>
        </form>
    </div>
</div>
@endsection
