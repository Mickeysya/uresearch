@extends('core::layouts.app')

@section('title', 'GA/GRA Certification Letter')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="GA/GRA Certification Letter Request" />

    @unless ($hasSupervisor)
        <div class="card card-wide" style="border-color: var(--danger-border);">
            <p style="color: var(--danger-fg); margin: 0;">
                No supervisor is assigned to your account. Please contact CGS before requesting a certification letter.
            </p>
        </div>
    @else
    <div class="card card-wide">
        <form id="ga-certification-form" method="POST" action="{{ route('ga-certification.store') }}"
              enctype="multipart/form-data" data-stepper>
            @csrf

            <fieldset class="fstep" data-label="Appointment">
            <p class="fstep-hint">Which kind of appointment this letter certifies.</p>

            <label for="appointment_type">Appointment Type</label>
            <select name="appointment_type" id="appointment_type" required
                    class="@error('appointment_type') is-invalid @enderror">
                <option value="">Select&hellip;</option>
                <option value="GA" @selected(old('appointment_type') === 'GA')>Graduate Assistant (GA)</option>
                <option value="GRA" @selected(old('appointment_type') === 'GRA')>Graduate Research Assistant (GRA)</option>
            </select>
            @error('appointment_type') <p class="field-error">{{ $message }}</p> @enderror

            <label for="period_start">Appointment period starts</label>
            <input type="date" name="period_start" id="period_start" required
                   value="{{ old('period_start') }}"
                   class="@error('period_start') is-invalid @enderror">
            @error('period_start') <p class="field-error">{{ $message }}</p> @enderror

            <label for="period_end">Appointment period ends</label>
            <input type="date" name="period_end" id="period_end" required
                   value="{{ old('period_end') }}"
                   class="@error('period_end') is-invalid @enderror">
            @error('period_end') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>

            @foreach ($fields as $type => $typeFields)
            <fieldset class="fstep" data-label="{{ $type }} details" data-type-fields="{{ $type }}" hidden>
            <p class="fstep-hint">
                These fields apply to a {{ $type === 'GA' ? 'Graduate Assistant' : 'Graduate Research Assistant' }} appointment only.
            </p>

            @foreach ($typeFields as $key => $field)
                <label for="{{ $key }}">
                    {{ $field['label'] }}
                    @unless ($field['required'] ?? false)
                        <span style="color: var(--text-grey)">(optional)</span>
                    @endunless
                </label>
                <input type="text" name="{{ $key }}" id="{{ $key }}" maxlength="255"
                       value="{{ old($key) }}"
                       class="@error($key) is-invalid @enderror">
                @error($key) <p class="field-error">{{ $message }}</p> @enderror
            @endforeach
            </fieldset>
            @endforeach

            <fieldset class="fstep" data-label="Purpose and documents">
            <p class="fstep-hint">Why you need the letter, and the evidence of work done.</p>

            <label for="purpose">Purpose <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="text" name="purpose" id="purpose" maxlength="255"
                   placeholder="e.g. scholarship application, visa renewal"
                   value="{{ old('purpose') }}"
                   class="@error('purpose') is-invalid @enderror">
            @error('purpose') <p class="field-error">{{ $message }}</p> @enderror

            <div data-type-fields="GA" hidden>
                <x-nureen::multi-file-upload
                    name="supporting_documents"
                    :label="$supportingDocumentLabel['GA']"
                    :required="true" />
            </div>
            <div data-type-fields="GRA" hidden>
                <x-nureen::multi-file-upload
                    name="supporting_documents"
                    :label="$supportingDocumentLabel['GRA']"
                    :required="true" />
            </div>
            </fieldset>

            <button type="submit">Submit Request</button>
        </form>
    </div>
    @endunless
</div>
@include('core::partials.form-stepper')

@push('scripts')
<script @cspNonce>
    (function () {
        var form = document.getElementById('ga-certification-form');
        if (! form) return;

        var typeSelect = document.getElementById('appointment_type');

        // Only the fields for the chosen type are shown, and only those
        // are required, so what the student sees matches exactly what
        // CertificationController::store() will validate.
        function syncType() {
            var value = typeSelect.value;

            form.querySelectorAll('[data-type-fields]').forEach(function (box) {
                var show = box.getAttribute('data-type-fields') === value;
                box.hidden = ! show;
                box.querySelectorAll('input[type="text"]').forEach(function (input) {
                    var label = box.querySelector('label[for="' + input.id + '"]');
                    input.required = show && ! (label && label.textContent.indexOf('(optional)') !== -1);
                });
                box.querySelectorAll('input[type="file"]').forEach(function (input) {
                    input.required = show;
                    // A hidden block keeps its value otherwise -- cleared
                    // so switching type never submits the wrong type's
                    // files alongside the one actually shown.
                    if (! show) input.value = '';
                });
            });
        }

        typeSelect.addEventListener('change', syncType);
        syncType();
    })();
</script>
@endpush
@endsection
