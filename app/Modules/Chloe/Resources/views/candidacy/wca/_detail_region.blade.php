@if ($canAct ?? false)
    @include('chloe::candidacy.wca.portal._actions')
@endif

@php
    use App\Modules\Chloe\Support\CandidacyAppealPresenter as P;
    use App\Modules\Chloe\Models\CandidacyAppealDetail;

    $steps = P::stepBar($appeal);
    $used = $candidacy?->allowanceUsed() ?? 0;
    $max = (int) config('candidacy.max_extension_months');
    $last = $appeal->events->where('action', 'returned')->last();
@endphp

<section class="wca-card" aria-labelledby="wca-h-status">
    <div class="wca-head-row">
        <h3 id="wca-h-status">Status</h3>
        @include('chloe::candidacy.wca._status_badge', ['appeal' => $appeal])
    </div>
    <p class="wca-muted">Submitted {{ P::date($appeal->submitted_at, 'j M Y, H:i') }} &middot; Round {{ $appeal->round }} &middot; Current step: {{ P::currentStep($appeal) }}</p>
    @if ($appeal->needs_cgs_recheck && $appeal->status === 'with_cgs')<p><strong>Re-check after Dean return:</strong> CGS checks the revised appeal before it goes back to the Dean.</p>@endif

    <ol class="wca-chain" aria-label="Approval steps">
        @foreach ($steps as $s)
            <li class="is-{{ $s['state'] }}">
                <span class="wca-chain-name">{{ $s['label'] }}</span>
                <span class="wca-chain-line">{{ $s['person'] ?? '—' }}</span>
                <span class="wca-chain-line">{{ P::stateLabel($s['state']) }}@if ($s['waiting'] !== null) &middot; {{ $s['waiting'] }} day(s)@endif</span>
                @if ($s['date'])<span class="wca-chain-line">{{ P::date($s['date'], 'j M Y, H:i') }}</span>@endif
                @if ($s['state'] === 'returned')<span class="wca-chain-line wca-strong">Returned the appeal to the student</span>@endif
            </li>
        @endforeach
    </ol>
    <ul class="wca-legend wca-legend-top" aria-label="Step colours">
        <li><span class="wca-dot is-green"></span>Done</li><li><span class="wca-dot is-gold"></span>Waiting here</li>
        <li><span class="wca-dot is-orange"></span>Returned the appeal</li><li><span class="wca-dot is-red"></span>Rejected</li><li><span class="wca-dot is-slate"></span>Not reached yet</li>
    </ul>

    <div class="wca-actions">
        @if ($canEdit && $appeal->status === 'returned')<a class="wca-btn is-primary" href="{{ route('candidacy.appeals.edit', $appeal) }}">Edit and resubmit</a>@endif
        @if ($canMemo)<a class="wca-btn is-primary" href="{{ route('candidacy.appeals.memo', $appeal) }}">Download memo</a>@endif
    </div>
</section>

@if ($canEdit && $appeal->status === 'returned' && $last)
    <section class="wca-card" aria-labelledby="wca-h-ret">
        <h3 id="wca-h-ret">Returned to you</h3>
        <p>By {{ P::STAGES[$last->stage] ?? 'a step' }} on {{ P::date($last->occurred_at, 'j M Y, H:i') }}</p>
        <p>Reasons: {{ P::reasonLabels($last->stage, 'returned', $last->reason_codes) ?: '—' }}</p>
        <p>Remarks: {{ $last->remark ?: '—' }}</p>
    </section>
@endif

@if ($appeal->status === 'rejected')
    @php $rej = $appeal->events->where('action', 'rejected')->last(); $rejReasons = P::reasonLabels('dean', 'rejected', $rej?->reason_codes); @endphp
    <section class="wca-card" aria-labelledby="wca-h-rej">
        <h3 id="wca-h-rej">Reasons for rejection</h3>
        <p>{{ $rejReasons !== '' ? $rejReasons : 'Not recorded' }}</p>
        @if ($rej?->remark)<p class="wca-muted">Remark: {{ $rej->remark }}</p>@endif
        <p class="wca-muted wca-small">This decision is final. No further appeals are accepted for this candidacy; the expiry date stays at {{ ($candidacy?->candidacy_expiry_date ?? $appeal->expiry_at_submission)?->format('j M Y') ?? 'its current date' }}. The student may still submit the Softbound thesis before the expiry date.</p>
    </section>
@endif

