@extends('core::layouts.app')

@section('title', 'Stuck Reviews')

{{--
    Requests no account can act on -- Support\StuckSupervisorStages. A
    GA Extension or Certification can be handed to another supervisor here
    (recorded in its history, with the reason, and the new supervisor is
    emailed). Anything else names what to fix.
--}}

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Stuck Reviews"
        subtitle="Pending requests that no account can act on: the named supervisor can't act, or the student's department has no Chair account." />

    @if ($stuck->isEmpty())
        <div class="empty-state">Nothing is stuck: every pending request has someone who can act on it.</div>
    @else
        @foreach ($stuck as $row)
            @php($application = $row['application'])
            <div class="card card-wide" style="border-color: var(--warning-border);">
                <p class="queue-meta" style="margin: 0;">
                    {{ $application->module()->label() }} · {{ $application->reference() }} · waiting at {{ $row['stage'] }}
                    since {{ $application->submitted_at?->format('j M Y') }}
                </p>
                <h3 style="margin: 4px 0;">{{ $application->student?->name }} ({{ $application->student?->matric_no }})</h3>
                <p style="margin: 0 0 var(--space-3); color: var(--warning-fg); font-weight: 600;">{{ $row['problem'] }}</p>

                @if ($row['reassignable'])
                    <form method="POST" action="{{ route('nureen.stuck-reviews.reassign', $application) }}">
                        @csrf
                        <label for="supervisor-{{ $application->id }}">Give it to</label>
                        <select id="supervisor-{{ $application->id }}" name="supervisor_id" required>
                            <option value="">Choose a supervisor&hellip;</option>
                            @foreach ($supervisors as $supervisor)
                                <option value="{{ $supervisor->id }}" @selected(old('supervisor_id') == $supervisor->id)>{{ $supervisor->name }} · {{ $supervisor->department ?: 'no department' }}</option>
                            @endforeach
                        </select>
                        <label for="reason-{{ $application->id }}">Reason</label>
                        <textarea id="reason-{{ $application->id }}" name="reason" rows="2" required maxlength="255"
                                  aria-describedby="reason-hint-{{ $application->id }}"
                                  placeholder="Why this request is going to another supervisor.">{{ old('reason') }}</textarea>
                        <p class="queue-meta" id="reason-hint-{{ $application->id }}">Recorded in the application's history and shown to the student. The new supervisor is emailed.</p>
                        @error('supervisor_id') <p class="field-error">{{ $message }}</p> @enderror
                        @error('reason') <p class="field-error">{{ $message }}</p> @enderror
                        <button type="submit" class="btn">Reassign</button>
                    </form>
                @else
                    <p class="queue-meta" style="margin: 0;">
                        @if ($application->module_type === 'supervision' && $row['stage'] === 'Lecturer/Supervisor')
                            A Supervision request can't be reassigned: the named supervisor is the appointment being requested. Fix the account in Users and Roles, or reject the request from the queue so the student can resubmit.
                        @else
                            Fix this in Users and Roles; the request moves on as soon as an account can act.
                        @endif
                    </p>
                @endif
            </div>
        @endforeach
    @endif
</div>
@endsection
