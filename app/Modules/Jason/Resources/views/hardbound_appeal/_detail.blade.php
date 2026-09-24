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

    <p class="queue-meta" style="margin-top: 10px;">
        The candidate's own memo is in the documents above, and says why in their
        own words. Your endorsement is stamped onto the endorsement slip that
        travels with it, which is re-issued each time it is endorsed.
    </p>
@else
    <p style="color: var(--text-grey);">Detail record missing for this appeal.</p>
@endif
