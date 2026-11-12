@extends('core::layouts.app')
@section('title', 'Re-viva Settings')
@section('content')
<div class="card-container-inline">
    <x-core::page-header title="Re-viva Settings" subtitle="When examiners are reminded before their deadline." />
    <div class="card card-wide">
        <form method="POST" action="{{ route('reviva.settings.save') }}">
            @csrf
            <p>Send a reminder this many working days before the deadline. Leave a box empty to use fewer reminders. Each reminder is sent once.</p>
            @foreach ($levels as $level => $row)
                <fieldset>
                    <legend>{{ $row['label'] }} thesis ({{ $row['limit'] }} working days)</legend>
                    @for ($i = 0; $i < 3; $i++)
                        <label for="day-{{ $level }}-{{ $i }}">Reminder {{ $i + 1 }}</label>
                        <input type="number" min="1" max="{{ $row['limit'] }}" name="days[{{ $level }}][]" id="day-{{ $level }}-{{ $i }}" value="{{ old("days.$level.$i", $row['days'][$i] ?? '') }}">
                    @endfor
                    @error("days.$level") <p class="field-error">{{ $message }}</p> @enderror
                    <p class="fstep-hint">The default in config is {{ implode(', ', $row['configured']) }} working days.</p>
                </fieldset>
            @endforeach
            <button type="submit">Save</button>
        </form>
    </div>
</div>
@endsection
