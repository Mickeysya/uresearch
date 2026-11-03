@php($detail = $details[$application->id] ?? null)

@if ($detail)
    @if ($detail->attendanceRecord)
        <p>Disputed Record: period ending {{ $detail->attendanceRecord->period_end->format('j M Y') }}
            ({{ $detail->attendanceRecord->percentage }}%)</p>
        @if ($detail->sessions_requested)
            <p><b>Asks to excuse {{ $detail->sessions_requested }} {{ Str::plural('session', $detail->sessions_requested) }}</b>
                ({{ $detail->attendanceRecord->sessionsMissed() }} still missed in that period).</p>

            {{-- CGS may approve fewer than requested, never more. This form
                 replaces Core's generic Approve button in this row only (the
                 rule below); Reject, with its reason, is still Core's. --}}
            @once
                <style>
                    .queue-row:has(.nureen-appeal-approve) .decision-row button[value="approve"] { display: none; }
                    .nureen-appeal-approve { display: flex; flex-wrap: wrap; align-items: flex-end; gap: var(--space-3); margin: var(--space-3) 0; padding: var(--space-3); border: 1px solid var(--border-subtle); border-radius: var(--radius-sm); background: var(--surface); }
                    .nureen-appeal-approve label { margin: 0; }
                    .nureen-appeal-approve input[type="number"] { width: 7em; }
                    .nureen-appeal-approve .hint { flex-basis: 100%; margin: 0; font-size: var(--text-xs); color: var(--text-grey); }
                </style>
            @endonce
            <form method="POST" action="{{ route('attendance-appeal.decide', $application) }}" class="nureen-appeal-approve">
                @csrf
                <input type="hidden" name="decision" value="approve">
                <div>
                    <label for="sessions-approved-{{ $application->id }}">Sessions to approve</label>
                    <input type="number" name="sessions_approved" id="sessions-approved-{{ $application->id }}"
                           min="0" max="{{ $detail->sessions_requested }}" value="{{ $detail->sessions_requested }}" required>
                </div>
                <button type="submit">Approve appeal</button>
                <p class="hint">
                    Defaults to the {{ $detail->sessions_requested }} requested; you may approve fewer. Approved sessions are
                    {{ \App\Modules\Nureen\Models\AttendanceRecord::excusalRule() === 'exclude_from_total' ? 'taken out of the period\'s total' : 'counted as attended' }},
                    and the student's attendance and risk status are recalculated at once.
                </p>
            </form>
        @endif
    @else
        <p class="queue-meta">This appeal names no period, so approving it does not change any attendance figures.</p>
    @endif
    <p>Reason: {{ $detail->reason }}</p>

    {{-- The documents CGS requires, checked rather than taken on faith --
         same list the form and the validation use. An appeal filed before
         they were required may genuinely lack them. --}}
    @php($byType = $application->documents->groupBy('doc_type'))
    <ul style="list-style: none; margin: var(--space-2) 0 0; padding: 0;">
        @foreach (config('nureen.attendance_appeal.required_documents') as $label)
            @php($count = $byType->get($label, collect())->count())
            <li style="color: {{ $count > 0 ? 'var(--success-fg)' : 'var(--danger-fg)' }};">
                {{ $count > 0 ? '✓' : '✗' }} {{ $label }} ({{ $count > 0 ? $count.' '.Str::plural('file', $count) : 'missing' }})
            </li>
        @endforeach
    </ul>
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
