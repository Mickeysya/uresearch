{{-- Claims-specific lines inside the shared queue card. --}}
@php($detail = $details[$application->id] ?? null)

@if ($detail)
    <p><b>Total Claim:</b> RM {{ number_format($detail->total_claims, 2) }}</p>
    <p><b>Bank Name:</b> {{ $detail->bank_name }}</p>
    <p><b>Bank Account No:</b> {{ $detail->bank_account_no }}</p>
    @include('norhanis::partials.stage-estimate', ['application' => $application])
    @include('norhanis::pack._buttons', ['application' => $application])
    @include('norhanis::pack._inline_documents', ['application' => $application])
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif
