@extends('core::layouts.app')

@section('title', 'Register a Candidacy')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Register a Candidacy</h2>
        <div class="card-divider"></div>

        @if ($students->isEmpty())
            <div class="empty-state">
                <p>Every student already has a candidacy on record.</p>
                <a href="{{ route('candidacies.index') }}" class="btn-secondary">Back to the masterlist</a>
            </div>
        @else
            <form method="POST" action="{{ route('candidacies.store') }}" class="app-form" data-stepper>
                @csrf

                <fieldset class="fstep" data-label="Student">
                <p class="fstep-hint">Only students without a candidacy already on record are listed. The clock is one per student.</p>

                <label for="student_id">Student</label>
                <select name="student_id" id="student_id" required
                        class="@error('student_id') is-invalid @enderror">
                    <option value="">Select a student</option>
                    @foreach ($students as $student)
                        <option value="{{ $student->id }}" @selected(old('student_id') == $student->id)>
                            {{ $student->name }}@if ($student->matric_no) ({{ $student->matric_no }})@endif
                        </option>
                    @endforeach
                </select>
                @error('student_id') <p class="field-error">{{ $message }}</p> @enderror
                </fieldset>

                <fieldset class="fstep" data-label="Candidature">
                <p class="fstep-hint">The deadline is worked out from these two: 8 months full-time, 12 part-time. It is stored, not recomputed, so an approved appeal can move it later.</p>

                <label for="programme_type">Programme</label>
                <select name="programme_type" id="programme_type" required
                        class="@error('programme_type') is-invalid @enderror">
                    @foreach ($programmeTypes as $value => $label)
                        <option value="{{ $value }}" @selected(old('programme_type') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('programme_type') <p class="field-error">{{ $message }}</p> @enderror

                <label for="candidature_start_date">Candidature start date</label>
                <input type="date" name="candidature_start_date" id="candidature_start_date" required
                       max="{{ now()->toDateString() }}"
                       value="{{ old('candidature_start_date') }}"
                       class="@error('candidature_start_date') is-invalid @enderror">
                @error('candidature_start_date') <p class="field-error">{{ $message }}</p> @enderror
                </fieldset>

                <button type="submit">Register Candidacy</button>
            </form>
        @endif
    </div>
</div>

@include('core::partials.form-stepper')
@endsection
