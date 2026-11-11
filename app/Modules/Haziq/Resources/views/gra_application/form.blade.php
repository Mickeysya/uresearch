@extends('core::layouts.app')

@section('title', $extension ? 'GRA Extension' : 'GRA Application')

@push('head')
    @include('haziq::partials.styles')
@endpush

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>{{ $extension ? 'GRA Extension' : 'GRA Application' }}</h2>
        <div class="card-divider"></div>

        @if ($extension)
            {{-- Extension: academic profile is loaded read-only; Section B is
                 re-confirmed since funding can change between appointments. --}}
            <p class="queue-meta">
                Programme and admission details below are from your record — no need to re-enter.
                Project/funding details are pre-filled from your current appointment but can be changed,
                e.g. if this extension moves to a different grant.
            </p>
            <div class="haziq-repeatable-row">
                <p><b>Programme</b><br>{{ $profile->programme }} ({{ ucfirst($profile->level_of_study) }})</p>
                <p><b>Admission Date</b><br>{{ $profile->admission_date->format('j M Y') }}</p>
                <p><b>Current Service Period</b><br>{{ $parentDetail->service_start_date->format('j M Y') }} – {{ $parentDetail->service_end_date->format('j M Y') }}</p>
                <p><b>Previous Allowance</b><br>RM {{ $parentDetail->proposed_allowance !== null ? number_format($parentDetail->proposed_allowance, 2) : '—' }}</p>
            </div>

            @if ($draft ?? false)
                <div class="haziq-repeatable-row" style="border-color: var(--warning-fg);">
                    <p style="grid-column: 1 / -1;">
                        <b>Continuing your saved draft</b> (#{{ $draft->id }}) — everything below is what
                        you last saved. Keep editing, Save Draft again any time, or Submit Extension
                        when it's ready.
                    </p>
                </div>
            @endif

            <form method="POST" action="{{ route('gra-application.extension.store') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="parent_application_id" value="{{ $parent->id }}">
                @if ($draft ?? false)
                    <input type="hidden" name="draft_id" value="{{ $draft->id }}">
                @endif

                <h3>Section B — Project Details</h3>
                <label for="project_leader_name">Project Leader</label>
                <input type="text" name="project_leader_name" id="project_leader_name" required
                       value="{{ old('project_leader_name', $draftDetail->project_leader_name ?? $parentDetail->project_leader_name) }}">
                @error('project_leader_name') <p class="field-error">{{ $message }}</p> @enderror

                <label for="project_leader_contact">Project Leader Contact No.</label>
                <input type="text" name="project_leader_contact" id="project_leader_contact"
                       value="{{ old('project_leader_contact', $draftDetail->project_leader_contact ?? $parentDetail->project_leader_contact) }}">

                <label for="project_title">Project Title</label>
                <input type="text" name="project_title" id="project_title" required
                       value="{{ old('project_title', $draftDetail->project_title ?? $parentDetail->project_title) }}">
                @error('project_title') <p class="field-error">{{ $message }}</p> @enderror

                <label for="funding_source">Funding Source</label>
                <select name="funding_source" id="funding_source" required>
                    @foreach (['Internal Funding', 'MOHE Grant', 'MOSTI Grant', 'Government Agencies', 'Consultancy & Contract Research', 'Petroleum Research Fund (PRF)', 'Research Industry or Private Grant', 'International Grant', 'Others'] as $source)
                        <option value="{{ $source }}" @selected(old('funding_source', $draftDetail->funding_source ?? $parentDetail->funding_source) === $source)>{{ $source }}</option>
                    @endforeach
                </select>

                <label for="funding_continuation">Is this extension on the same grant, or a new one?</label>
                <select name="funding_continuation" id="funding_continuation" required>
                    <option value="existing" @selected(old('funding_continuation', $draftDetail->funding_continuation ?? null) === 'existing')>Existing Grant</option>
                    <option value="new" @selected(old('funding_continuation', $draftDetail->funding_continuation ?? null) === 'new')>New Grant</option>
                </select>
                @error('funding_continuation') <p class="field-error">{{ $message }}</p> @enderror

                <label for="grant_name">Name of Grant</label>
                <input type="text" name="grant_name" id="grant_name" value="{{ old('grant_name', $draftDetail->grant_name ?? $parentDetail->grant_name) }}">

                <label for="cost_centre">Cost Centre</label>
                <input type="text" name="cost_centre" id="cost_centre" value="{{ old('cost_centre', $draftDetail->cost_centre ?? $parentDetail->cost_centre) }}">

                <label for="centre_name">Name of Centre</label>
                <input type="text" name="centre_name" id="centre_name" value="{{ old('centre_name', $draftDetail->centre_name ?? $parentDetail->centre_name) }}">

                <h3>Extension Details</h3>
                <label for="requested_new_end_date">Requested New End Date</label>
                <input type="date" name="requested_new_end_date" id="requested_new_end_date"
                       value="{{ old('requested_new_end_date', $draftDetail?->service_end_date?->format('Y-m-d')) }}">
                @error('requested_new_end_date') <p class="field-error">{{ $message }}</p> @enderror
                <p class="queue-meta" style="margin-top: -8px;">
                    No allowance field here — CGS sets the allowance for the extension when they approve it.
                </p>

                <label for="extension_reason">Reason / Justification</label>
                <textarea name="extension_reason" id="extension_reason" rows="4">{{ old('extension_reason', $draftDetail->extension_reason ?? null) }}</textarea>
                @error('extension_reason') <p class="field-error">{{ $message }}</p> @enderror

                <label for="evidence_document">Stage Gate Evidence <span style="color: var(--text-grey)">(journal acceptance / publication proof, if applicable)</span></label>
                @if (($draftDocuments ?? collect())->where('doc_type', 'Stage Gate Evidence / Publication Proof')->isNotEmpty())
                    <p class="queue-meta" style="margin: 0 0 6px;">Already uploaded — choose a file only to replace it.</p>
                @endif
                <input type="file" name="evidence_document" id="evidence_document">
                @error('evidence_document') <p class="field-error">{{ $message }}</p> @enderror

                <div class="haziq-actions" style="margin-top: 14px;">
                    <button type="submit" name="action" value="save_draft" class="btn-secondary">Save Draft</button>
                    <button type="submit" name="action" value="submit" data-confirm="Submit this GRA extension request? You won't be able to edit it unless an approver sends it back for correction.">Submit Extension</button>
                </div>
            </form>
        @else
            @if ($draft ?? false)
                <div class="haziq-repeatable-row" style="border-color: var(--warning-fg);">
                    <p style="grid-column: 1 / -1;">
                        <b>Continuing your saved draft</b> (#{{ $draft->id }}) — everything below is what
                        you last saved. Keep editing, Save Draft again any time, or Submit Application
                        when it's ready.
                    </p>
                </div>
            @endif

            <form method="POST" action="{{ route('gra-application.store') }}" enctype="multipart/form-data">
                @csrf

                <h3>Section B — Project Details</h3>
                <label for="project_leader_name">Project Leader</label>
                <input type="text" name="project_leader_name" id="project_leader_name" required
                       value="{{ old('project_leader_name', $draftDetail?->project_leader_name) }}">
                @error('project_leader_name') <p class="field-error">{{ $message }}</p> @enderror

                <label for="project_leader_contact">Project Leader Contact No.</label>
                <input type="text" name="project_leader_contact" id="project_leader_contact"
                       value="{{ old('project_leader_contact', $draftDetail?->project_leader_contact) }}">

                <label for="project_title">Project Title</label>
                <input type="text" name="project_title" id="project_title" required
                       value="{{ old('project_title', $draftDetail?->project_title) }}">
                @error('project_title') <p class="field-error">{{ $message }}</p> @enderror

                <label for="funding_source">Funding Source</label>
                <select name="funding_source" id="funding_source" required>
                    @foreach (['Internal Funding', 'MOHE Grant', 'MOSTI Grant', 'Government Agencies', 'Consultancy & Contract Research', 'Petroleum Research Fund (PRF)', 'Research Industry or Private Grant', 'International Grant', 'Others'] as $source)
                        <option value="{{ $source }}" @selected(old('funding_source', $draftDetail?->funding_source) === $source)>{{ $source }}</option>
                    @endforeach
                </select>

                <label for="grant_name">Name of Grant</label>
                <input type="text" name="grant_name" id="grant_name" value="{{ old('grant_name', $draftDetail?->grant_name) }}">

                <label for="cost_centre">Cost Centre</label>
                <input type="text" name="cost_centre" id="cost_centre" value="{{ old('cost_centre', $draftDetail?->cost_centre) }}">

                <label for="centre_name">Name of Centre</label>
                <input type="text" name="centre_name" id="centre_name" value="{{ old('centre_name', $draftDetail?->centre_name) }}">

                <h3>Section C — GRA Details</h3>
                @if ($draft ?? false)
                    <p class="queue-meta">
                        Pre-filled from your saved draft — update anything before submitting.
                    </p>
                @else
                    <p class="queue-meta">
                        Every application starts blank, even if you've applied before — fill in your
                        current details.
                    </p>
                @endif

                <label for="level_of_study">Level of Study</label>
                <select name="level_of_study" id="level_of_study" required>
                    <option value="master" @selected(old('level_of_study', $profile?->level_of_study) === 'master')>MSc</option>
                    <option value="phd" @selected(old('level_of_study', $profile?->level_of_study) === 'phd')>PhD</option>
                </select>

                <label for="programme">Programme</label>
                <input type="text" name="programme" id="programme" required value="{{ old('programme', $profile?->programme) }}">

                <label for="mode_of_study">Mode of Study</label>
                <input type="text" name="mode_of_study" id="mode_of_study" value="{{ old('mode_of_study', $profile?->mode_of_study) }}">

                <label for="admission_date">Admission Date</label>
                <input type="date" name="admission_date" id="admission_date" required
                       value="{{ old('admission_date', $profile?->admission_date?->format('Y-m-d')) }}">
                <p class="queue-meta" style="margin-top: -8px;">Your academic admission date — a different date from Service Start Date below, which is what your Stage Gate schedule is actually built from.</p>

                <label for="thesis_title">Thesis Title</label>
                <input type="text" name="thesis_title" id="thesis_title" value="{{ old('thesis_title', $profile?->thesis_title) }}">

                <label for="co_supervisor_id">Co-Supervisor <span style="color: var(--text-grey);">(if any)</span></label>
                <select name="co_supervisor_id" id="co_supervisor_id">
                    <option value="">— None —</option>
                    @foreach ($supervisors ?? [] as $supervisor)
                        <option value="{{ $supervisor->id }}" @selected((string) old('co_supervisor_id', $profile?->co_supervisor_id) === (string) $supervisor->id)>{{ $supervisor->name }}</option>
                    @endforeach
                </select>

                <label for="ic_passport_no">I/C or Passport No.</label>
                <input type="text" name="ic_passport_no" id="ic_passport_no" value="{{ old('ic_passport_no', $profile?->ic_passport_no) }}">

                <label for="bank_name">Name of Bank</label>
                <input type="text" name="bank_name" id="bank_name" required value="{{ old('bank_name', $profile?->bank_name) }}">

                <label for="bank_account_no">Account No.</label>
                <input type="text" name="bank_account_no" id="bank_account_no" required value="{{ old('bank_account_no', $profile?->bank_account_no) }}">

                <div class="haziq-repeatable-row">
                    <div>
                        <label for="previous_sponsorship_name">Previous Sponsorship <span style="color: var(--text-grey);">(if any)</span></label>
                        <input type="text" name="previous_sponsorship_name" id="previous_sponsorship_name" placeholder="Name of sponsorship" value="{{ old('previous_sponsorship_name', $profile?->previous_sponsorship_name) }}">
                    </div>
                    <div>
                        <label for="previous_sponsorship_duration">Duration</label>
                        <input type="text" name="previous_sponsorship_duration" id="previous_sponsorship_duration" placeholder="e.g. Jan 2023 – Dec 2023" value="{{ old('previous_sponsorship_duration', $profile?->previous_sponsorship_duration) }}">
                    </div>
                    <div>
                        <label for="scholarship_name">Scholarship <span style="color: var(--text-grey);">(if any)</span></label>
                        <input type="text" name="scholarship_name" id="scholarship_name" placeholder="Name of scholarship" value="{{ old('scholarship_name', $profile?->scholarship_name) }}">
                    </div>
                    <div>
                        <label for="scholarship_duration">Duration</label>
                        <input type="text" name="scholarship_duration" id="scholarship_duration" placeholder="e.g. Jan 2023 – Dec 2023" value="{{ old('scholarship_duration', $profile?->scholarship_duration) }}">
                    </div>
                </div>

                <label for="expected_reporting_date">Expected Date of Reporting Duty</label>
                <input type="date" name="expected_reporting_date" id="expected_reporting_date"
                       value="{{ old('expected_reporting_date', $draftDetail?->expected_reporting_date?->format('Y-m-d')) }}">

                <label for="service_start_date">Service Start Date</label>
                <input type="date" name="service_start_date" id="service_start_date" required
                       value="{{ old('service_start_date', $draftDetail?->service_start_date?->format('Y-m-d')) }}">
                <p class="queue-meta" style="margin-top: -8px;">
                    Your Stage Gate schedule is built from this date. Service End Date and the proposed
                    allowance are not entered here — the end date is calculated automatically (24 months
                    for Master's, 42 for PhD), and CGS sets the allowance when they approve.
                </p>

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
                        <div><label>Year</label><input type="text" name="publication[{{ $i }}][publication_year]" maxlength="4" value="{{ $row->publication_year ?? '' }}"></div>
                        <div><label>Journal/Conference</label><input type="text" name="publication[{{ $i }}][journal_or_conference]" value="{{ $row->journal_or_conference ?? '' }}"></div>
                        <div><label>Volume/Chapter</label><input type="text" name="publication[{{ $i }}][volume_chapter]" value="{{ $row->volume_chapter ?? '' }}"></div>
                        <div><label>Page No.</label><input type="text" name="publication[{{ $i }}][page_number]" value="{{ $row->page_number ?? '' }}"></div>
                        <div><label>DOI</label><input type="text" name="publication[{{ $i }}][doi]" value="{{ $row->doi ?? '' }}"></div>
                        <div><label>ISSN</label><input type="text" name="publication[{{ $i }}][issn]" value="{{ $row->issn ?? '' }}"></div>
                        <div><label>Database &amp; Quartile</label><input type="text" name="publication[{{ $i }}][database_quartile]" placeholder="e.g. Scopus Q1" value="{{ $row->database_quartile ?? '' }}"></div>
                        <div><label>Impact Factor</label><input type="text" name="publication[{{ $i }}][impact_factor]" value="{{ $row->impact_factor ?? '' }}"></div>
                    </div>
                @endforeach

                <h3>Section A — Documents</h3>
                <p class="queue-meta">
                    Only required to Submit — Save Draft works with none, some, or all of these uploaded.
                </p>
                @php($draftDocLabels = ($draftDocuments ?? collect())->pluck('doc_type'))
                @foreach ([
                    'resume' => 'Resume/CV',
                    'academic_transcript' => 'Academic Transcript',
                    'visa_or_ic' => 'Visa / IC',
                    'admission_letter' => 'UTP Admission Offer Letter',
                    'bank_letter' => 'Bank Account Details Letter',
                    'study_plan' => 'Study Plan (Gantt Chart)',
                    'grant_utilization_approval' => 'Grant Utilization Approval from UResearch',
                    'supervisor_supporting_letter' => 'GRA Supporting Letter from Supervisor',
                ] as $field => $label)
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
                            data-confirm="Submit this GRA application? You won't be able to edit it unless an approver sends it back for correction.">Submit Application</button>
                </div>
            </form>
        @endif
    </div>
</div>

@include('haziq::partials.confirm_scripts')
@endsection
