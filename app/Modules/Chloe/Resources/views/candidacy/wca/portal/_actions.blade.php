@php
    $stageName = $stage ? \App\Modules\Chloe\Support\CandidacyAppealPresenter::STAGES[$stage] : null;
    $isCgs = $stage === 'cgs'; $isDean = $stage === 'dean';
@endphp
<section class="wca-card" aria-labelledby="wca-h-act">
    <h3 id="wca-h-act">Your action</h3>
    @if ($needsSignature)
        <div class="wca-banner is-note" role="alert">You have no signature on file. Your approval is stamped with your signature, so upload it before you decide. <a href="{{ route('candidacy.signature.show', ['return' => \App\Modules\Chloe\Support\CandidacyReturnPath::current(request(), '/candidacy/appeals/'.$appeal->id, ['return' => $return])]) }}">Upload your signature</a></div>
    @else
        @if ($appeal->needs_cgs_recheck && $isCgs)<p><strong>Re-check after Dean return:</strong> the Dean returned this appeal and the student revised it. Verify it again before it goes back to the Dean.</p>@endif
        @if ($isDean && $preview)
            <dl class="wca-kv">
                <dt>Months requested</dt><dd>{{ $appeal->requested_months }}</dd>
                <dt>Months remaining in the allowance</dt><dd>{{ $candidacy?->remainingAppealMonths() ?? 0 }}</dd>
                <dt>New expiry date preview</dt><dd>{{ $preview['new_expiry']->format('j M Y') }} (if approved as requested)</dd>
                <dt>Allowance after approval</dt><dd>{{ $preview['allowance_after'] }} of {{ config('candidacy.max_extension_months') }} months (if approved as requested)</dd>
            </dl>
            <p class="wca-muted wca-small">You may approve a different number of months, from 1 up to the months remaining. A short reason is then required; the student does not see it.</p>
        @endif
        <div class="wca-actions">
            <button type="button" class="wca-btn is-primary" data-modal-open="wca-modal-approve">{{ $isCgs ? 'Verify' : 'Approve' }}</button>
            <button type="button" class="wca-btn" data-modal-open="wca-modal-return">Return to student</button>
            @if ($isDean)<button type="button" class="wca-btn" data-modal-open="wca-modal-reject">Reject (final)</button>@endif
        </div>
        @include('chloe::candidacy.wca.portal._modals')
    @endif
</section>
