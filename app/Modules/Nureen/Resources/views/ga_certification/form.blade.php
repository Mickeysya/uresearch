@extends('core::layouts.app')

@section('title', 'GA/GRA Certification Letter')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>GA/GRA Certification Letter Request</h2>
        <div class="card-divider"></div>

        <form method="POST" action="{{ route('ga-certification.store') }}">
            @csrf

            <label for="appointment_type">Appointment Type</label>
            <select name="appointment_type" id="appointment_type" required
                    class="@error('appointment_type') is-invalid @enderror">
                <option value="">Select&hellip;</option>
                <option value="GA" @selected(old('appointment_type') === 'GA')>Graduate Assistant (GA)</option>
                <option value="GRA" @selected(old('appointment_type') === 'GRA')>Graduate Research Assistant (GRA)</option>
            </select>
            @error('appointment_type') <p class="field-error">{{ $message }}</p> @enderror

            <label for="period_start">Appointment Period — Start</label>
            <input type="date" name="period_start" id="period_start" required
                   value="{{ old('period_start') }}"
                   class="@error('period_start') is-invalid @enderror">
            @error('period_start') <p class="field-error">{{ $message }}</p> @enderror

            <label for="period_end">Appointment Period — End</label>
            <input type="date" name="period_end" id="period_end" required
                   value="{{ old('period_end') }}"
                   class="@error('period_end') is-invalid @enderror">
            @error('period_end') <p class="field-error">{{ $message }}</p> @enderror

            <label for="purpose">Purpose</label>
            <input type="text" name="purpose" id="purpose" required maxlength="255"
                   placeholder="e.g. scholarship application, visa renewal"
                   value="{{ old('purpose') }}"
                   class="@error('purpose') is-invalid @enderror">
            @error('purpose') <p class="field-error">{{ $message }}</p> @enderror

            <button type="submit">Submit Request</button>
        </form>
    </div>
</div>
@endsection
