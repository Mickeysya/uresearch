@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p>Requested Supervisor: <b>{{ $detail->requestedSupervisor->name }}</b></p>
    <p>Justification: {{ $detail->justification }}</p>
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
