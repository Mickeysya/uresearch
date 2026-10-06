{{--
    Sections A-D of the appeal, shared by the new-appeal form and the
    revise-and-resubmit form so the two cannot drift. Each section is one
    wizard step (core::partials.form-stepper).

    Expects $candidacy. $detail is null on a new appeal and the returned
    appeal's CandidacyAppealDetail on a resubmission, so every value falls
    back old() -> saved -> empty.
--}}
@php
    $detail = $detail ?? null;
    $rcsStatus = old('rcs_status', $detail?->rcs_status ?? 'pending');
    $viaGsc = old('extension_via_gsc', $detail ? ($detail->extension_via_gsc ? 'yes' : 'no') : 'no');
    $viaVc = old('extension_via_vc', $detail ? ($detail->extension_via_vc ? 'yes' : 'no') : 'no');
    $publications = old('publications', $detail?->publications->pluck('description')->all() ?: ['']);
@endphp

<fieldset class="fstep" data-label="Academic progress">
    <p class="fstep-hint">Section A. Where your research stands today.</p>

    <label id="phase-label">Phase</label>
    <div class="chloe-choices is-stacked" role="radiogroup" aria-labelledby="phase-label">
        @foreach (\App\Modules\Chloe\Models\CandidacyAppealDetail::phases() as $value => $label)
            <label>
                <input type="radio" name="phase" value="{{ $value }}" required
                       @checked(old('phase', $detail?->phase) === $value)>
                {{ $label }}
            </label>
        @endforeach
    </div>
    @error('phase') <p class="field-error">{{ $message }}</p> @enderror

    <label for="writing_completion_percent">Percentage of completion (%)</label>
    <input type="number" name="writing_completion_percent" id="writing_completion_percent"
           min="0" max="100" required
           value="{{ old('writing_completion_percent', $detail?->writing_completion_percent) }}"
           class="@error('writing_completion_percent') is-invalid @enderror">
    @error('writing_completion_percent') <p class="field-error">{{ $message }}</p> @enderror

    <label id="rcs-label">Research Completion Seminar (RCS)</label>
    <div class="chloe-choices" role="radiogroup" aria-labelledby="rcs-label">
        <label>
            <input type="radio" name="rcs_status" value="completed" class="appeal-rcs-input" required
                   @checked($rcsStatus === 'completed')>
            Completed
        </label>
        <label>
            <input type="radio" name="rcs_status" value="pending" class="appeal-rcs-input" required
                   @checked($rcsStatus === 'pending')>
            Not yet done
        </label>
    </div>
    @error('rcs_status') <p class="field-error">{{ $message }}</p> @enderror

    <div id="rcs-completed-fields" hidden>
        <label for="rcs_date">RCS date</label>
        <input type="date" name="rcs_date" id="rcs_date" max="{{ now()->toDateString() }}"
               value="{{ old('rcs_date', $detail?->rcs_date?->format('Y-m-d')) }}"
               class="@error('rcs_date') is-invalid @enderror">
        @error('rcs_date') <p class="field-error">{{ $message }}</p> @enderror

        <label for="rcs_category">RCS category</label>
        <input type="text" name="rcs_category" id="rcs_category" maxlength="255"
               value="{{ old('rcs_category', $detail?->rcs_category) }}"
               class="@error('rcs_category') is-invalid @enderror">
        @error('rcs_category') <p class="field-error">{{ $message }}</p> @enderror
    </div>

    <div id="rcs-pending-fields" hidden>
        <label for="rcs_expected_date">Expected RCS date</label>
        <input type="date" name="rcs_expected_date" id="rcs_expected_date"
               value="{{ old('rcs_expected_date', $detail?->rcs_expected_date?->format('Y-m-d')) }}"
               class="@error('rcs_expected_date') is-invalid @enderror">
        @error('rcs_expected_date') <p class="field-error">{{ $message }}</p> @enderror
    </div>
</fieldset>

<fieldset class="fstep" data-label="Extension record">
    <p class="fstep-hint">Section B. Have you had a candidacy extension before, through either route?</p>

    <label id="gsc-label">Through GSC</label>
    <div class="chloe-choices" role="radiogroup" aria-labelledby="gsc-label">
        <label><input type="radio" name="extension_via_gsc" value="yes" required @checked($viaGsc === 'yes')> Yes</label>
        <label><input type="radio" name="extension_via_gsc" value="no" required @checked($viaGsc === 'no')> No</label>
    </div>
    @error('extension_via_gsc') <p class="field-error">{{ $message }}</p> @enderror

    <label id="vc-label">Through the Vice Chancellor</label>
    <div class="chloe-choices" role="radiogroup" aria-labelledby="vc-label">
        <label><input type="radio" name="extension_via_vc" value="yes" required @checked($viaVc === 'yes')> Yes</label>
        <label><input type="radio" name="extension_via_vc" value="no" required @checked($viaVc === 'no')> No</label>
    </div>
    @error('extension_via_vc') <p class="field-error">{{ $message }}</p> @enderror
