@extends('core::layouts.app')

@section('title', 'Study Candidacy Appeal')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Study Candidacy Appeal</h2>
        <div class="card-divider"></div>

        <p style="color: var(--text-grey); font-size: 13px;">
            Your candidacy expires <b>{{ $candidacy->candidacy_expiry_date->format('j M Y') }}</b>.
            You may request up to <b>{{ $candidacy->remainingAppealMonths() }}</b> more month(s) of extension
            (12-month cumulative maximum).
        </p>

        <form method="POST" action="{{ route('candidacy-appeal.store') }}" enctype="multipart/form-data" id="appeal-form">
            @csrf

            <label for="supervisor_id">Supervisor</label>
            <select name="supervisor_id" id="supervisor_id" required class="@error('supervisor_id') is-invalid @enderror">
                <option value="">— Select your supervisor —</option>
                @foreach ($supervisors as $supervisor)
                    <option value="{{ $supervisor->id }}" @selected(old('supervisor_id') == $supervisor->id)>{{ $supervisor->name }}</option>
                @endforeach
            </select>
            @error('supervisor_id') <p class="field-error">{{ $message }}</p> @enderror

            <h3 style="margin-top: 20px;">Section A — Academic Progress / Current Status</h3>

            <label>Phase</label>
            <fieldset style="border:none; padding:0; margin:0;">
                @foreach (\App\Modules\Chloe\Models\CandidacyAppealDetail::phases() as $value => $label)
                    <label style="display:block; font-weight:normal;">
                        <input type="radio" name="phase" value="{{ $value }}"
                               @checked(old('phase') === $value) required>
                        {{ $label }}
                    </label>
                @endforeach
            </fieldset>
            @error('phase') <p class="field-error">{{ $message }}</p> @enderror

            <label for="writing_completion_percent">Percentage of completion (%)</label>
            <input type="number" name="writing_completion_percent" id="writing_completion_percent"
                   min="0" max="100" required value="{{ old('writing_completion_percent') }}">
            @error('writing_completion_percent') <p class="field-error">{{ $message }}</p> @enderror

            <h4 style="margin-top:16px;">Research Completion Seminar (RCS)</h4>
            <label style="display:block; font-weight:normal;">
                <input type="radio" name="rcs_status" value="completed" class="appeal-rcs-input" @checked(old('rcs_status') === 'completed') required>
                RCS completed
            </label>
            <label style="display:block; font-weight:normal;">
                <input type="radio" name="rcs_status" value="pending" class="appeal-rcs-input" @checked(old('rcs_status', 'pending') === 'pending') required>
                Not yet done
            </label>
            @error('rcs_status') <p class="field-error">{{ $message }}</p> @enderror

            <div id="rcs-completed-fields" hidden>
                <label for="rcs_date">RCS Date</label>
                <input type="date" name="rcs_date" id="rcs_date" value="{{ old('rcs_date') }}">
                @error('rcs_date') <p class="field-error">{{ $message }}</p> @enderror

                <label for="rcs_category">RCS Category</label>
                <input type="text" name="rcs_category" id="rcs_category" value="{{ old('rcs_category') }}">
                @error('rcs_category') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div id="rcs-pending-fields" hidden>
                <label for="rcs_expected_date">Expected date for RCS <span style="color: var(--text-grey);">*if applicable</span></label>
                <input type="date" name="rcs_expected_date" id="rcs_expected_date" value="{{ old('rcs_expected_date') }}">
                @error('rcs_expected_date') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <h3 style="margin-top: 20px;">Section B — Candidacy Extension Record</h3>
            <p style="color: var(--text-grey); font-size: 13px;">Have you previously had a candidacy extension through either route?</p>

            <label>Through GSC</label>
            <label style="display:inline-block; font-weight:normal; margin-right:16px;">
                <input type="radio" name="extension_via_gsc" value="yes" @checked(old('extension_via_gsc') === 'yes') required> Yes
            </label>
            <label style="display:inline-block; font-weight:normal;">
                <input type="radio" name="extension_via_gsc" value="no" @checked(old('extension_via_gsc', 'no') === 'no') required> No
            </label>
            @error('extension_via_gsc') <p class="field-error">{{ $message }}</p> @enderror

            <label style="margin-top:10px;">Through Vice Chancellor</label>
            <label style="display:inline-block; font-weight:normal; margin-right:16px;">
                <input type="radio" name="extension_via_vc" value="yes" @checked(old('extension_via_vc') === 'yes') required> Yes
            </label>
            <label style="display:inline-block; font-weight:normal;">
                <input type="radio" name="extension_via_vc" value="no" @checked(old('extension_via_vc', 'no') === 'no') required> No
            </label>
            @error('extension_via_vc') <p class="field-error">{{ $message }}</p> @enderror

            <h3 style="margin-top: 20px;">Section C — List of Publications/Journal</h3>
            <div id="publications-rows">
                @foreach (old('publications', ['']) as $i => $publication)
                    <div class="publication-row" style="display:flex; gap:8px; margin-bottom:8px;">
                        <input type="text" name="publications[]" value="{{ $publication }}" placeholder="Description" style="flex:1;">
                        <button type="button" class="remove-publication-row" @if($i === 0) hidden @endif>Remove</button>
                    </div>
                @endforeach
            </div>
            <button type="button" id="add-publication-row">+ Add publication</button>
            @error('publications.*') <p class="field-error">{{ $message }}</p> @enderror

            <h3 style="margin-top: 20px;">Section D — Duration of Extension Requested</h3>
            <label for="requested_extension_months">Extension Requested (months)</label>
            <input type="number" name="requested_extension_months" id="requested_extension_months"
                   min="1" max="{{ $candidacy->remainingAppealMonths() }}" required
                   value="{{ old('requested_extension_months') }}"
                   class="@error('requested_extension_months') is-invalid @enderror">
            @error('requested_extension_months') <p class="field-error">{{ $message }}</p> @enderror

            <label for="reason" style="margin-top:16px;">Additional Comments <span style="color: var(--text-grey);">(optional)</span></label>
            <textarea name="reason" id="reason" rows="3">{{ old('reason') }}</textarea>
            @error('reason') <p class="field-error">{{ $message }}</p> @enderror

            <label for="appeal_form" style="margin-top:16px;">Official Appeal Form <span style="color: var(--text-grey)">(optional — the university's own form, if you have it completed and signed)</span></label>
            <input type="file" name="appeal_form" id="appeal_form" class="@error('appeal_form') is-invalid @enderror">
            @error('appeal_form') <p class="field-error">{{ $message }}</p> @enderror

            <div class="card-divider" style="margin-top:20px;"></div>
            <label style="display:block; font-weight:normal;">
                <input type="checkbox" name="disclaimer" value="1" @checked(old('disclaimer')) required>
                All of the information provided by the applicant on this document is considered valid and accurate at
                the time it has been received. Any deceit or untruth may lead to discard of the application.
            </label>
            @error('disclaimer') <p class="field-error">{{ $message }}</p> @enderror

            <p class="queue-meta" style="margin-top:16px;">
                Routing: Student → Supervisor → Programme Chair → CGS Verification → Dean of PGR. You will be notified
                by email as your appeal moves through each stage.
            </p>

            <button type="submit">Submit Appeal</button>
        </form>
    </div>
</div>

@push('scripts')
<script @cspNonce>
    (function () {
        var form = document.getElementById('appeal-form');
        if (!form) return;

        function toggle(el, show) {
            el.hidden = !show;
        }

        function syncRcs() {
            var checked = form.querySelector('.appeal-rcs-input:checked');
            toggle(document.getElementById('rcs-completed-fields'), checked && checked.value === 'completed');
            toggle(document.getElementById('rcs-pending-fields'), checked && checked.value === 'pending');
        }

        form.querySelectorAll('.appeal-rcs-input').forEach(function (el) {
            el.addEventListener('change', syncRcs);
        });
        syncRcs();

        var rows = document.getElementById('publications-rows');
        document.getElementById('add-publication-row').addEventListener('click', function () {
            var row = document.createElement('div');
            row.className = 'publication-row';
            row.style.cssText = 'display:flex; gap:8px; margin-bottom:8px;';
            row.innerHTML = '<input type="text" name="publications[]" placeholder="Description" style="flex:1;">'
                + '<button type="button" class="remove-publication-row">Remove</button>';
            rows.appendChild(row);
        });
        rows.addEventListener('click', function (e) {
            if (e.target.classList.contains('remove-publication-row')) {
                e.target.closest('.publication-row').remove();
            }
        });
    })();
</script>
@endpush
@endsection
