{{-- Claims-specific lines inside the shared queue card. --}}
@php($detail = $details[$application->id] ?? null)

@if ($detail)
    @if ($publication = $detail->publication)
        <p><b>For Publication #{{ $publication->application_id }}:</b> {{ $publication->title_of_paper }}
           ({{ $publication->titleLabel() }}: {{ $publication->title_of_conference_journal }})</p>
        <p><b>{{ $publication->feeLabelText() }}:</b> {{ $publication->currency_type }} {{ number_format((float) $publication->conference_journal_fee, 2) }}
           &middot; {{ $publication->costCentreLabel() }}</p>
    @else
        <p><b>For Publication:</b> <span style="color: var(--text-grey);">not linked (filed before claims were linked to publications)</span></p>
    @endif
    <p><b>Total Claim:</b> RM {{ number_format($detail->total_claims, 2) }}</p>
    <p><b>Bank Name:</b> {{ $detail->bank_name }}</p>
    <p><b>Bank Account No:</b> {{ $detail->bank_account_no }}</p>
    @include('norhanis::partials.stage-estimate', ['application' => $application])
    @include('norhanis::pack._buttons', ['application' => $application])
    @include('norhanis::pack._inline_documents', ['application' => $application])
@else
    <p style="color: var(--text-grey);">Detail record missing for this application.</p>
@endif

@include('norhanis::partials.decision-form', ['application' => $application, 'route' => route($decideRoute, $application)])
