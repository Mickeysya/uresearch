@php use App\Modules\Chloe\Support\CandidacyAppealPresenter as P; @endphp
@include('chloe::candidacy.wca.portal._actions')
@include('chloe::candidacy.wca._detail_region')

<section class="wca-card" aria-labelledby="wca-h-ctx">
    <h3 id="wca-h-ctx">Student candidacy</h3>
    <dl class="wca-kv">
        <dt>Original expiry date</dt><dd>{{ ($candidacy?->original_expiry_date ?? $appeal->expiry_at_submission)?->format('j M Y') ?? '—' }}</dd>
        <dt>Current expiry date</dt><dd>{{ $candidacy?->candidacy_expiry_date?->format('j M Y') ?? '—' }}</dd>
        <dt>Allowance used</dt><dd>{{ $candidacy?->allowanceUsed() ?? 0 }} of {{ config('candidacy.max_extension_months') }} months</dd>
    </dl>
    <h4>Earlier appeals of this student</h4>
    @forelse ($earlier as $e)
        <p>{{ $e->appeal_no }} · {{ P::statusLabel($e) }} · {{ $e->requested_months }} month(s) requested{{ $e->approved_months ? ', '.$e->approved_months.' approved' : '' }}</p>
    @empty <p class="wca-muted">None.</p> @endforelse
</section>
