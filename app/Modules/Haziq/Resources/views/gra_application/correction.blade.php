@extends('core::layouts.app')

@section('title', 'Update Application')

@push('head')
    @include('haziq::partials.styles')
@endpush

@php
    // Small local helpers so each field below is one readable line instead
    // of repeating this lookup logic fifteen times.
    $isFlagged = fn ($section, $field = null) => ($field && $flaggedFields->has("{$section}.{$field}")) || $flaggedSections->contains($section);
    $fieldReason = fn ($section, $field) => $flaggedFields->get("{$section}.{$field}")?->remarks;
    $sectionReason = fn ($section) => $open->first(fn ($r) => $r->target_type === $section && $r->target_field === null)?->remarks;
@endphp

@section('content')
<div class="card-container-inline">
    <x-core::page-header title="Update Application #{{ $application->id }}" />

    <div class="card card-wide">
        @if ($open->isNotEmpty())
            <p class="queue-meta">
                Your application has not been rejected. The item(s) highlighted in red below were
                flagged by an approver; everything else is yours to edit too, any time.
            </p>
        @elseif ($application->status === \App\Modules\Core\Models\Application::STATUS_APPROVED)
            <p class="queue-meta">
                Nothing has been flagged; your GRA appointment is approved, and you can still
                update any section below any time it needs correcting.
            </p>
        @else
            <p class="queue-meta">
                Nothing has been flagged; you can still update any section below and resubmit.
            </p>
        @endif

        <form method="POST" action="{{ route('gra-application.correction.store', $application) }}" enctype="multipart/form-data">
            @csrf

            <h3>Section B: Project Details</h3>
            <div class="{{ $flaggedSections->contains('section_b') ? 'haziq-flagged-section' : '' }}">
                @if ($r = $sectionReason('section_b'))
                    <p class="haziq-flag-reason">⚠ {{ $r }}</p>
                @endif

                <label for="project_leader_name">Project Leader</label>
                <input type="text" name="project_leader_name" id="project_leader_name" required
                       value="{{ old('project_leader_name', $detail->project_leader_name) }}"
                       class="{{ $isFlagged('section_b', 'project_leader_name') ? 'haziq-flagged' : '' }}">
                @if ($r = $fieldReason('section_b', 'project_leader_name'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif

                <label for="project_leader_contact">Project Leader Contact No.</label>
                <input type="text" name="project_leader_contact" id="project_leader_contact"
                       value="{{ old('project_leader_contact', $detail->project_leader_contact) }}"
                       class="{{ $isFlagged('section_b', 'project_leader_contact') ? 'haziq-flagged' : '' }}">
                @if ($r = $fieldReason('section_b', 'project_leader_contact'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif

                <label for="project_title">Project Title</label>
                <input type="text" name="project_title" id="project_title" required
                       value="{{ old('project_title', $detail->project_title) }}"
                       class="{{ $isFlagged('section_b', 'project_title') ? 'haziq-flagged' : '' }}">
                @if ($r = $fieldReason('section_b', 'project_title'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif

                <label for="funding_source">Funding Source</label>
                <input type="text" name="funding_source" id="funding_source" required
                       value="{{ old('funding_source', $detail->funding_source) }}"
                       class="{{ $isFlagged('section_b', 'funding_source') ? 'haziq-flagged' : '' }}">
                @if ($r = $fieldReason('section_b', 'funding_source'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif

                <label for="grant_name">Name of Grant</label>
                <input type="text" name="grant_name" id="grant_name"
                       value="{{ old('grant_name', $detail->grant_name) }}"
                       class="{{ $isFlagged('section_b', 'grant_name') ? 'haziq-flagged' : '' }}">
                @if ($r = $fieldReason('section_b', 'grant_name'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif

                <label for="cost_centre">Cost Centre</label>
                <input type="text" name="cost_centre" id="cost_centre"
                       value="{{ old('cost_centre', $detail->cost_centre) }}"
                       class="{{ $isFlagged('section_b', 'cost_centre') ? 'haziq-flagged' : '' }}">
                @if ($r = $fieldReason('section_b', 'cost_centre'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif

                <label for="centre_name">Name of Centre</label>
                <input type="text" name="centre_name" id="centre_name"
                       value="{{ old('centre_name', $detail->centre_name) }}"
                       class="{{ $isFlagged('section_b', 'centre_name') ? 'haziq-flagged' : '' }}">
                @if ($r = $fieldReason('section_b', 'centre_name'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif

                @if ($detail->isExtension())
                    <label for="funding_continuation">Is this extension on the same grant, or a new one?</label>
                    <select name="funding_continuation" id="funding_continuation" required
                            class="{{ $isFlagged('section_b', 'funding_continuation') ? 'haziq-flagged' : '' }}">
                        <option value="existing" @selected(old('funding_continuation', $detail->funding_continuation) === 'existing')>Existing Grant</option>
                        <option value="new" @selected(old('funding_continuation', $detail->funding_continuation) === 'new')>New Grant</option>
                    </select>
                    @error('funding_continuation') <p class="field-error">{{ $message }}</p> @enderror

                    <p class="queue-meta">
                        Service: {{ $detail->service_start_date->format('j M Y') }} to
                        {{ $detail->service_end_date->format('j M Y') }}; the start date follows the
                        original appointment automatically and isn't editable here.
                    </p>
                @else
                    <label for="service_start_date">Service Start Date</label>
                    <input type="date" name="service_start_date" id="service_start_date" required
                           value="{{ old('service_start_date', $detail->service_start_date->format('Y-m-d')) }}"
                           class="{{ $isFlagged('section_b', 'service_start_date') ? 'haziq-flagged' : '' }}">
                    @if ($r = $fieldReason('section_b', 'service_start_date'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif
                    <p class="queue-meta" style="margin-top: -8px;">
                        Service End Date ({{ $detail->service_end_date->format('j M Y') }}) and your Stage Gate
                        schedule both recalculate automatically from this date; nothing to enter for either.
                    </p>
                @endif
                <label for="proposed_allowance">Proposed Allowance (RM)</label>
                <input type="number" step="0.01" min="0" name="proposed_allowance" id="proposed_allowance" required
                       value="{{ old('proposed_allowance', $detail->proposed_allowance) }}"
                       class="{{ $isFlagged('section_b', 'proposed_allowance') ? 'haziq-flagged' : '' }}">
                @if ($r = $fieldReason('section_b', 'proposed_allowance'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif
            </div>

            <h3>Section C: GRA Details</h3>
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

                <label for="mode_of_study">Mode of Study</label>
                <input type="text" name="mode_of_study" id="mode_of_study"
                       value="{{ old('mode_of_study', $profile->mode_of_study) }}"
                       class="{{ $isFlagged('section_c', 'mode_of_study') ? 'haziq-flagged' : '' }}">
                @if ($r = $fieldReason('section_c', 'mode_of_study'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif

                <label for="admission_date">Admission Date</label>
                <input type="date" name="admission_date" id="admission_date" required
                       value="{{ old('admission_date', $profile->admission_date->format('Y-m-d')) }}"
                       class="{{ $isFlagged('section_c', 'admission_date') ? 'haziq-flagged' : '' }}">
                @if ($r = $fieldReason('section_c', 'admission_date'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif

                <label for="candidature_period">Period Candidature</label>
                <input type="text" name="candidature_period" id="candidature_period"
                       value="{{ old('candidature_period', $profile->candidature_period) }}"
                       class="{{ $isFlagged('section_c', 'candidature_period') ? 'haziq-flagged' : '' }}">
                @if ($r = $fieldReason('section_c', 'candidature_period'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif

                <label for="thesis_title">Thesis Title</label>
                <input type="text" name="thesis_title" id="thesis_title"
                       value="{{ old('thesis_title', $profile->thesis_title) }}"
                       class="{{ $isFlagged('section_c', 'thesis_title') ? 'haziq-flagged' : '' }}">
                @if ($r = $fieldReason('section_c', 'thesis_title'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif

                <label for="co_supervisor_id">Co-Supervisor <span style="color: var(--text-grey);">(if any)</span></label>
                <select name="co_supervisor_id" id="co_supervisor_id"
                        class="{{ $isFlagged('section_c', 'co_supervisor_id') ? 'haziq-flagged' : '' }}">
                    <option value="">None</option>
                    @foreach ($supervisors ?? [] as $supervisor)
                        <option value="{{ $supervisor->id }}" @selected((string) old('co_supervisor_id', $profile->co_supervisor_id) === (string) $supervisor->id)>{{ $supervisor->name }}</option>
                    @endforeach
                </select>
                @if ($r = $fieldReason('section_c', 'co_supervisor_id'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif

                <label for="ic_passport_no">I/C or Passport No.</label>
                <input type="text" name="ic_passport_no" id="ic_passport_no"
                       value="{{ old('ic_passport_no', $profile->ic_passport_no) }}"
                       class="{{ $isFlagged('section_c', 'ic_passport_no') ? 'haziq-flagged' : '' }}">
                @if ($r = $fieldReason('section_c', 'ic_passport_no'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif

                <label for="bank_name">Name of Bank</label>
                <input type="text" name="bank_name" id="bank_name"
                       value="{{ old('bank_name', $profile->bank_name) }}"
                       class="{{ $isFlagged('section_c', 'bank_name') ? 'haziq-flagged' : '' }}">
                @if ($r = $fieldReason('section_c', 'bank_name'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif

                <label for="bank_account_no">Account No.</label>
                <input type="text" name="bank_account_no" id="bank_account_no"
                       value="{{ old('bank_account_no', $profile->bank_account_no) }}"
                       class="{{ $isFlagged('section_c', 'bank_account_no') ? 'haziq-flagged' : '' }}">
                @if ($r = $fieldReason('section_c', 'bank_account_no'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif

                <div class="haziq-repeatable-row">
                    <div>
                        <label for="previous_sponsorship_name">Previous Sponsorship <span style="color: var(--text-grey);">(if any)</span></label>
                        <input type="text" name="previous_sponsorship_name" id="previous_sponsorship_name" value="{{ old('previous_sponsorship_name', $profile->previous_sponsorship_name) }}">
                    </div>
                    <div>
                        <label for="previous_sponsorship_duration">Duration</label>
                        <input type="text" name="previous_sponsorship_duration" id="previous_sponsorship_duration" value="{{ old('previous_sponsorship_duration', $profile->previous_sponsorship_duration) }}">
                    </div>
                    <div>
                        <label for="scholarship_name">Scholarship <span style="color: var(--text-grey);">(if any)</span></label>
                        <input type="text" name="scholarship_name" id="scholarship_name" value="{{ old('scholarship_name', $profile->scholarship_name) }}">
                    </div>
                    <div>
                        <label for="scholarship_duration">Duration</label>
                        <input type="text" name="scholarship_duration" id="scholarship_duration" value="{{ old('scholarship_duration', $profile->scholarship_duration) }}">
                    </div>
                </div>
            </div>

            {{-- D/E/F are repeatable tables — no fixed field list to highlight
                 one input within, so a flag on these highlights the whole block. --}}
            <h3>Section D: Academic Qualification</h3>
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

            <h3>Section E: Working Experience</h3>
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

            <h3>Section F: Publications</h3>
            <div class="{{ $flaggedSections->contains('section_f') ? 'haziq-flagged-section' : '' }}">
                @if ($r = $sectionReason('section_f'))<p class="haziq-flag-reason">⚠ {{ $r }}</p>@endif
                @php($rows = $publications->isNotEmpty() ? $publications : collect([null, null]))
                @foreach ($rows as $i => $row)
                    <div class="haziq-repeatable-row">
                        <div><label>Type</label><input type="text" name="publication[{{ $i }}][publication_type]" value="{{ $row->publication_type ?? '' }}"></div>
                        <div><label>Title</label><input type="text" name="publication[{{ $i }}][title]" value="{{ $row->title ?? '' }}"></div>
                        <div><label>Authors</label><input type="text" name="publication[{{ $i }}][authors]" value="{{ $row->authors ?? '' }}"></div>
                        <div><label>Year</label><input type="text" name="publication[{{ $i }}][publication_year]" maxlength="4" value="{{ $row->publication_year ?? '' }}"></div>
                        <div><label>Journal/Conference</label><input type="text" name="publication[{{ $i }}][journal_or_conference]" value="{{ $row->journal_or_conference ?? '' }}"></div>
                        <div><label>Volume/Chapter</label><input type="text" name="publication[{{ $i }}][volume_chapter]" value="{{ $row->volume_chapter ?? '' }}"></div>
                        <div><label>Page No.</label><input type="text" name="publication[{{ $i }}][page_number]" value="{{ $row->page_number ?? '' }}"></div>
                        <div><label>DOI</label><input type="text" name="publication[{{ $i }}][doi]" value="{{ $row->doi ?? '' }}"></div>
                        <div><label>ISSN</label><input type="text" name="publication[{{ $i }}][issn]" value="{{ $row->issn ?? '' }}"></div>
                        <div><label>Database &amp; Quartile</label><input type="text" name="publication[{{ $i }}][database_quartile]" value="{{ $row->database_quartile ?? '' }}"></div>
                        <div><label>Impact Factor</label><input type="text" name="publication[{{ $i }}][impact_factor]" value="{{ $row->impact_factor ?? '' }}"></div>
                    </div>
                @endforeach
            </div>

            <h3>Documents</h3>
            <p class="queue-meta">Choose a file only to replace what's already on record; otherwise it stays as is.</p>
            @foreach ($documentTypes as $field => $label)
                @php($flaggedDoc = $flaggedDocuments->contains($label))
                @php($existing = ($existingDocuments ?? collect())->get($label))
                <label for="{{ $field }}">{{ $label }} @if ($flaggedDoc)<span style="color: var(--danger-fg);">: flagged, please replace</span>@endif</label>
                @if ($existing)
                    <p class="queue-meta" style="margin: 2px 0 4px;">
                        <span style="color: {{ $flaggedDoc ? 'var(--danger-fg)' : 'var(--success-fg)' }};">Already uploaded: {{ $existing->original_name }}</span>
                        , <a href="{{ $existing->url() }}" target="_blank" rel="noopener">view</a>
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
