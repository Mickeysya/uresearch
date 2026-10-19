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

    @if ($detail->reason)
        <p style="margin-top: 10px;"><b>Reason given:</b></p>
        <p style="white-space: pre-line;">{{ $detail->reason }}</p>
    @endif

    <p class="queue-meta" style="margin-top: 10px;">
        <a href="{{ route('hardbound-appeal.read', $application) }}"><b>Read the memo &rarr;</b></a>
        &nbsp;It is written by the portal rather than stored, so it is not in the
        documents list: it carries every signature collected so far, and yours is
        stamped into it when you endorse. It opens on a page rather than downloading.
    </p>
@else
    <p style="color: var(--text-grey);">Detail record missing for this appeal.</p>
@endif
