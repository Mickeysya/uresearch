@extends('core::layouts.guest')

@section('title', 'Certificate Verification')

@section('content')
<div class="card-container">
    <div class="card">
        <h2>Certificate Verification</h2>
        <div class="card-divider"></div>

        @if (! $detail)
            <p class="message-error">
                No certificate was found for reference "{{ $reference }}". Check the
                reference number and try again, or contact the Centre for Graduate
                Studies if you believe this is an error.
            </p>
        @else
            @php
                $revoked = $detail->isRevoked();
            @endphp

            <p style="text-align: center; margin-bottom: var(--space-4);">
                <span style="display: inline-block; padding: 4px 14px; border-radius: var(--radius-full);
                             font-weight: var(--weight-semi); font-size: var(--text-sm);
                             background: {{ $revoked ? 'var(--danger-bg)' : 'var(--success-bg)' }};
                             color: {{ $revoked ? 'var(--danger-fg)' : 'var(--success-fg)' }};">
                    {{ $revoked ? 'Revoked' : 'Valid' }}
                </span>
            </p>

            <dl style="display: grid; grid-template-columns: max-content 1fr; gap: var(--space-2) var(--space-4); margin: 0;">
                <dt style="color: var(--text-grey);">Reference</dt>
                <dd style="margin: 0;">{{ $detail->reference_number }}</dd>

                <dt style="color: var(--text-grey);">Student</dt>
                <dd style="margin: 0;">{{ $student?->name ?? '—' }}</dd>

                <dt style="color: var(--text-grey);">Letter Type</dt>
                <dd style="margin: 0;">
                    {{ $detail->appointment_type === 'GRA' ? 'Graduate Research Assistant' : 'Graduate Assistant' }}
                    Certification Letter
                </dd>

                <dt style="color: var(--text-grey);">Issue Date</dt>
                <dd style="margin: 0;">{{ $issuedAt?->format('j F Y') ?? '—' }}</dd>

                <dt style="color: var(--text-grey);">Endorsed By</dt>
                <dd style="margin: 0;">{{ $endorsedBy?->name ?? '—' }}</dd>
            </dl>

            @if ($revoked)
                <p class="queue-meta" style="margin-top: var(--space-4);">
                    This certificate was revoked and should no longer be relied upon.
                </p>
            @endif
        @endif
    </div>
</div>
@endsection
