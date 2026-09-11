@extends('core::layouts.app')

@section('title', 'Nominate Examiner')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Examiner Nomination — Appointment Letter</h2>
        <div class="card-divider"></div>

        @if ($candidates->isEmpty())
            <div class="empty-state">
                There are no candidates in your department yet.<br>
                CGS assigns students to a department before nominations can be filed.
            </div>
        @else
            <form method="POST" action="{{ route('appointment-letter.store') }}">
                @csrf

                <label for="student_id">Candidate</label>
                <select name="student_id" id="student_id" required
                        class="@error('student_id') is-invalid @enderror">
                    <option value="">— Select the candidate —</option>
                    @foreach ($candidates as $candidate)
                        <option value="{{ $candidate->id }}" @selected(old('student_id') == $candidate->id)>
                            {{ $candidate->name }} @if ($candidate->matric_no)({{ $candidate->matric_no }})@endif
                        </option>
                    @endforeach
                </select>
                @error('student_id') <p class="field-error">{{ $message }}</p> @enderror

                <label for="examiner_name">Examiner Name</label>
                <input type="text" name="examiner_name" id="examiner_name" required
                       value="{{ old('examiner_name') }}"
                       class="@error('examiner_name') is-invalid @enderror">
                @error('examiner_name') <p class="field-error">{{ $message }}</p> @enderror

                <label for="examiner_institution">Institution</label>
                <input type="text" name="examiner_institution" id="examiner_institution" required
                       value="{{ old('examiner_institution') }}"
                       class="@error('examiner_institution') is-invalid @enderror">
                @error('examiner_institution') <p class="field-error">{{ $message }}</p> @enderror

                <label for="examiner_email">Examiner Email</label>
                <input type="email" name="examiner_email" id="examiner_email" required
                       value="{{ old('examiner_email') }}"
                       class="@error('examiner_email') is-invalid @enderror">
                @error('examiner_email') <p class="field-error">{{ $message }}</p> @enderror
                <p class="queue-meta" style="margin-top: -8px;">
                    The Appointment Letter PDF is emailed to this address once the Dean approves —
                    double-check it before submitting.
                </p>

                <label for="examiner_expertise">Area of Expertise</label>
                <input type="text" name="examiner_expertise" id="examiner_expertise" required
                       value="{{ old('examiner_expertise') }}"
                       class="@error('examiner_expertise') is-invalid @enderror">
                @error('examiner_expertise') <p class="field-error">{{ $message }}</p> @enderror

                <button type="submit">Submit Nomination</button>
            </form>
        @endif
    </div>
</div>
@endsection
