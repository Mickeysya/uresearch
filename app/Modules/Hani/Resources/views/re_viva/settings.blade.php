@extends('core::layouts.app')
@section('title', 'Re-viva Settings')
@section('content')
<div class="card-container-inline">
    <x-core::page-header title="Re-viva Settings" subtitle="When examiners are reminded before their deadline." />
    <div class="card card-wide">
        <form method="POST" action="{{ route('reviva.settings.save') }}">
            @csrf
            <p>Send a reminder this many working days before the deadline. Leave a box empty to use fewer reminders.</p>
            @for ($i = 0; $i < 3; $i++)
                <label for="day-{{ $i }}">Reminder {{ $i + 1 }}</label>
                <input type="number" min="1" max="{{ config('hani.reviva.panel_working_days') }}" name="days[]" id="day-{{ $i }}" value="{{ $days[$i] ?? '' }}">
            @endfor
            @error('days') <p class="field-error">{{ $message }}</p> @enderror
            <p class="fstep-hint">The default in config is {{ implode(' and ', $configured) }} working days.</p>
            <button type="submit">Save</button>
        </form>
    </div>
</div>
@endsection
