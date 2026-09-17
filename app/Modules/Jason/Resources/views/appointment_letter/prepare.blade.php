@extends('core::layouts.app')

@section('title', 'Prepare Appointment Pack: Application #' . $application->id)

@section('content')
@php($c = $defaults['candidate'])
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Prepare Appointment Pack: Application #{{ $application->id }}</h2>
        <div class="card-divider"></div>

        <p class="queue-meta">
            Endorsed by the Academic Executive. The candidate's details below are filled
            in automatically from {{ $student?->name ?? 'the candidate' }}'s record. Check
            them and add the thesis title. Submitting generates
            <b>{{ $examiners->count() * 2 }} documents</b>: an appointment letter and a
            thesis evaluation report for each of the {{ $examiners->count() }} examiners,
            and sends the pack to the Dean.
        </p>

        <form method="POST" action="{{ route('appointment-letter.prepare.store', $application) }}"
              class="app-form" data-stepper>
            @csrf

            <fieldset class="fstep" data-label="Candidate">
            <p class="fstep-hint">Pre-filled from the candidate's record. Correct
               anything the record has wrong, because this is what the letters print.</p>

            <table class="recent-activity-table" style="margin-bottom: 14px;">
                <tbody>
                    <tr><th style="width: 180px;">Candidate</th><td>{{ $student?->name ?? '—' }}@if ($student?->matric_no) ({{ $student->matric_no }})@endif</td></tr>
                    <tr><th>Letter date</th><td>{{ now()->format('j F Y') }} <span style="color: var(--text-grey);">(set when you submit this form)</span></td></tr>
                </tbody>
            </table>

            <label for="candidate_degree">Degree</label>
            <input type="text" name="candidate_degree" id="candidate_degree" required
                   value="{{ old('candidate_degree', $c['candidate_degree']) }}"
                   placeholder="e.g. PhD in Civil Engineering"
                   class="@error('candidate_degree') is-invalid @enderror">
            <p class="queue-meta" style="margin-top: -8px;">Derived from the candidate's programme and department.</p>
            @error('candidate_degree') <p class="field-error">{{ $message }}</p> @enderror

            <label for="candidate_programme">Programme</label>
            <input type="text" name="candidate_programme" id="candidate_programme" required
                   value="{{ old('candidate_programme', $c['candidate_programme']) }}"
                   class="@error('candidate_programme') is-invalid @enderror">
            <p class="queue-meta" style="margin-top: -8px;">From the candidate's department.</p>
            @error('candidate_programme') <p class="field-error">{{ $message }}</p> @enderror

            <label for="supervisor_name">Supervisor Name</label>
            <input type="text" name="supervisor_name" id="supervisor_name" required
                   value="{{ old('supervisor_name', $c['supervisor_name']) }}"
                   class="@error('supervisor_name') is-invalid @enderror">
            <p class="queue-meta" style="margin-top: -8px;">From the supervisor assigned to this candidate.</p>
            @error('supervisor_name') <p class="field-error">{{ $message }}</p> @enderror

            <label for="thesis_title">Title of the Thesis</label>
            <textarea name="thesis_title" id="thesis_title" rows="3" required
                      class="@error('thesis_title') is-invalid @enderror">{{ old('thesis_title', $c['thesis_title']) }}</textarea>
            <p class="queue-meta" style="margin-top: -8px;">
                The one field with no record to draw on: no module captures thesis titles yet.
            </p>
            @error('thesis_title') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>

            <fieldset class="fstep" data-label="Examiners">
            <p class="fstep-hint">One appointment letter and one evaluation report
               per examiner. The address and reference number print on the letter.</p>

            @foreach ($examiners as $i => $examiner)
                @php($d = $defaults['examiners'][$examiner->id])
                <fieldset class="app-item" style="margin: 14px 0; border: 1px solid var(--border, #ddd); padding: 14px;">
                    <legend style="font-weight: 600; padding: 0 6px;">
                        {{ $examiner->examiner_name }}
                    </legend>

                    <input type="hidden" name="examiners[{{ $i }}][id]" value="{{ $examiner->id }}">

                    <p style="margin: 0 0 10px;">
                        {{ $examiner->examiner_institution }} &middot; {{ $examiner->examiner_email }}<br>
                        <span style="color: var(--text-grey);">Expertise: {{ $examiner->examiner_expertise }}</span>
                    </p>

                    <label>Appointment Type</label>
                    <select name="examiners[{{ $i }}][examiner_type]" required>
                        <option value="internal" @selected(old("examiners.$i.examiner_type", $d['examiner_type']) === 'internal')>
                            Internal Examiner: no entitlements attachment
                        </option>
                        <option value="external" @selected(old("examiners.$i.examiner_type", $d['examiner_type']) === 'external')>
                            External Examiner: letter includes honorarium and travel entitlements
                        </option>
                    </select>
                    <p class="queue-meta" style="margin-top: -8px;">As nominated by the Chair. Change it here if the Chair got it wrong.</p>

                    <label>Examiner's Address</label>
                    <textarea name="examiners[{{ $i }}][examiner_address]" rows="4" required
                              class="@error("examiners.$i.examiner_address") is-invalid @enderror">{{ old("examiners.$i.examiner_address", $d['examiner_address']) }}</textarea>
                    <p class="queue-meta" style="margin-top: -8px;">
                        Printed under the examiner's name at the top of the letter, one line each:
                        department or faculty, institution, postcode and city, country.
                    </p>
                    @error("examiners.$i.examiner_address") <p class="field-error">{{ $message }}</p> @enderror

                    <label>Our Ref</label>
                    <input type="text" name="examiners[{{ $i }}][letter_ref_no]" required
                           value="{{ old("examiners.$i.letter_ref_no", $d['letter_ref_no']) }}"
                           class="@error("examiners.$i.letter_ref_no") is-invalid @enderror">
                    <p class="queue-meta" style="margin-top: -8px;">
                        Built from the candidate's matric number. PGS series for external, CGS for internal.
                    </p>
                    @error("examiners.$i.letter_ref_no") <p class="field-error">{{ $message }}</p> @enderror
                </fieldset>
            @endforeach

            </fieldset>

            <fieldset class="fstep" data-label="Send to the Dean">
            <p class="fstep-hint">Submitting generates the pack and moves the
               application to the Dean's queue.</p>

            <label for="remarks">Remarks for the Dean <span style="color: var(--text-grey);">(optional)</span></label>
            <textarea name="remarks" id="remarks" rows="2"
                      class="@error('remarks') is-invalid @enderror">{{ old('remarks') }}</textarea>
            @error('remarks') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>

            <button type="submit">Generate {{ $examiners->count() * 2 }} Documents &amp; Send to Dean</button>
        </form>
    </div>
</div>

@include('core::partials.form-stepper')
@endsection
