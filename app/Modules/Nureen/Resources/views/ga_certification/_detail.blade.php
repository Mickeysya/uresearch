@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p>Appointment Type: <b>{{ $detail->appointment_type }}</b></p>
    <p>Period: {{ $detail->period_start->format('j M Y') }} – {{ $detail->period_end->format('j M Y') }}</p>
    <p>Purpose: {{ $detail->purpose }}</p>
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