<div class="wca-grid wca-grid-2">
    <section class="wca-card" aria-labelledby="wca-h-dec">
        <h3 id="wca-h-dec">Decision summary</h3>
        <dl class="wca-kv">
            <dt>Months requested</dt><dd>{{ $appeal->requested_months ?? '—' }}</dd>
            <dt>Months approved</dt><dd>{{ $appeal->approved_months ?? '—' }}</dd>
            @if (($showContext ?? false) && $appeal->wasAdjustedByDean())
                <dt>Dean's reason for the change</dt><dd>{{ $appeal->dean_adjust_reason ?: '—' }}</dd>
            @endif
            <dt>Original expiry date</dt><dd>{{ ($candidacy?->original_expiry_date ?? $appeal->expiry_at_submission)?->format('j M Y') ?? '—' }}</dd>
            <dt>{{ $appeal->status === 'approved' ? 'New expiry date' : 'Current expiry date' }}</dt><dd>{{ ($appeal->new_expiry_date ?? $candidacy?->candidacy_expiry_date ?? $appeal->expiry_at_submission)?->format('j M Y') ?? '—' }}</dd>
            <dt>Allowance used</dt><dd>{{ $used }} of {{ $max }} months</dd>
        </dl>
    </section>
    <section class="wca-card" aria-labelledby="wca-h-a">
        <h3 id="wca-h-a">Section A: Research progress</h3>
        <dl class="wca-kv">
            <dt>Current phase</dt><dd>{{ CandidacyAppealDetail::phases()[$appeal->phase] ?? '—' }}</dd>
            <dt>Thesis written</dt><dd>{{ $appeal->percent_complete !== null ? $appeal->percent_complete.'%' : '—' }}</dd>
            <dt>RCS status</dt><dd>{{ $appeal->rcs_status === 'done' ? 'Done' : ($appeal->rcs_status === 'pending' ? 'Pending' : '—') }}</dd>
            <dt>RCS date</dt><dd>{{ ($appeal->rcs_status === 'pending' ? $appeal->rcs_expected_date : $appeal->rcs_date)?->format('j M Y') ?? '—' }}</dd>
            <dt>RCS category</dt><dd>{{ $appeal->rcs_category ?: '—' }}</dd>
        </dl>
    </section>
    <section class="wca-card" aria-labelledby="wca-h-b">
        <h3 id="wca-h-b">Section B: Earlier extensions</h3>
        <dl class="wca-kv"><dt>Through GSC</dt><dd>{{ $appeal->gsc_extension ? 'Yes' : 'No' }}</dd><dt>Through VC</dt><dd>{{ $appeal->vc_extension ? 'Yes' : 'No' }}</dd></dl>
    </section>
    <section class="wca-card" aria-labelledby="wca-h-c">
        <h3 id="wca-h-c">Section C: Publications</h3>
        @forelse ($appeal->publications as $p)
            <p>{{ $p->title ?? $p->description }}<br><span class="wca-muted wca-small">{{ collect([$p->venue, $p->status, $p->year])->filter()->implode(' · ') }}</span></p>
        @empty <p class="wca-muted">None listed.</p> @endforelse
    </section>
</div>

<section class="wca-card" aria-labelledby="wca-h-d">
    <h3 id="wca-h-d">Section D: Reason for the appeal</h3>
    <p>{!! nl2br(e($appeal->reason ?: '—')) !!}</p>
    <p class="wca-muted wca-small">Declaration {{ $appeal->declaration_accepted_at ? 'accepted on '.P::date($appeal->declaration_accepted_at) : 'not accepted' }}</p>
</section>

<section class="wca-card" aria-labelledby="wca-h-docs">
    <h3 id="wca-h-docs">Documents</h3>
    @if ($appeal->documents->isEmpty())<p class="wca-muted">No documents.</p>@else
        <ul class="wca-list">
            @foreach ($appeal->documents->sortBy('id') as $doc)
                <li><span>{{ $doc->kind === 'official_form' ? 'Official appeal form' : 'Supporting' }}: {{ $doc->original_name }} <span class="wca-muted wca-small">(round {{ $doc->round_uploaded }})</span></span>
                    <span><x-core::doc-link class="wca-btn is-small" data-region-swap-ignore
                            :url="route('candidacy.appeals.documents.download', [$appeal, $doc, 'inline' => 1])"
                            :download="route('candidacy.appeals.documents.download', [$appeal, $doc])"
                            :name="$doc->original_name" :kind="\App\Modules\Core\Support\FilePreview::kind($doc->original_name)" />
                    <a class="wca-btn is-small" href="{{ route('candidacy.appeals.documents.download', [$appeal, $doc]) }}">Download</a></span></li>
            @endforeach
        </ul>
    @endif
</section>

