@extends('core::layouts.app')

@section('title', 'Prepare Appointment Letter — Application #' . $application->id)

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Prepare Appointment Letter — Application #{{ $application->id }}</h2>
        <div class="card-divider"></div>

        <p class="queue-meta">
            Endorsed by the Academic Executive. The fields below are filled in
            automatically from {{ $student?->name ?? 'the candidate' }}'s record —
            check them, add the thesis title, and the letter goes to the Dean for approval.
        </p>

        <table class="recent-activity-table" style="margin-bottom: 20px;">
            <tbody>
                <tr><th style="width: 180px;">Examiner</th><td>{{ $detail->examiner_name }}</td></tr>
                <tr><th>Institution</th><td>{{ $detail->examiner_institution }}</td></tr>
                <tr><th>Examiner email</th><td>{{ $detail->examiner_email }}</td></tr>
                <tr><th>Expertise</th><td>{{ $detail->examiner_expertise }}</td></tr>
                <tr><th>Candidate</th><td>{{ $student?->name ?? '—' }}@if ($student?->matric_no) ({{ $student->matric_no }})@endif</td></tr>
                <tr><th>Letter date</th><td>{{ now()->format('j F Y') }} <span style="color: var(--text-grey);">— set when you submit this form</span></td></tr>
            </tbody>
        </table>

        <form method="POST" action="{{ route('appointment-letter.prepare.store', $application) }}">
            @csrf

            <label for="examiner_type">Appointment Type</label>
            <select name="examiner_type" id="examiner_type" required
                    class="@error('examiner_type') is-invalid @enderror">
                <option value="external" @selected(old('examiner_type', $defaults['examiner_type']) === 'external')>
                    External Examiner — letter includes honorarium and travel entitlements
                </option>
                <option value="internal" @selected(old('examiner_type', $defaults['examiner_type']) === 'internal')>
                    Internal Examiner — no entitlements attachment
                </option>
            </select>
            <p class="queue-meta" style="margin-top: -8px;">
                Pre-selected from the examiner's institution.
            </p>
            @error('examiner_type') <p class="field-error">{{ $message }}</p> @enderror

            <label for="letter_ref_no">Our Ref</label>
            <input type="text" name="letter_ref_no" id="letter_ref_no" required
                   value="{{ old('letter_ref_no', $defaults['letter_ref_no']) }}"
                   class="@error('letter_ref_no') is-invalid @enderror">
            <p class="queue-meta" style="margin-top: -8px;">
                Built from the candidate's matric number.
            </p>
            @error('letter_ref_no') <p class="field-error">{{ $message }}</p> @enderror

            <label for="candidate_degree">Degree</label>
            <input type="text" name="candidate_degree" id="candidate_degree" required
                   value="{{ old('candidate_degree', $defaults['candidate_degree']) }}"
                   placeholder="e.g. PhD in Civil Engineering"
                   class="@error('candidate_degree') is-invalid @enderror">
            <p class="queue-meta" style="margin-top: -8px;">
                Derived from the candidate's programme and department.
            </p>
            @error('candidate_degree') <p class="field-error">{{ $message }}</p> @enderror

            <label for="candidate_programme">Programme</label>
            <input type="text" name="candidate_programme" id="candidate_programme" required
                   value="{{ old('candidate_programme', $defaults['candidate_programme']) }}"
                   class="@error('candidate_programme') is-invalid @enderror">
            <p class="queue-meta" style="margin-top: -8px;">
                From the candidate's department.
            </p>
            @error('candidate_programme') <p class="field-error">{{ $message }}</p> @enderror

            <label for="supervisor_name">Supervisor Name</label>
            <input type="text" name="supervisor_name" id="supervisor_name" required
                   value="{{ old('supervisor_name', $defaults['supervisor_name']) }}"
                   class="@error('supervisor_name') is-invalid @enderror">
            <p class="queue-meta" style="margin-top: -8px;">
                From the supervisor assigned to this candidate.
            </p>
            @error('supervisor_name') <p class="field-error">{{ $message }}</p> @enderror

            <label for="thesis_title">Title of the Thesis</label>
            <textarea name="thesis_title" id="thesis_title" rows="3" required
                      class="@error('thesis_title') is-invalid @enderror">{{ old('thesis_title', $defaults['thesis_title']) }}</textarea>
            <p class="queue-meta" style="margin-top: -8px;">
                The one field with no record to draw on — no module captures thesis titles yet.
            </p>
            @error('thesis_title') <p class="field-error">{{ $message }}</p> @enderror

            <label for="remarks">Remarks for the Dean <span style="color: var(--text-grey);">(optional)</span></label>
            <textarea name="remarks" id="remarks" rows="2"
                      class="@error('remarks') is-invalid @enderror">{{ old('remarks') }}</textarea>
            @error('remarks') <p class="field-error">{{ $message }}</p> @enderror

            <button type="submit">Prepare Letter &amp; Send to Dean</button>
        </form>
    </div>
</div>
@endsection
