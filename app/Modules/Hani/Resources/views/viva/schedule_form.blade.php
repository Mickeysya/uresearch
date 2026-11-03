@extends('core::layouts.app')

@section('title', 'Set Viva Date')

@section('content')
@php($student = $progress->notice->student)
<div class="card-container-inline">
    <x-core::page-header
        :title="($detail->isScheduled() ? 'Reschedule Viva: ' : 'Set Viva Date: ').$student->name"
        :subtitle="$detail->thesis_title" />

    @include('hani::partials.module_stepper', ['tracker' => \App\Modules\Hani\Support\ModuleTracker::viva($progress), 'compact' => true])

    <div class="card card-wide">
        <h3>Panel</h3>
        <p>Internal examiner: <b>{{ $panel?->internalMain?->name ?? 'Not recorded' }}</b>
           @if ($panel?->internalMain) ({{ $panel->internalMain->email }}) @endif</p>
        <p>External examiner: <b>{{ $panel?->externalMain?->name ?? 'Not recorded' }}</b>
           @if ($panel?->externalMain) ({{ $panel->externalMain->email }}) @endif</p>
        <p>Supervisor: <b>{{ $student->supervisor?->name ?? 'Not recorded' }}</b></p>

        @if ($detail->isScheduled())
            <p class="message-warning">
                Currently {{ $detail->viva_at->format('j M Y, g:i a') }} at {{ $detail->venue }}.
                Saving sends everyone an updated invitation.
            </p>
        @endif

        <form method="POST" action="{{ route('viva.schedule.update', $progress->notice) }}">
            @csrf

            <label for="viva_date">Viva Date</label>
            <input type="date" name="viva_date" id="viva_date" required
                   min="{{ now()->toDateString() }}"
                   value="{{ old('viva_date', $detail->viva_at?->toDateString()) }}"
                   class="@error('viva_date') is-invalid @enderror">
            @error('viva_date') <p class="field-error">{{ $message }}</p> @enderror

            <label for="viva_time">Start Time</label>
            <input type="time" name="viva_time" id="viva_time" required
                   value="{{ old('viva_time', $detail->viva_at?->format('H:i')) }}"
                   class="@error('viva_time') is-invalid @enderror">
            @error('viva_time') <p class="field-error">{{ $message }}</p> @enderror

            <label for="venue">Venue or Online Meeting Link</label>
            <input type="text" name="venue" id="venue" required maxlength="255"
                   value="{{ old('venue', $detail->venue) }}"
                   class="@error('venue') is-invalid @enderror">
            @error('venue') <p class="field-error">{{ $message }}</p> @enderror

            <label for="chair_name">Chairman's Name</label>
            <input type="text" name="chair_name" id="chair_name" required maxlength="150"
                   value="{{ old('chair_name', $detail->chair_name) }}"
                   class="@error('chair_name') is-invalid @enderror">
            @error('chair_name') <p class="field-error">{{ $message }}</p> @enderror

            <label for="chair_email">Chairman's Email</label>
            <input type="email" name="chair_email" id="chair_email" required maxlength="150"
                   value="{{ old('chair_email', $detail->chair_email) }}"
                   class="@error('chair_email') is-invalid @enderror">
            @error('chair_email') <p class="field-error">{{ $message }}</p> @enderror

            <label for="co_supervisor_name">Co-supervisor's Name <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="text" name="co_supervisor_name" id="co_supervisor_name" maxlength="150"
                   value="{{ old('co_supervisor_name', $detail->co_supervisor_name) }}"
                   class="@error('co_supervisor_name') is-invalid @enderror">
            @error('co_supervisor_name') <p class="field-error">{{ $message }}</p> @enderror

            <label for="co_supervisor_email">Co-supervisor's Email <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="email" name="co_supervisor_email" id="co_supervisor_email" maxlength="150"
                   value="{{ old('co_supervisor_email', $coSupervisorEmail) }}"
                   class="@error('co_supervisor_email') is-invalid @enderror">
            @error('co_supervisor_email') <p class="field-error">{{ $message }}</p> @enderror

            <p class="fstep-hint">
                The invitation, with a calendar file, goes to the student, the supervisor, both examiners,
                the chairman and the co-supervisor if given.
            </p>

            <button type="submit">{{ $detail->isScheduled() ? 'Reschedule and Notify' : 'Schedule and Notify' }}</button>
        </form>
    </div>
</div>
@endsection
