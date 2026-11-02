@php
    use App\Modules\Chloe\Support\CandidacyAppealPresenter as P;

    $used = $candidacy?->allowanceUsed() ?? 0;
    $left = $candidacy?->remainingAppealMonths() ?? 0;
    $days = $candidacy?->daysUntilExpiry();
    $canStart = $candidacy && count($problems) === 0;
    $statusTone = match ($candidacy?->status) { 'active' => 'green', 'dismissed' => 'red', default => 'slate' };
@endphp

@if (! $candidacy)
    <div class="wca-card"><p class="wca-empty">No study candidacy record was found for your account. Please contact the Centre for Graduate Studies.</p></div>
@else
    <div class="wca-grid">
        <section class="wca-card" aria-labelledby="wca-h-cand">
            <h3 id="wca-h-cand">Candidacy</h3>
            <p><span class="wca-status"><span class="wca-dot is-{{ $statusTone }}"></span>{{ \App\Modules\Chloe\Models\StudyCandidacy::statuses()[$candidacy->status] ?? ucfirst($candidacy->status) }}</span></p>
            <dl class="wca-kv">
                <dt>Programme started</dt><dd>{{ $candidacy->programme_start_date?->format('j M Y') ?? '—' }}</dd>
                <dt>Original expiry date</dt><dd>{{ ($candidacy->original_expiry_date ?? $candidacy->candidacy_expiry_date)->format('j M Y') }}</dd>
                <dt>Current expiry date</dt><dd>{{ $candidacy->candidacy_expiry_date->format('j M Y') }}</dd>
                <dt>Days remaining</dt><dd>{{ $days >= 0 ? $days : 'Expired '.abs($days).' day(s) ago' }}</dd>
            </dl>
        </section>

        <section class="wca-card" aria-labelledby="wca-h-allow">
            <h3 id="wca-h-allow">Extension allowance</h3>
            <div class="wca-progress" role="img" aria-label="{{ $used }} of {{ $max }} months used"><span class="wca-w-{{ min($max, $used) }}"></span></div>
            <p><strong>{{ $used }} of {{ $max }} months used</strong> &middot; {{ $left }} month(s) left</p>
            @if ($used > 0 || $submittedCount > 1)
                <p class="wca-muted wca-small">Appeal {{ max(1, $submittedCount + ($draft ? 1 : 0)) }} &middot; {{ $used }} of {{ $max }} months used</p>
            @endif
            <p class="wca-muted wca-small">The total extension across all appeals can never exceed {{ $max }} months.</p>
        </section>

        <section class="wca-card" aria-labelledby="wca-h-next">
            <h3 id="wca-h-next">What happens next</h3>
            @if ($open && $open->status === 'returned')
                <p>Your appeal <strong>{{ $open->appeal_no }}</strong> was returned to you. Revise it and resubmit before {{ $candidacy->candidacy_expiry_date->format('j M Y') }}.</p>
            @elseif ($open)
                <p>Your appeal <strong>{{ $open->appeal_no }}</strong> is with: {{ P::currentStep($open) }}. You will be notified of every decision.</p>
            @elseif ($draft)
                <p>You have a draft appeal. Continue it and submit when it is complete.</p>
            @elseif ($canStart)
                <p>You may submit an appeal for an extension of 1 to {{ $left }} month(s).</p>
            @else
                <p>An appeal cannot be started now:</p>
                <ul>@foreach ($problems as $problem)<li>{{ $problem }}</li>@endforeach</ul>
            @endif

            <div class="wca-actions">
                @if ($open && $open->status === 'returned')
                    <a class="wca-btn is-primary" href="{{ route('candidacy.appeals.edit', $open) }}">Edit and resubmit</a>
                @elseif ($open)
                    <a class="wca-btn" href="{{ route('candidacy.appeals.show', ['appeal' => $open->id, 'return' => '/candidacy']) }}">View appeal</a>
                @elseif ($draft)
                    <a class="wca-btn is-primary" href="{{ route('candidacy.appeals.draft', ['step' => 1]) }}">Continue draft</a>
                @elseif ($canStart)
                    <a class="wca-btn is-primary" href="{{ route('candidacy.appeals.new') }}">Submit an Appeal</a>
                @else
                    <span class="wca-btn" aria-disabled="true">Submit an Appeal</span>
                @endif
            </div>
        </section>
    </div>

    <section class="wca-card" aria-labelledby="wca-h-appeals">
        <h3 id="wca-h-appeals">My Appeals</h3>
        @include('chloe::candidacy.wca._legend')

        @if ($appeals->isEmpty())
            <p class="wca-empty">You have not started an appeal.</p>
        @else
            <div class="wca-table-wrap">
                <table class="wca-table is-stack is-compact">
                    <thead><tr>
                        <th>Appeal No.</th><th>Submitted</th><th class="wca-num">Requested (months)</th><th class="wca-num">Approved (months)</th>
                        <th>Status</th><th>Current step (who)</th><th>Last updated</th><th>Action</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($appeals as $appeal)
                        <tr>
                            <td class="wca-nowrap" data-label="Appeal No.">{{ $appeal->displayNo() }}</td>
                            <td class="wca-nowrap" data-label="Submitted">{{ P::date($appeal->submitted_at) }}</td>
                            <td class="wca-num" data-label="Requested (months)">{{ $appeal->requested_months ?? '—' }}</td>
                            <td class="wca-num" data-label="Approved (months)">{{ $appeal->approved_months ?? '—' }}</td>
                            <td data-label="Status">@include('chloe::candidacy.wca._status_badge', ['appeal' => $appeal])</td>
                            <td data-label="Current step (who)">{{ P::currentStep($appeal) }}</td>
                            <td class="wca-nowrap" data-label="Last updated">{{ P::date($appeal->last_action_at ?? $appeal->updated_at, 'j M Y, H:i') }}</td>
                            <td class="wca-nowrap" data-label="Action">
                                @if ($appeal->isDraft())
                                    <a class="wca-btn is-row is-primary" href="{{ route('candidacy.appeals.draft', ['step' => 1]) }}">Continue</a>
                                @else
                                    <a class="wca-btn is-row is-long" href="{{ route('candidacy.appeals.show', ['appeal' => $appeal->id, 'return' => '/candidacy']) }}">View full details &rarr;</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($reminders->isNotEmpty())
        <section class="wca-card" aria-labelledby="wca-h-rem">
            <h3 id="wca-h-rem">Expiry reminders sent to you</h3>
            <ul class="wca-list">
                @foreach ($reminders as $reminder)
                    <li><span>{{ $reminder->milestone }} month(s) before {{ $reminder->expiry_date->format('j M Y') }}</span><span class="wca-muted">{{ $reminder->sent_at?->setTimezone('Asia/Kuala_Lumpur')->format('j M Y') }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif
@endif
