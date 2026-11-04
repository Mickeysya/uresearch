@if ($event)
    <section class="wca-card" aria-labelledby="wca-h-ret">
        <h3 id="wca-h-ret">Why the appeal was returned</h3>
        <p><strong>Returned by {{ \App\Modules\Chloe\Support\CandidacyAppealPresenter::STAGES[$event->stage] ?? 'a step' }}</strong> on {{ \App\Modules\Chloe\Support\CandidacyAppealPresenter::date($event->occurred_at) }}</p>
        <p>Reasons: {{ \App\Modules\Chloe\Support\CandidacyAppealPresenter::reasonLabels($event->stage, 'returned', $event->reason_codes) ?: '—' }}</p>
        <p>Remarks: {{ $event->remark ?: '—' }}</p>
        <p class="wca-muted wca-small">Resubmit before {{ ($candidacy?->candidacy_expiry_date ?? $appeal->expiry_at_submission)?->format('j M Y') }}. After that date the appeal is closed.</p>
    </section>
@endif
