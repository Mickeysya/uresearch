{{-- Claims-specific lines inside the shared queue card. --}}
@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p><b>Total Claim:</b> RM {{ number_format($detail->total_claims, 2) }}</p>
    <p><b>Bank Name:</b> {{ $detail->bank_name }}</p>
    <p><b>Bank Account No:</b> {{ $detail->bank_account_no }}</p>

    @if ($application->documents->isNotEmpty())
        <p style="margin-top: 12px;"><b>Attachments:</b></p>
        <ul class="doc-list">
            @foreach ($application->documents as $doc)
                <li><a href="{{ $doc->url() }}">{{ $doc->doc_type }}: {{ $doc->original_name }}</a></li>
            @endforeach
        </ul>
    @endif
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
