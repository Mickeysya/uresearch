@extends('core::layouts.app')

@section('title', 'Request a Supervisor')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Supervisor Appointment Request" />

    <div class="card card-wide">
        <form method="POST" action="{{ route('supervision.store') }}" enctype="multipart/form-data" data-stepper
              id="supervision-form" data-current-supervisor="{{ $currentSupervisorId }}" data-ga-record="{{ $gaFromRecord === null ? '' : ($gaFromRecord ? '1' : '0') }}">
            @csrf

            <fieldset class="fstep" data-label="Supervisor">
            <p class="fstep-hint">Who you are asking for, in what capacity, and why them.</p>

            <label for="appointment_role">Appointment Role</label>
            <select name="appointment_role" id="appointment_role" required
                    class="@error('appointment_role') is-invalid @enderror">
                <option value="">Select&hellip;</option>
                <option value="main_supervisor" @selected(old('appointment_role') == 'main_supervisor')>Main Supervisor</option>
                <option value="co_supervisor" @selected(old('appointment_role') == 'co_supervisor')>Co-Supervisor</option>
            </select>
            @if ($currentSupervisorId)
                <p class="queue-meta" style="margin-top: -8px;">
                    A Main Supervisor appointment replaces your current supervisor{{ $currentSupervisor ? ', '.$currentSupervisor->name.',' : '' }} once approved.
                </p>
            @endif
            @error('appointment_role') <p class="field-error">{{ $message }}</p> @enderror

            <label for="appointment_type">Appointment Type</label>
            <select name="appointment_type" id="appointment_type" required
                    class="@error('appointment_type') is-invalid @enderror">
                <option value="">Select&hellip;</option>
                <option value="new" @selected(old('appointment_type') == 'new')>New</option>
                <option value="existing" @selected(old('appointment_type') == 'existing')>Existing</option>
            </select>
            @error('appointment_type') <p class="field-error">{{ $message }}</p> @enderror

            <label for="requested_supervisor_id">Requested Supervisor</label>
            <select name="requested_supervisor_id" id="requested_supervisor_id" required
                    class="@error('requested_supervisor_id') is-invalid @enderror">
                <option value="">Select a supervisor&hellip;</option>
                @foreach ($supervisors as $supervisor)
                    <option value="{{ $supervisor->id }}" @selected(old('requested_supervisor_id') == $supervisor->id)>
                        {{ $supervisor->name }}@if ($supervisor->department) &middot; {{ $supervisor->department }}@endif
                    </option>
                @endforeach
            </select>
            @error('requested_supervisor_id') <p class="field-error">{{ $message }}</p> @enderror

            <label for="justification">Justification</label>
            <textarea name="justification" id="justification" rows="4" required
                      class="@error('justification') is-invalid @enderror">{{ old('justification') }}</textarea>
            <p class="queue-meta" style="margin-top: -8px;">
                Briefly explain why you are requesting this supervisor, e.g. shared
                research interest or a prior working relationship.
            </p>
            @error('justification') <p class="field-error">{{ $message }}</p> @enderror

            {{-- Changing Main Supervisor as a GA needs PGFAC approval first
                 (Postgraduate Handbook 2025). With no GA appointment on
                 record, the student says whether they are one. Shown by the
                 script below only for a change; without JavaScript it is
                 always shown, and SupervisionController::store() decides. --}}
            @if ($gaFromRecord === null)
                <fieldset class="sv-choice @error('declares_ga') is-invalid @enderror" data-change-only>
                    <legend>Are you currently a Graduate Assistant (GA)?</legend>
                    <label class="sv-choice-option"><input type="radio" name="declares_ga" value="1" @checked(old('declares_ga') === '1')> Yes</label>
                    <label class="sv-choice-option"><input type="radio" name="declares_ga" value="0" @checked(old('declares_ga') === '0')> No</label>
                    <p class="queue-meta" style="margin: var(--space-1) 0 0;">No GA appointment is on record for you, so we need to ask. A GA changing supervisor needs PGFAC approval first.</p>
                </fieldset>
                @error('declares_ga') <p class="field-error">{{ $message }}</p> @enderror
            @endif

            </fieldset>

            <fieldset class="fstep" data-label="Supporting documents">
            <p class="fstep-hint">Required. Checked for completeness here rather than after it reaches your prospective supervisor.</p>

            <x-nureen::multi-file-upload
                name="supporting_documents"
                label="Supporting Documents"
                :required="true"
                hint="Your research proposal, or whatever your department asks for with a supervision request." />

            <div data-pgfac-block>
                <p class="message-warning" style="margin-bottom: var(--space-2);">
                    You are changing your Main Supervisor as a Graduate Assistant. The Postgraduate Handbook requires PGFAC approval first:
                    attach the {{ $pgfacLabel }}, with your supervisor's memo.
                </p>
                <x-nureen::multi-file-upload
                    name="pgfac_memo_documents"
                    :label="$pgfacLabel"
                    :required="false"
                    hint="Required for a GA changing Main Supervisor." />
            </div>
            </fieldset>

            <button type="submit">Submit Request</button>
        </form>
    </div>
</div>
@include('core::partials.form-stepper')

@push('head')
<style>
    .sv-choice { border: 0; padding: 0; margin: var(--space-4) 0 0; }
    .sv-choice legend { padding: 0; margin-bottom: var(--space-1); font-weight: var(--weight-semi); color: var(--text-dark); }
    .sv-choice-option { display: inline-flex; align-items: center; gap: var(--space-2); margin-right: var(--space-5); font-weight: 400; }
    .sv-choice-option input { width: auto; margin: 0; }
</style>
@endpush

@push('scripts')
<script @cspNonce>
(function () {
    // A change of Main Supervisor, by a GA (on record or declared), needs
    // the PGFAC memo. Mirrors SupervisionController::store(), which decides.
    var form = document.getElementById('supervision-form');
    if (!form) return;
    var current = form.getAttribute('data-current-supervisor');
    var record = form.getAttribute('data-ga-record');
    var changeOnly = form.querySelector('[data-change-only]');
    var block = form.querySelector('[data-pgfac-block]');
    var memo = block.querySelector('input[type="file"]');

    function sync() {
        var role = form.querySelector('[name="appointment_role"]').value;
        var requested = form.querySelector('[name="requested_supervisor_id"]').value;
        var change = role === 'main_supervisor' && current !== '' && requested !== '' && requested !== current;
        var declared = form.querySelector('[name="declares_ga"]:checked');
        var ga = record === '1' || (record === '' && declared && declared.value === '1');

        if (changeOnly) {
            changeOnly.hidden = !change;
            changeOnly.querySelectorAll('input').forEach(function (input) { input.required = change; });
        }
        block.hidden = !(change && ga);
        memo.required = change && ga && !memo.hasAttribute('data-has-existing');
        if (block.hidden) memo.value = '';
    }

    form.querySelectorAll('[name="appointment_role"], [name="requested_supervisor_id"], [name="declares_ga"]').forEach(function (input) {
        input.addEventListener('change', sync);
    });
    sync();
})();
</script>
@endpush
@endsection
