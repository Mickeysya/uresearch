@extends('core::layouts.app')

@section('title', 'New GA Extension Application')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="GA Extension Application" />

    {{--
        Read-only, straight from the logged-in student's own profile -- not
        submitted as form fields. Editing any of this happens on the
        Profile page, not here; showing it here is only so CGS does not
        have to cross-check the applicant against the student record by
        hand.
    --}}
    <div class="card card-wide" style="margin-bottom: var(--space-6);">
        <h3 style="margin-top: 0;">Applicant</h3>
        <dl style="display: grid; grid-template-columns: max-content 1fr; gap: var(--space-2) var(--space-4); margin: 0;">
            <dt style="color: var(--text-grey);">Name</dt><dd style="margin: 0;">{{ $student->name }}</dd>
            <dt style="color: var(--text-grey);">Matric No.</dt><dd style="margin: 0;">{{ $student->matric_no ?? '—' }}</dd>
            <dt style="color: var(--text-grey);">Programme</dt><dd style="margin: 0;">{{ $student->programme ?? '—' }}</dd>
            <dt style="color: var(--text-grey);">Supervisor</dt><dd style="margin: 0;">{{ $student->supervisor?->name ?? 'Not yet assigned' }}</dd>
        </dl>
    </div>

    <div class="card card-wide">
        <form method="POST" action="{{ route('ga-extension.store') }}" enctype="multipart/form-data" data-stepper>
            @csrf

            <fieldset class="fstep" data-label="Extension details">
            <p class="fstep-hint">Your current end date, the date you are asking for, and why.</p>

            <label for="current_end_date">Current GA End Date</label>
            <input type="date" name="current_end_date" id="current_end_date" required
                   value="{{ old('current_end_date') }}"
                   class="@error('current_end_date') is-invalid @enderror">
            @error('current_end_date') <p class="field-error">{{ $message }}</p> @enderror

            <label for="requested_new_end_date">Requested New End Date</label>
            <input type="date" name="requested_new_end_date" id="requested_new_end_date" required
                   value="{{ old('requested_new_end_date') }}"
                   class="@error('requested_new_end_date') is-invalid @enderror">
            @error('requested_new_end_date') <p class="field-error">{{ $message }}</p> @enderror

            <label for="reason_for_extension">Reason for Extension</label>
            <textarea name="reason_for_extension" id="reason_for_extension" rows="4" required
                      class="@error('reason_for_extension') is-invalid @enderror">{{ old('reason_for_extension') }}</textarea>
            @error('reason_for_extension') <p class="field-error">{{ $message }}</p> @enderror

            </fieldset>

            @if ($additionalFields !== [])
            <fieldset class="fstep" data-label="Additional information">
            <p class="fstep-hint">A few extra details CGS has asked for.</p>

            @foreach ($additionalFields as $key => $field)
                <label for="additional_info_{{ $key }}">
                    {{ $field['label'] }}
                    @unless ($field['required'] ?? false)
                        <span style="color: var(--text-grey)">(optional)</span>
                    @endunless
                </label>
                @switch($field['type'] ?? 'text')
                    @case('textarea')
                        <textarea name="additional_info[{{ $key }}]" id="additional_info_{{ $key }}" rows="4"
                                  @required($field['required'] ?? false)
                                  class="@error('additional_info.'.$key) is-invalid @enderror">{{ old("additional_info.$key") }}</textarea>
                        @break
                    @case('date')
                        <input type="date" name="additional_info[{{ $key }}]" id="additional_info_{{ $key }}"
                               value="{{ old("additional_info.$key") }}"
                               @required($field['required'] ?? false)
                               class="@error('additional_info.'.$key) is-invalid @enderror">
                        @break
                    @default
                        <input type="text" name="additional_info[{{ $key }}]" id="additional_info_{{ $key }}"
                               value="{{ old("additional_info.$key") }}"
                               @required($field['required'] ?? false)
                               class="@error('additional_info.'.$key) is-invalid @enderror">
                @endswitch
                @error('additional_info.'.$key) <p class="field-error">{{ $message }}</p> @enderror
            @endforeach
            </fieldset>
            @endif

            <fieldset class="fstep" data-label="Supporting documents">
            <p class="fstep-hint">
                Required. An application missing any of these is rejected here rather than
                reaching CGS.
            </p>

            @foreach ($requiredDocuments as $field => $label)
                <x-nureen::multi-file-upload
                    :name="$field"
                    :label="$label"
                    :required="true" />
            @endforeach
            </fieldset>

            <button type="submit">Submit Application</button>
        </form>
    </div>
</div>
@include('core::partials.form-stepper')
@endsection
