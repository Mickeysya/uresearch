{{-- .nureen-row: the wrapper components/decision-bar keys on. --}}
<div class="nureen-row">
@php($detail = $details[$application->id] ?? null)

@if ($detail)
    @if ($detail->attendanceRecord)
        <p>Disputed Record: period ending {{ $detail->attendanceRecord->period_end->format('j M Y') }}
            ({{ $detail->attendanceRecord->percentage }}%)</p>
        @if ($detail->sessions_requested)
            <p><b>Asks to excuse {{ $detail->sessions_requested }} {{ Str::plural('session', $detail->sessions_requested) }}</b>
                ({{ $detail->attendanceRecord->sessionsMissed() }} still missed in that period).</p>

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

{{-- System checks (the required documents, above) → Documents → the
     sessions to excuse → Remarks → actions. CGS may approve fewer
     sessions than requested, never more (AttendanceAppealController::decide()). --}}
@php($excuses = $detail?->attendanceRecord && $detail->sessions_requested)
@php($panel = \App\Modules\Nureen\Support\ApproverPanel::attendanceAppeal($application, auth()->user(), (bool) $excuses))
<x-nureen::documents :items="$panel['documents']" />
<x-nureen::decision-bar :application="$application" :route="route('attendance-appeal.decide', $application)"
    :approve-label="$panel['approveLabel']" :hint="$panel['hint']">
    @if ($excuses)
        <div class="nureen-appeal-sessions">
            <label for="sessions-approved-{{ $application->id }}">Sessions to excuse if approved</label>
            <input type="number" name="sessions_approved" id="sessions-approved-{{ $application->id }}"
                   min="0" max="{{ $detail->sessions_requested }}" value="{{ $detail->sessions_requested }}"
                   aria-describedby="sessions-approved-hint-{{ $application->id }}">
            <p class="queue-meta" id="sessions-approved-hint-{{ $application->id }}">
                Defaults to the {{ $detail->sessions_requested }} requested; you may approve fewer. Approved sessions are
                {{ \App\Modules\Nureen\Models\AttendanceRecord::excusalRule() === 'exclude_from_total' ? 'taken out of the period\'s total' : 'counted as attended' }}.
            </p>
        </div>
    @endif
</x-nureen::decision-bar>
</div>
