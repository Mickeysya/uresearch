@extends('core::layouts.app')

@section('title', 'New GA Extension Application')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>GA Extension Application</h2>
        <div class="card-divider"></div>

        <form method="POST" action="{{ route('ga-extension.store') }}" enctype="multipart/form-data">
            @csrf

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

            <label for="supporting_document">Supporting Document <span style="color: var(--text-grey)">(required)</span></label>
            <input type="file" name="supporting_document" id="supporting_document" required
                   class="@error('supporting_document') is-invalid @enderror">
            @error('supporting_document') <p class="field-error">{{ $message }}</p> @enderror
            <p class="queue-meta" style="margin-top: -8px;">
                PDF, image or Office document, up to 10&nbsp;MB. Incomplete applications
                are rejected here rather than reaching CGS.
            </p>

            <button type="submit">Submit Application</button>
        </form>
    </div>
</div>
@endsection
