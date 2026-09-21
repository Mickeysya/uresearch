@extends('core::layouts.app')

@section('title', 'New Travel Application')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Travel Application" />

    <div class="card card-wide">
        <form method="POST" action="{{ route('travel.store') }}" enctype="multipart/form-data" class="app-form" data-stepper>
            @csrf

            <fieldset class="fstep" data-label="Trip details">
            <p class="fstep-hint">Where you are going, when, and why. The
               duration is worked out from the dates.</p>

            <label for="type_of_request">Type of Request</label>
            <select name="type_of_request" id="type_of_request" required
                    class="@error('type_of_request') is-invalid @enderror">
                @foreach ($requestTypes as $value => $labelText)
                    <option value="{{ $value }}" @selected(old('type_of_request') === $value)>{{ $labelText }}</option>
                @endforeach
            </select>
            @error('type_of_request') <p class="field-error">{{ $message }}</p> @enderror

            <label for="other_request_specify">If "Others", please specify</label>
            <input type="text" name="other_request_specify" id="other_request_specify"
                   value="{{ old('other_request_specify') }}"
                   class="@error('other_request_specify') is-invalid @enderror">
            @error('other_request_specify') <p class="field-error">{{ $message }}</p> @enderror

            <label for="travel_start_date">Start Date</label>
            {{-- min mirrors the controller's after_or_equal:today. Without it
                 the wizard's checkValidity() gate waves a past date through and
                 the student only finds out on submit. --}}
            <input type="date" name="travel_start_date" id="travel_start_date" required
                   min="{{ now()->toDateString() }}"
                   value="{{ old('travel_start_date') }}"
                   class="@error('travel_start_date') is-invalid @enderror">
            @error('travel_start_date') <p class="field-error">{{ $message }}</p> @enderror

            <label for="travel_end_date">End Date</label>
            <input type="date" name="travel_end_date" id="travel_end_date" required
                   value="{{ old('travel_end_date') }}"
                   class="@error('travel_end_date') is-invalid @enderror">
            @error('travel_end_date') <p class="field-error">{{ $message }}</p> @enderror

            {{-- Duration is computed from the dates on save, so there is no
                 field for it here. --}}

            <label for="reason_for_travel">Reason for Travel</label>
            <input type="text" name="reason_for_travel" id="reason_for_travel" required
                   value="{{ old('reason_for_travel') }}"
                   class="@error('reason_for_travel') is-invalid @enderror">
            @error('reason_for_travel') <p class="field-error">{{ $message }}</p> @enderror

            <label for="destination_address">Destination</label>
            <input type="text" name="destination_address" id="destination_address" required
                   value="{{ old('destination_address') }}"
                   class="@error('destination_address') is-invalid @enderror">
            @error('destination_address') <p class="field-error">{{ $message }}</p> @enderror

            <div class="checkbox-row">
                <input type="checkbox" name="is_international" id="is_international" value="1"
                       @checked(old('is_international'))>
                <label for="is_international">International Travel</label>
            </div>
            </fieldset>

            {{-- International travel routes through CGS and the Dean as well,
                 so which of these two steps you are on does not change the
                 chain -- TravelWorkflow::stages() decides that from
                 is_international on submit. --}}
            <fieldset class="fstep" data-label="Contact &amp; documents">
            <p class="fstep-hint">Who to reach if something goes wrong while
               you are away, and anything supporting the request.</p>

            <label for="contact_person_name">Contact Person Name</label>
            <input type="text" name="contact_person_name" id="contact_person_name"
                   value="{{ old('contact_person_name') }}">

            <label for="contact_person_no">Contact Person No</label>
            <input type="text" name="contact_person_no" id="contact_person_no"
                   value="{{ old('contact_person_no') }}">

            <label for="supporting_document">Supporting Document <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="file" name="supporting_document" id="supporting_document"
                   class="@error('supporting_document') is-invalid @enderror">
            @error('supporting_document') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>

            <button type="submit">Submit Application</button>
        </form>
    </div>
</div>

@include('core::partials.form-stepper')
@endsection
