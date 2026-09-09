{{-- Travel-specific lines inside the shared queue card. --}}
@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p>Reason: {{ $detail->reason_for_travel }}</p>
    <p>Destination: {{ $detail->destination_address }}</p>
    <p>Dates: {{ $detail->travel_start_date->format('j M Y') }}
       to {{ $detail->travel_end_date->format('j M Y') }}
       ({{ $detail->duration_days }} {{ Str::plural('day', $detail->duration_days) }})</p>
    <p>Type: <b>{{ $detail->is_international ? 'International' : 'Local' }}</b>
       &middot; {{ \App\Modules\Norhanis\Models\TravelDetail::requestTypes()[$detail->type_of_request] ?? $detail->type_of_request }}
       @if ($detail->other_request_specify) — {{ $detail->other_request_specify }} @endif
    </p>
    @if ($detail->contact_person_name)
        <p>Contact: {{ $detail->contact_person_name }} {{ $detail->contact_person_no }}</p>
    @endif
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
