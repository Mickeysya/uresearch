@extends('core::layouts.app')

@section('title', 'Update Application')

@push('head')
    @include('haziq::partials.styles')
@endpush

@php
    $isFlagged = fn ($section, $field = null) => ($field && $flaggedFields->has("{$section}.{$field}")) || $flaggedSections->contains($section);
    $fieldReason = fn ($section, $field) => $flaggedFields->get("{$section}.{$field}")?->remarks;
    $sectionReason = fn ($section) => $open->first(fn ($r) => $r->target_type === $section && $r->target_field === null)?->remarks;
@endphp

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Update Application #{{ $application->id }}</h2>
        <div class="card-divider"></div>

        @if ($open->isNotEmpty())
            <p class="queue-meta">
                Your application has not been rejected. The item(s) highlighted in red below were
                flagged by an approver — everything else is yours to edit too, any time.
            </p>
        @else
            <p class="queue-meta">Nothing has been flagged — you can still update any section below and resubmit.</p>
        @endif

        <form method="POST" action="{{ route('ga-application.correction.store', $application) }}" enctype="multipart/form-data">
            @csrf

            <h3>Section B — GA Details</h3>
            <div class="{{ $flaggedSections->contains('section_b') ? 'haziq-flagged-section' : '' }}">
                @if ($r = $sectionReason('section_b'))
                    <p class="haziq-flag-reason">⚠ {{ $r }}</p>
                @endif

                <label for="department_unit">Department / Unit</label>
                <input type="text" name="department_unit" id="department_unit"
                       value="{{ old('department_unit', $detail->department_unit) }}"
                       class="{{ $isFlagged('section_b', 'department_unit') ? 'haziq-flagged' : '' }}">
                @if ($r = $fieldReason('section_b', 'department_unit'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif

                <label for="purpose">Purpose</label>
                <input type="text" name="purpose" id="purpose"
                       value="{{ old('purpose', $detail->purpose) }}"
                       class="{{ $isFlagged('section_b', 'purpose') ? 'haziq-flagged' : '' }}">
                @if ($r = $fieldReason('section_b', 'purpose'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif

                <label for="proposed_hours_per_week">Proposed Hours per Week</label>
                <input type="number" min="1" max="21" name="proposed_hours_per_week" id="proposed_hours_per_week"
                       value="{{ old('proposed_hours_per_week', $detail->proposed_hours_per_week) }}"
                       class="{{ $isFlagged('section_b', 'proposed_hours_per_week') ? 'haziq-flagged' : '' }}">
                @if ($r = $fieldReason('section_b', 'proposed_hours_per_week'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif

                <label for="service_start_date">Service Start Date</label>
                <input type="date" name="service_start_date" id="service_start_date" required
                       value="{{ old('service_start_date', $detail->service_start_date->format('Y-m-d')) }}"
                       class="{{ $isFlagged('section_b', 'service_start_date') ? 'haziq-flagged' : '' }}">
                @if ($r = $fieldReason('section_b', 'service_start_date'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif

                <label for="service_end_date">Service End Date</label>
                <input type="date" name="service_end_date" id="service_end_date" required
                       value="{{ old('service_end_date', $detail->service_end_date->format('Y-m-d')) }}">

                <label for="proposed_allowance">Proposed Allowance (RM)</label>
                <input type="number" step="0.01" min="0" name="proposed_allowance" id="proposed_allowance" required
                       value="{{ old('proposed_allowance', $detail->proposed_allowance) }}">
            </div>

            <h3>Section C — Candidate Details</h3>
            <div class="{{ $flaggedSections->contains('section_c') ? 'haziq-flagged-section' : '' }}">
                @if ($r = $sectionReason('section_c'))
                    <p class="haziq-flag-reason">⚠ {{ $r }}</p>
                @endif

                <label for="level_of_study">Level of Study</label>
                <select name="level_of_study" id="level_of_study" required
                        class="{{ $isFlagged('section_c', 'level_of_study') ? 'haziq-flagged' : '' }}">
                    <option value="master" @selected(old('level_of_study', $profile->level_of_study) === 'master')>MSc</option>
                    <option value="phd" @selected(old('level_of_study', $profile->level_of_study) === 'phd')>PhD</option>
                </select>
                @if ($r = $fieldReason('section_c', 'level_of_study'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif

                <label for="programme">Programme</label>
                <input type="text" name="programme" id="programme" required
                       value="{{ old('programme', $profile->programme) }}"
                       class="{{ $isFlagged('section_c', 'programme') ? 'haziq-flagged' : '' }}">
                @if ($r = $fieldReason('section_c', 'programme'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif

                <label for="admission_date">Admission Date</label>
                <input type="date" name="admission_date" id="admission_date" required
                       value="{{ old('admission_date', $profile->admission_date->format('Y-m-d')) }}"
                       class="{{ $isFlagged('section_c', 'admission_date') ? 'haziq-flagged' : '' }}">
                @if ($r = $fieldReason('section_c', 'admission_date'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif
            </div>

            <h3>Section D — Academic Qualification</h3>
            <div class="{{ $flaggedSections->contains('section_d') ? 'haziq-flagged-section' : '' }}">
                @if ($r = $sectionReason('section_d'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif
                @php($rows = $academics->isNotEmpty() ? $academics : collect([null, null, null]))
                @foreach ($rows as $i => $row)
                    <div class="haziq-repeatable-row">
                        <div><label>Level</label><input type="text" name="academic[{{ $i }}][level_of_study]" value="{{ $row->level_of_study ?? '' }}"></div>
                        <div><label>Institution</label><input type="text" name="academic[{{ $i }}][institution]" value="{{ $row->institution ?? '' }}"></div>
                        <div><label>Programme</label><input type="text" name="academic[{{ $i }}][programme]" value="{{ $row->programme ?? '' }}"></div>
                        <div><label>Result</label><input type="text" name="academic[{{ $i }}][result]" value="{{ $row->result ?? '' }}"></div>
                        <div><label>Year From</label><input type="text" name="academic[{{ $i }}][year_from]" maxlength="4" value="{{ $row->year_from ?? '' }}"></div>
                        <div><label>Year To</label><input type="text" name="academic[{{ $i }}][year_to]" maxlength="4" value="{{ $row->year_to ?? '' }}"></div>
                    </div>
                @endforeach
            </div>

            <h3>Section E — Working Experience</h3>
            <div class="{{ $flaggedSections->contains('section_e') ? 'haziq-flagged-section' : '' }}">
                @if ($r = $sectionReason('section_e'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif
                @php($rows = $experiences->isNotEmpty() ? $experiences : collect([null, null]))
                @foreach ($rows as $i => $row)
                    <div class="haziq-repeatable-row">
                        <div><label>Company</label><input type="text" name="experience[{{ $i }}][company_name]" value="{{ $row->company_name ?? '' }}"></div>
                        <div><label>Designation</label><input type="text" name="experience[{{ $i }}][designation]" value="{{ $row->designation ?? '' }}"></div>
                        <div><label>From</label><input type="date" name="experience[{{ $i }}][date_from]" value="{{ $row?->date_from?->format('Y-m-d') }}"></div>
                        <div><label>To</label><input type="date" name="experience[{{ $i }}][date_to]" value="{{ $row?->date_to?->format('Y-m-d') }}"></div>
                        <div><label>Reason for Leaving</label><input type="text" name="experience[{{ $i }}][reason_for_leaving]" value="{{ $row->reason_for_leaving ?? '' }}"></div>
                    </div>
                @endforeach
            </div>

            <h3>Section F — Publications</h3>
            <div class="{{ $flaggedSections->contains('section_f') ? 'haziq-flagged-section' : '' }}">
                @if ($r = $sectionReason('section_f'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif
                @php($rows = $publications->isNotEmpty() ? $publications : collect([null, null]))
                @foreach ($rows as $i => $row)
                    <div class="haziq-repeatable-row">
                        <div><label>Type</label><input type="text" name="publication[{{ $i }}][publication_type]" value="{{ $row->publication_type ?? '' }}"></div>
                        <div><label>Title</label><input type="text" name="publication[{{ $i }}][title]" value="{{ $row->title ?? '' }}"></div>
                        <div><label>Authors</label><input type="text" name="publication[{{ $i }}][authors]" value="{{ $row->authors ?? '' }}"></div>
                        <div><label>Journal/Conference</label><input type="text" name="publication[{{ $i }}][journal_or_conference]" value="{{ $row->journal_or_conference ?? '' }}"></div>
                    </div>
                @endforeach
            </div>

            <h3>Documents</h3>
            <p class="queue-meta">Choose a file only to replace what's already on record — otherwise it stays as is.</p>
            @foreach ($documentTypes as $field => $label)
                @php($flaggedDoc = $flaggedDocuments->contains($label))
                @php($existing = ($existingDocuments ?? collect())->get($label))
                <label for="{{ $field }}">{{ $label }} @if ($flaggedDoc)<span style="color: #D0342C;">— flagged, please replace</span>@endif</label>
                @if ($existing)
                    <p class="queue-meta" style="margin: 2px 0 4px;">
                        <span style="color: {{ $flaggedDoc ? '#D0342C' : '#07794A' }};">Already uploaded: {{ $existing->original_name }}</span>
                        — <a href="{{ $existing->url() }}" target="_blank" rel="noopener">view</a>
                    </p>
                @endif
                <input type="file" name="{{ $field }}" id="{{ $field }}" class="{{ $flaggedDoc ? 'haziq-flagged' : '' }}">
                @if ($flaggedDoc)
                    @php($r = $open->first(fn ($req) => $req->target_type === 'document' && $req->target_label === $label)?->remarks)
                    @if ($r)<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif
                @endif
            @endforeach

            <button type="submit" data-confirm="Save these changes to your application?">Submit</button>
        </form>
    </div>
</div>

@include('haziq::partials.confirm_scripts')
@endsection
