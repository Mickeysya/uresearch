@extends('core::layouts.guest')

{{--
    Public verification for both of Nureen's letters -- see
    CertificateVerificationController. $facts is built there, from the same
    data the letter itself was rendered from.
--}}

@section('title', $title)

@section('content')
<div class="card-container">
    <div class="card">
        <h2>{{ $title }}</h2>
        <div class="card-divider"></div>

        @if (! $found)
            <p class="message-error">
                No {{ $noun }} was found for reference "{{ $reference }}". Check the
                reference number and try again, or contact the Centre for Graduate
                Studies if you believe this is an error.
            </p>
        @else
            <p style="text-align: center; margin-bottom: var(--space-4);">
                <span style="display: inline-block; padding: 4px 14px; border-radius: var(--radius-full);
                             font-weight: var(--weight-semi); font-size: var(--text-sm);
                             background: {{ $revoked ? 'var(--danger-bg)' : 'var(--success-bg)' }};
                             color: {{ $revoked ? 'var(--danger-fg)' : 'var(--success-fg)' }};">
                    {{ $revoked ? 'Revoked' : 'Valid' }}
                </span>
            </p>

            <dl style="display: grid; grid-template-columns: max-content 1fr; gap: var(--space-2) var(--space-4); margin: 0;">
                @foreach ($facts as $label => $value)
                    <dt style="color: var(--text-grey);">{{ $label }}</dt>
                    <dd style="margin: 0;">{{ $value }}</dd>
                @endforeach
            </dl>

            @if ($revoked)
                <p class="queue-meta" style="margin-top: var(--space-4);">
                    This {{ $noun }} was revoked and should no longer be relied upon.
                </p>
            @endif
        @endif
    </div>
</div>
@endsection