<div class="wca-grid wca-grid-2">
    <section class="wca-card" aria-labelledby="wca-h-rem">
        <h3 id="wca-h-rem">Remarks and reasons</h3>
        @forelse (P::remarks($appeal) as $e)
            <p><strong>{{ P::eventLabel($e) }}</strong> &middot; {{ $e->actor_name }} &middot; {{ P::date($e->occurred_at, 'j M Y, H:i') }}<br>
                @if ($e->reason_codes)Reasons: {{ P::reasonLabels($e->stage, $e->action, $e->reason_codes) }}<br>@elseif ($e->action === 'rejected')Reasons: Not recorded<br>@endif
                {{ $e->remark ? 'Remark: '.$e->remark : '' }}</p>
        @empty <p class="wca-muted">No remarks yet.</p> @endforelse
    </section>
    <section class="wca-card" aria-labelledby="wca-h-hist">
        <h3 id="wca-h-hist">History</h3>
        <ul class="wca-timeline">
            @foreach (P::timeline($appeal) as $e)
                @php $tone = match ($e->action) { 'approved', 'verified' => 'green', 'returned' => 'orange', 'rejected' => 'red', 'submitted', 'resubmitted' => 'blue', default => 'slate' }; @endphp
                <li class="is-{{ $tone }}"><strong>{{ P::eventLabel($e) }}</strong><br><span class="wca-muted wca-small">{{ $e->actor_name }} &middot; {{ P::date($e->occurred_at, 'j M Y, H:i') }}</span>
                    @if (in_array($e->action, ['returned', 'rejected'], true))<br><span class="wca-small">Reasons: {{ P::reasonLabels($e->stage, $e->action, $e->reason_codes) ?: 'Not recorded' }}</span>@endif
                    @if (($showContext ?? false) && $e->stage === 'dean' && $e->action === 'approved' && $appeal->wasAdjustedByDean())<br><span class="wca-small">Requested {{ $appeal->requested_months }} month(s), approved {{ $appeal->approved_months }}. Reason: {{ $appeal->dean_adjust_reason ?: '—' }}</span>@endif</li>
            @endforeach
        </ul>
        <ul class="wca-legend" aria-label="History colours"><li><span class="wca-dot is-blue"></span>Submitted</li><li><span class="wca-dot is-green"></span>Approved</li><li><span class="wca-dot is-orange"></span>Returned</li><li><span class="wca-dot is-red"></span>Rejected</li><li><span class="wca-dot is-slate"></span>Other</li></ul>
    </section>
</div>

@if ($showContext ?? false)
<section class="wca-card" aria-labelledby="wca-h-ctx">
    <h3 id="wca-h-ctx">Student candidacy</h3>
    <dl class="wca-kv">
        <dt>Original expiry date</dt><dd>{{ ($candidacy?->original_expiry_date ?? $appeal->expiry_at_submission)?->format('j M Y') ?? '—' }}</dd>
        <dt>Current expiry date</dt><dd>{{ $candidacy?->candidacy_expiry_date?->format('j M Y') ?? '—' }}</dd>
        <dt>Allowance used</dt><dd>{{ $candidacy?->allowanceUsed() ?? 0 }} of {{ config('candidacy.max_extension_months') }} months</dd>
        @if ($studentStatus)
            @php $demoTag = fn ($key) => $studentStatus->sources[$key] === 'overlay' ? ' · Demo data' : ''; @endphp
            <dt>Softbound</dt><dd>{{ $studentStatus->softboundSubmitted ? 'Submitted'.($studentStatus->softboundDate ? ' '.$studentStatus->softboundDate->format('j M Y') : '') : 'Not submitted' }}{{ $demoTag('softbound') }}</dd>
            <dt>Completed</dt><dd>{{ $studentStatus->completed ? 'Yes' : 'No' }}{{ $demoTag('completed') }}</dd>
            <dt>Inactive (including deferred)</dt><dd>{{ $studentStatus->inactive ? 'Yes' : 'No' }}{{ $demoTag('inactive') }}</dd>
            <dt>Dismissed</dt><dd>{{ $studentStatus->dismissed ? 'Yes' : 'No' }}</dd>
        @endif
    </dl>
    <h4>Earlier appeals of this student</h4>
    @forelse ($earlier as $e)
        <p>{{ $e->appeal_no }} · {{ P::statusLabel($e) }} · {{ $e->requested_months }} month(s) requested{{ $e->approved_months ? ', '.$e->approved_months.' approved' : '' }}</p>
    @empty <p class="wca-muted">None.</p> @endforelse
</section>
@endif
