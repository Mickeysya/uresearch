@extends('core::layouts.app')

@section('title', 'Revise and Resubmit Appeal')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Revise and Resubmit — Appeal #{{ $application->id }}</h2>
        <div class="card-divider"></div>

        @if ($lastReturn)
            <div class="empty-state" style="text-align:left;">
                <b>Returned at the {{ $lastReturn->stage_label }} stage</b>
                @if ($lastReturn->remarks)
                    <p style="margin-top:6px;">"{{ $lastReturn->remarks }}"</p>
                @endif
            </div>
        @endif

        <form method="POST" action="{{ route('candidacy-appeal.resubmit', $application) }}" enctype="multipart/form-data" id="appeal-form" style="margin-top: 16px;">
            @csrf

            <h3>Section A — Academic Progress / Current Status</h3>

            <label>Phase</label>
            <fieldset style="border:none; padding:0; margin:0;">
                @foreach (\App\Modules\Chloe\Models\CandidacyAppealDetail::phases() as $value => $label)
                    <label style="display:block; font-weight:normal;">
                        <input type="radio" name="phase" value="{{ $value }}"
                               @checked(old('phase', $detail->phase) === $value) required>
                        {{ $label }}
                    </label>
                @endforeach
            </fieldset>
            @error('phase') <p class="field-error">{{ $message }}</p> @enderror

            <label for="writing_completion_percent">Percentage of completion (%)</label>
            <input type="number" name="writing_completion_percent" id="writing_completion_percent"
                   min="0" max="100" required value="{{ old('writing_completion_percent', $detail->writing_completion_percent) }}">
            @error('writing_completion_percent') <p class="field-error">{{ $message }}</p> @enderror

            <h4 style="margin-top:16px;">Research Completion Seminar (RCS)</h4>
            <label style="display:block; font-weight:normal;">
                <input type="radio" name="rcs_status" value="completed" class="appeal-rcs-input" @checked(old('rcs_status', $detail->rcs_status) === 'completed') required>
                RCS completed
            </label>
            <label style="display:block; font-weight:normal;">
                <input type="radio" name="rcs_status" value="pending" class="appeal-rcs-input" @checked(old('rcs_status', $detail->rcs_status ?? 'pending') === 'pending') required>
                Not yet done
            </label>
            @error('rcs_status') <p class="field-error">{{ $message }}</p> @enderror

            <div id="rcs-completed-fields" hidden>
                <label for="rcs_date">RCS Date</label>
                <input type="date" name="rcs_date" id="rcs_date" value="{{ old('rcs_date', $detail->rcs_date?->format('Y-m-d')) }}">
                @error('rcs_date') <p class="field-error">{{ $message }}</p> @enderror

                <label for="rcs_category">RCS Category</label>
                <input type="text" name="rcs_category" id="rcs_category" value="{{ old('rcs_category', $detail->rcs_category) }}">
                @error('rcs_category') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div id="rcs-pending-fields" hidden>
                <label for="rcs_expected_date">Expected date for RCS <span style="color: var(--text-grey);">*if applicable</span></label>
                <input type="date" name="rcs_expected_date" id="rcs_expected_date" value="{{ old('rcs_expected_date', $detail->rcs_expected_date?->format('Y-m-d')) }}">
                @error('rcs_expected_date') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <h3 style="margin-top: 20px;">Section B — Candidacy Extension Record</h3>
            <p style="color: var(--text-grey); font-size: 13px;">Have you previously had a candidacy extension through either route?</p>

            <label>Through GSC</label>
            <label style="display:inline-block; font-weight:normal; margin-right:16px;">
                <input type="radio" name="extension_via_gsc" value="yes" @checked(old('extension_via_gsc', $detail->extension_via_gsc ? 'yes' : 'no') === 'yes') required> Yes
            </label>
            <label style="display:inline-block; font-weight:normal;">
                <input type="radio" name="extension_via_gsc" value="no" @checked(old('extension_via_gsc', $detail->extension_via_gsc ? 'yes' : 'no') === 'no') required> No
            </label>
            @error('extension_via_gsc') <p class="field-error">{{ $message }}</p> @enderror

            <label style="margin-top:10px;">Through Vice Chancellor</label>
            <label style="display:inline-block; font-weight:normal; margin-right:16px;">
                <input type="radio" name="extension_via_vc" value="yes" @checked(old('extension_via_vc', $detail->extension_via_vc ? 'yes' : 'no') === 'yes') required> Yes
            </label>
            <label style="display:inline-block; font-weight:normal;">
                <input type="radio" name="extension_via_vc" value="no" @checked(old('extension_via_vc', $detail->extension_via_vc ? 'yes' : 'no') === 'no') required> No
            </label>
            @error('extension_via_vc') <p class="field-error">{{ $message }}</p> @enderror

            <h3 style="margin-top: 20px;">Section C — List of Publications/Journal</h3>
            <div id="publications-rows">
                @php($existing = old('publications', $detail->publications->pluck('description')->all() ?: ['']))
                @foreach ($existing as $i => $publication)
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
                   min="1" max="{{ $detail->candidacy->remainingAppealMonths() }}" required
                   value="{{ old('requested_extension_months', $detail->requested_extension_months) }}">
            @error('requested_extension_months') <p class="field-error">{{ $message }}</p> @enderror

            <label for="reason" style="margin-top:16px;">Additional Comments <span style="color: var(--text-grey);">(optional)</span></label>
            <textarea name="reason" id="reason" rows="3">{{ old('reason', $detail->reason) }}</textarea>
            @error('reason') <p class="field-error">{{ $message }}</p> @enderror

            <label for="appeal_form" style="margin-top:16px;">Revised Appeal Form <span style="color: var(--text-grey)">(optional — only if it changed)</span></label>
            <input type="file" name="appeal_form" id="appeal_form">
            @error('appeal_form') <p class="field-error">{{ $message }}</p> @enderror

            <button type="submit" style="margin-top:16px;">Resubmit Appeal</button>
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
