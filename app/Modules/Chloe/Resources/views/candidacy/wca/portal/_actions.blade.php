@php
    $stageName = $stage ? \App\Modules\Chloe\Support\CandidacyAppealPresenter::STAGES[$stage] : null;
    $isCgs = $stage === 'cgs'; $isDean = $stage === 'dean';
@endphp
<section class="wca-card" aria-labelledby="wca-h-act">
    <h3 id="wca-h-act">Your action</h3>
    @if ($needsSignature)
        <div class="wca-banner is-note" role="alert">You have no signature on file. Your approval is stamped with your signature, so upload it before you decide. <a href="{{ route('candidacy.signature.show') }}">Upload your signature</a></div>
    @else
        @if ($appeal->needs_cgs_recheck && $isCgs)<p><strong>Re-check after Dean return:</strong> the Dean returned this appeal and the student revised it. Verify it again before it goes back to the Dean.</p>@endif
        @if ($isDean && $preview)
            <dl class="wca-kv">
                <dt>New expiry date preview</dt><dd>{{ $preview['new_expiry']->format('j M Y') }}</dd>
                <dt>Months that would be approved</dt><dd>{{ $appeal->requested_months }} (fixed)</dd>
                <dt>Allowance after approval</dt><dd>{{ $preview['allowance_after'] }} of {{ config('candidacy.max_extension_months') }} months</dd>
            </dl>
            <p class="wca-muted wca-small">The Dean cannot change the number of months: the approved months are the months the student requested.</p>
        @endif
        <div class="wca-actions">
            <button type="button" class="wca-btn is-primary" data-modal-open="wca-modal-approve">{{ $isCgs ? 'Verify' : 'Approve' }}</button>
            <button type="button" class="wca-btn" data-modal-open="wca-modal-return">Return to student</button>
            @if ($isDean)<button type="button" class="wca-btn" data-modal-open="wca-modal-reject">Reject (final)</button>@endif
        </div>
        @include('chloe::candidacy.wca.portal._modals')
    @endif
</section>
