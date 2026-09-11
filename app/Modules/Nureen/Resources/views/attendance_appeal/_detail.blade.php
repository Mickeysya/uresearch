@php($detail = $details[$application->id] ?? null)

@if ($detail)
    @if ($detail->attendanceRecord)
        <p>Disputed Record: period ending {{ $detail->attendanceRecord->period_end->format('j M Y') }}
            ({{ $detail->attendanceRecord->percentage }}%)</p>
    @endif
    <p>Reason: {{ $detail->reason }}</p>
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
