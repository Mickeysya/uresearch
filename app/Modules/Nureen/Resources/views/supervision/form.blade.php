@extends('core::layouts.app')

@section('title', 'Request a Supervisor')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Supervisor Appointment Request</h2>
        <div class="card-divider"></div>

        <form method="POST" action="{{ route('supervision.store') }}" enctype="multipart/form-data">
            @csrf

            <label for="requested_supervisor_id">Requested Supervisor</label>
            <select name="requested_supervisor_id" id="requested_supervisor_id" required
                    class="@error('requested_supervisor_id') is-invalid @enderror">
                <option value="">Select a supervisor&hellip;</option>
                @foreach ($supervisors as $supervisor)
                    <option value="{{ $supervisor->id }}" @selected(old('requested_supervisor_id') == $supervisor->id)>
                        {{ $supervisor->name }}@if ($supervisor->department) &mdash; {{ $supervisor->department }}@endif
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

            <label for="supporting_document">Supporting Document <span style="color: var(--text-grey)">(required)</span></label>
            <input type="file" name="supporting_document" id="supporting_document" required
                   class="@error('supporting_document') is-invalid @enderror">
            @error('supporting_document') <p class="field-error">{{ $message }}</p> @enderror
            <p class="queue-meta" style="margin-top: -8px;">
                Your research proposal, or whatever your department asks for with a
                supervision request. PDF, image or Office document, up to 10&nbsp;MB.
                The request is checked for completeness here rather than after it
                reaches your prospective supervisor.
            </p>

            <button type="submit">Submit Request</button>
        </form>
    </div>
</div>
@endsection
