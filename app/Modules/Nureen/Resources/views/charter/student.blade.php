@extends('core::layouts.app')

@section('title', 'Expected Decision Dates')

@section('content')
{{--
    Each pending GA Extension, Supervision or Certification application with
    the date Support\ClientCharter::expectedDecision() works out. Worded as
    a service target, not a promise: the Client Charter is CGS's commitment
    on how long each stage should take, and this page only applies it.
--}}
<div class="card-container-inline">
    <x-core::page-header
        title="Expected Decision Dates"
        subtitle="When to expect an outcome on your GA Extension, Supervision and Certification applications, based on the CGS Client Charter." />

    @forelse ($rows as $row)
        @php
            $application = $row['application'];
            $expected = $row['expected'];
            $clock = $expected['clock'];
            $overdue = $clock['on_charter'] && $clock['overdue'];
        @endphp

        <div class="card card-wide" style="margin-bottom: var(--space-5);" data-expected-for="{{ $application->id }}">
            <h3 style="margin: 0 0 var(--space-1);">
                <a href="{{ route('applications.show', $application) }}">{{ $application->module()->label() }}</a>
            </h3>
            <p class="queue-meta" style="margin: 0 0 var(--space-3);">
                Reference {{ $application->reference() }}
                · Submitted {{ $application->submitted_at?->format('j M Y') }}
                · Currently with the {{ $clock['stage']->label }}
            </p>

            @if ($expected['expected_date'])
                <p style="margin: 0; font-size: var(--text-lg);">
                    Expected decision by <b>{{ $expected['expected_date']->format('l, j F Y') }}</b>
                </p>
                <p class="queue-meta" style="margin: var(--space-1) 0 0;">
                    Based on the CGS Client Charter of {{ $expected['total_charter_days'] }} {{ Str::plural('working day', $expected['total_charter_days']) }} for this type of application.
                    This is a service target, not a guaranteed date.
                </p>
            @else
                @if ($expected['stage_due_date'])
                    <p style="margin: 0;">
                        The {{ $clock['stage']->label }} stage is expected to be done by
                        <b>{{ $expected['stage_due_date']->format('l, j F Y') }}</b>, under the CGS Client Charter.
                    </p>
                @endif
                <p class="queue-meta" style="margin: var(--space-1) 0 0;">
                    An expected decision date can't be given yet: the {{ $expected['untimed_stage']->label }}
                    stage has no Client Charter time.
                </p>
            @endif

            @if ($overdue)
                <p data-charter-overdue style="margin: var(--space-3) 0 0; padding: var(--space-2) var(--space-3); border-radius: var(--radius-sm); border: 1px solid var(--warning-border); background: var(--warning-bg); color: var(--warning-fg);">
                    This stage is taking longer than the Client Charter. You don't need to do anything; the reviewer has been reminded.
                </p>
            @endif
        </div>
    @empty
        <div class="card card-wide">
            <p class="queue-meta" style="margin: 0;">
                You have no GA Extension, Supervision or Certification applications awaiting a decision.
                Decided applications are under <a href="{{ route('applications.index') }}">My Applications</a>.
            </p>
        </div>
    @endforelse
</div>
@endsection
