@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p>Current End Date: {{ $detail->current_end_date->format('j M Y') }}</p>
    <p>Requested New End Date: <b>{{ $detail->requested_new_end_date->format('j M Y') }}</b>
       ({{ $detail->current_end_date->diffInDays($detail->requested_new_end_date) }} days extra)</p>
    <p>Reason: {{ $detail->reason_for_extension }}</p>
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