</fieldset>

<fieldset class="fstep" data-label="Publications">
    <p class="fstep-hint">Section C. Your publications and journal papers, one per row. Leave it empty if you have none yet.</p>

    <label for="publication-0">Publications</label>
    <div id="publications-rows">
        @foreach ($publications as $i => $publication)
            <div class="chloe-row">
                <input type="text" name="publications[]" id="publication-{{ $i }}" maxlength="1000"
                       value="{{ $publication }}" placeholder="Title, venue and year">
                <button type="button" class="btn-secondary" data-remove-publication @if ($i === 0) hidden @endif>Remove</button>
            </div>
        @endforeach
    </div>
    <button type="button" class="btn-secondary" id="add-publication-row">Add another publication</button>
    @error('publications.*') <p class="field-error">{{ $message }}</p> @enderror
</fieldset>

<fieldset class="fstep" data-label="Extension requested">
    <p class="fstep-hint">Section D. You can ask for up to {{ $candidacy->remainingAppealMonths() }} more
        {{ Str::plural('month', $candidacy->remainingAppealMonths()) }}; the ceiling is
        {{ \App\Modules\Chloe\Models\StudyCandidacy::MAX_APPEAL_MONTHS }} months across every appeal.</p>

    <label for="requested_extension_months">Months requested</label>
    <input type="number" name="requested_extension_months" id="requested_extension_months"
           min="1" max="{{ $candidacy->remainingAppealMonths() }}" step="1" required
           value="{{ old('requested_extension_months', $detail?->requested_extension_months) }}"
           class="@error('requested_extension_months') is-invalid @enderror">
    @error('requested_extension_months') <p class="field-error">{{ $message }}</p> @enderror

    <label for="reason">Additional comments <span style="color: var(--text-grey)">(optional)</span></label>
    <textarea name="reason" id="reason" rows="4" maxlength="2000"
              class="@error('reason') is-invalid @enderror">{{ old('reason', $detail?->reason) }}</textarea>
    @error('reason') <p class="field-error">{{ $message }}</p> @enderror

    <label for="appeal_form">
        {{ $detail ? 'Revised appeal form' : 'Official appeal form' }}
        <span style="color: var(--text-grey)">({{ $detail ? 'optional, only if it changed' : 'optional, if you have the signed university form' }})</span>
    </label>
    <input type="file" name="appeal_form" id="appeal_form" class="@error('appeal_form') is-invalid @enderror">
    @error('appeal_form') <p class="field-error">{{ $message }}</p> @enderror

</fieldset>

@push('scripts')
<script @cspNonce>
    (function () {
        var form = document.getElementById('appeal-form');
        if (! form) return;

        // Only the RCS fields that apply are shown, and only those are
        // required, so the wizard's per-step check matches what the
        // controller's required_if rules will ask for.
        function syncRcs() {
            var checked = form.querySelector('.appeal-rcs-input:checked');
            var value = checked ? checked.value : null;

            [['rcs-completed-fields', 'completed'], ['rcs-pending-fields', 'pending']].forEach(function (pair) {
                var box = document.getElementById(pair[0]);
                var show = value === pair[1];
                box.hidden = ! show;
                box.querySelectorAll('input').forEach(function (input) { input.required = show; });
            });
        }

        form.querySelectorAll('.appeal-rcs-input').forEach(function (el) {
            el.addEventListener('change', syncRcs);
        });
        syncRcs();

        var rows = document.getElementById('publications-rows');

        document.getElementById('add-publication-row').addEventListener('click', function () {
            var row = rows.firstElementChild.cloneNode(true);
            var input = row.querySelector('input');
            input.value = '';
            input.removeAttribute('id');
            row.querySelector('[data-remove-publication]').hidden = false;
            rows.appendChild(row);
            input.focus();
        });

        rows.addEventListener('click', function (e) {
            if (e.target.matches('[data-remove-publication]')) {
                e.target.closest('.chloe-row').remove();
            }
        });
    })();
</script>
@endpush
