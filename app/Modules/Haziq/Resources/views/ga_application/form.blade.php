@extends('core::layouts.app')

@section('title', 'GA Application')

@push('head')
    @include('haziq::partials.styles')
@endpush

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>GA Application</h2>
        <div class="card-divider"></div>

        <p class="queue-meta">
            Admin verifies your documents, Research Centre conducts an interview, then CGS
            Staff, Supervisor and Senior Director CGS each decide in turn. A failed interview
            ends the application — it never reaches CGS.
        </p>

        @if ($draft ?? false)
            <div class="haziq-repeatable-row" style="border-color: var(--warning-fg);">
                <p style="grid-column: 1 / -1;">
                    <b>Continuing your saved draft</b> (#{{ $draft->id }}) — everything below is what
                    you last saved. Keep editing, Save Draft again any time, or Submit Application
                    when it's ready.
                </p>
            </div>
        @endif

        <form method="POST" action="{{ route('ga-application.store') }}" enctype="multipart/form-data">
            @csrf

            <h3>Section B — GA Details</h3>
            <label for="department_unit">Department / Unit</label>
            <input type="text" name="department_unit" id="department_unit" value="{{ old('department_unit', $draftDetail?->department_unit) }}">

            <label for="purpose">Purpose</label>
            <input type="text" name="purpose" id="purpose" value="{{ old('purpose', $draftDetail?->purpose) }}">

            <label for="proposed_hours_per_week">Proposed Hours per Week</label>
            <input type="number" min="1" max="21" name="proposed_hours_per_week" id="proposed_hours_per_week" value="{{ old('proposed_hours_per_week', $draftDetail?->proposed_hours_per_week) }}">

            <label for="service_start_date">Service Start Date</label>
            <input type="date" name="service_start_date" id="service_start_date" required
                   value="{{ old('service_start_date', $draftDetail?->service_start_date?->format('Y-m-d')) }}">

            <label for="service_end_date">Service End Date</label>
            <input type="date" name="service_end_date" id="service_end_date" required
                   value="{{ old('service_end_date', $draftDetail?->service_end_date?->format('Y-m-d')) }}">

            <label for="proposed_allowance">Proposed Allowance (RM)</label>
            <input type="number" step="0.01" min="0" name="proposed_allowance" id="proposed_allowance" required value="{{ old('proposed_allowance', $draftDetail?->proposed_allowance) }}">

            <h3>Section C — Candidate Details</h3>
            @if ($draft ?? false)
                <p class="queue-meta">Pre-filled from your saved draft — update anything before submitting.</p>
            @else
                <p class="queue-meta">Every application starts blank, even if you've applied before — fill in your current details.</p>
            @endif

            <label for="level_of_study">Level of Study</label>
            <select name="level_of_study" id="level_of_study" required>
                <option value="master" @selected(old('level_of_study', $profile?->level_of_study) === 'master')>MSc</option>
                <option value="phd" @selected(old('level_of_study', $profile?->level_of_study) === 'phd')>PhD</option>
            </select>

            <label for="programme">Programme</label>
            <input type="text" name="programme" id="programme" required value="{{ old('programme', $profile?->programme) }}">

            <label for="admission_date">Admission Date</label>
            <input type="date" name="admission_date" id="admission_date" required
                   value="{{ old('admission_date', $profile?->admission_date?->format('Y-m-d')) }}">

            <h3>Section D — Academic Qualification</h3>
            @php($academicRows = ($academics ?? collect())->isNotEmpty() ? $academics : collect([null, null, null]))
            @foreach ($academicRows as $i => $row)
                <div class="haziq-repeatable-row">
                    <div><label>Level</label><input type="text" name="academic[{{ $i }}][level_of_study]" value="{{ $row->level_of_study ?? '' }}"></div>
                    <div><label>Institution</label><input type="text" name="academic[{{ $i }}][institution]" value="{{ $row->institution ?? '' }}"></div>
                    <div><label>Programme</label><input type="text" name="academic[{{ $i }}][programme]" value="{{ $row->programme ?? '' }}"></div>
                    <div><label>Result</label><input type="text" name="academic[{{ $i }}][result]" value="{{ $row->result ?? '' }}"></div>
                    <div><label>Year From</label><input type="text" name="academic[{{ $i }}][year_from]" maxlength="4" value="{{ $row->year_from ?? '' }}"></div>
                    <div><label>Year To</label><input type="text" name="academic[{{ $i }}][year_to]" maxlength="4" value="{{ $row->year_to ?? '' }}"></div>
                </div>
            @endforeach

            <h3>Section E — Working Experience</h3>
            @php($experienceRows = ($experiences ?? collect())->isNotEmpty() ? $experiences : collect([null, null]))
            @foreach ($experienceRows as $i => $row)
                <div class="haziq-repeatable-row">
                    <div><label>Company</label><input type="text" name="experience[{{ $i }}][company_name]" value="{{ $row->company_name ?? '' }}"></div>
                    <div><label>Designation</label><input type="text" name="experience[{{ $i }}][designation]" value="{{ $row->designation ?? '' }}"></div>
                    <div><label>From</label><input type="date" name="experience[{{ $i }}][date_from]" value="{{ $row?->date_from?->format('Y-m-d') }}"></div>
                    <div><label>To</label><input type="date" name="experience[{{ $i }}][date_to]" value="{{ $row?->date_to?->format('Y-m-d') }}"></div>
                    <div><label>Reason for Leaving</label><input type="text" name="experience[{{ $i }}][reason_for_leaving]" value="{{ $row->reason_for_leaving ?? '' }}"></div>
                </div>
            @endforeach

            <h3>Section F — Publications <span style="color: var(--text-grey); font-weight: normal;">(optional)</span></h3>
            @php($publicationRows = ($publications ?? collect())->isNotEmpty() ? $publications : collect([null, null]))
            @foreach ($publicationRows as $i => $row)
                <div class="haziq-repeatable-row">
                    <div><label>Type</label><input type="text" name="publication[{{ $i }}][publication_type]" placeholder="Journal / Conference / Book" value="{{ $row->publication_type ?? '' }}"></div>
                    <div><label>Title</label><input type="text" name="publication[{{ $i }}][title]" value="{{ $row->title ?? '' }}"></div>
                    <div><label>Authors</label><input type="text" name="publication[{{ $i }}][authors]" value="{{ $row->authors ?? '' }}"></div>
                    <div><label>Journal/Conference</label><input type="text" name="publication[{{ $i }}][journal_or_conference]" value="{{ $row->journal_or_conference ?? '' }}"></div>
                </div>
            @endforeach

            <h3>Section A — Documents</h3>
            <p class="queue-meta">Only required to Submit — Save Draft works with none, some, or all of these uploaded.</p>
            @php($draftDocLabels = ($draftDocuments ?? collect())->pluck('doc_type'))
            @foreach (['resume' => 'Resume/CV', 'academic_transcript' => 'Academic Transcript', 'visa_or_ic' => 'Visa / IC'] as $field => $label)
                <label for="{{ $field }}">{{ $label }}
                    @if ($draftDocLabels->contains($label))
                        <span style="color: var(--success-fg);">— already uploaded, choose a file only to replace it</span>
                    @endif
                </label>
                <input type="file" name="{{ $field }}" id="{{ $field }}">
                @error($field) <p class="field-error">{{ $message }}</p> @enderror
            @endforeach

            <div class="decision-row">
                <button type="submit" name="action" value="save_draft" class="btn-secondary">Save Draft</button>
                <button type="submit" name="action" value="submit"
                        data-confirm="Submit this GA application? You won't be able to edit it unless an approver sends it back for correction.">Submit Application</button>
            </div>
        </form>
    </div>
</div>

@include('haziq::partials.confirm_scripts')
@endsection
