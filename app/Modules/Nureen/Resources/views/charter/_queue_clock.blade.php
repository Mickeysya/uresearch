{{--
    The current stage's Client Charter due date, at the top of an approver's
    detail panel for GA Extension, Supervision and Certification. Expects
    $clocks (application id => Support\ClientCharter::stageClock()) from the
    module's queue() and $application from core::partials.queue.

    The collapsed queue row is Core's markup (core::partials.queue), not
    ours, so nothing in it can carry a due date. What this partial CAN do is
    mark an overdue row from the inside: it renders within that row's
    <details>, so `.queue-row:has(.nureen-charter-overdue)` reaches the
    collapsed summary without touching Core. If Core ever renames those
    classes the marker silently disappears, and the due date below still
    shows. See SHARED_CHANGES.md for the proper Core seam.
--}}
@php($clock = $clocks[$application->id] ?? null)

@once
    <style>
        .queue-row:has(.nureen-charter-overdue) { box-shadow: inset 3px 0 0 var(--danger-fg); }
        .queue-row:has(.nureen-charter-overdue) .queue-row-age { color: var(--danger-fg); }
        .queue-row:has(.nureen-charter-overdue) .queue-row-id::after {
            content: 'Overdue';
            margin-left: var(--space-2);
            padding: 1px var(--space-2);
            border-radius: var(--radius-full);
            background: var(--danger-bg);
            color: var(--danger-fg);
            font-size: var(--text-2xs);
            font-weight: var(--weight-semi);
            vertical-align: middle;
        }
        .nureen-charter {
            margin: 0 0 var(--space-3);
            padding: var(--space-2) var(--space-3);
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-subtle);
            background: var(--surface);
            font-size: var(--text-sm);
        }
        .nureen-charter-overdue { border-color: var(--danger-border); background: var(--danger-bg); color: var(--danger-fg); }
    </style>
@endonce

@if ($clock)
    <p class="nureen-charter {{ $clock['overdue'] ? 'nureen-charter-overdue' : '' }}">
        @if ($clock['overdue'])
            <b>Overdue.</b>
            Was due {{ $clock['due_date']->format('D j M Y') }};
            {{ $clock['working_days_overdue'] }} {{ Str::plural('working day', $clock['working_days_overdue']) }} past
            @if ($clock['on_charter'])
                the Client Charter of {{ $clock['charter_days'] }} {{ Str::plural('working day', $clock['charter_days']) }} for this stage.
            @else
                the {{ $clock['threshold_days'] }}-working-day reminder threshold (this stage has no Client Charter).
            @endif
        @elseif ($clock['on_charter'])
            Due by <b>{{ $clock['due_date']->format('D j M Y') }}</b>:
            Client Charter of {{ $clock['charter_days'] }} {{ Str::plural('working day', $clock['charter_days']) }} for this stage,
            reached {{ $clock['entered_at']->format('j M Y') }}.
        @else
            No Client Charter for this stage. A reminder goes out if it is still here after
            {{ $clock['due_date']->format('D j M Y') }}.
        @endif
    </p>
@endif
