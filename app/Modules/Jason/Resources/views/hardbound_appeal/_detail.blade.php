@php
    $detail = $details[$application->id] ?? null;
@endphp

@if ($detail)
    <p>
        Asking to submit the hardbound thesis by
        <b>{{ $detail->requested_until?->format('j F Y') }}</b>
        @if ($detail->original_deadline)
            , instead of {{ $detail->original_deadline->format('j F Y') }}
        @endif
    </p>

    <p style="margin-top: 10px;"><b>Reason given:</b></p>
    <p style="white-space: pre-line;">{{ $detail->reason }}</p>

    <p class="queue-meta" style="margin-top: 10px;">
        The memo itself is in the documents above. It carries every endorsement
        collected so far, and is re-issued with yours when you endorse it.
    </p>
@else
    <p style="color: var(--text-grey);">Detail record missing for this appeal.</p>
@endif
