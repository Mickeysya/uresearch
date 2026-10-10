@extends('core::layouts.app')

@section('title', 'Request a Supervisor')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Supervisor Appointment Request" />

    <div class="card card-wide">
        <form method="POST" action="{{ route('supervision.store') }}" enctype="multipart/form-data" data-stepper>
            @csrf

            <fieldset class="fstep" data-label="Supervisor">
            <p class="fstep-hint">Who you are asking for, and why them.</p>

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

            </fieldset>

            <fieldset class="fstep" data-label="Supporting documents">
            <p class="fstep-hint">Required. Checked for completeness here rather than after it reaches your prospective supervisor.</p>

            <x-nureen::multi-file-upload
                name="supporting_documents"
                label="Supporting Documents"
                :required="true"
                hint="Your research proposal, or whatever your department asks for with a supervision request." />
            </fieldset>

            <button type="submit">Submit Request</button>
        </form>
    </div>
</div>
@include('core::partials.form-stepper')
@endsection
