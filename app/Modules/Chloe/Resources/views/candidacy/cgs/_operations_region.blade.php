@php
    use App\Modules\Chloe\Support\CandidacyAppealPresenter as P;
    $base = array_diff_key($query, ['group' => 1, 'page' => 1]);
    $link = fn (?string $group, ?int $page = null) => route('candidacy.cgs.operations', array_filter($base + ['group' => $group, 'page' => $page]));
    $detail = fn ($a) => route('candidacy.appeals.show', ['appeal' => $a->id, 'return' => $returnTo]);
@endphp
<section class="wca-card" aria-label="Appeals">
    <div class="wca-tabs" role="tablist">
        @foreach (['in_progress' => 'In progress', 'returned' => 'Returned', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $key => $label)
            @php $on = ($f['group'] ?? 'all') === $key; @endphp
            <a class="wca-tab {{ $on ? 'is-active' : '' }}" role="tab" aria-selected="{{ $on ? 'true' : 'false' }}" href="{{ $link($key === 'all' ? null : $key) }}">{{ $label }} <span class="wca-count" data-live-stat="tab_{{ $key }}">{{ $counts[$key] }}</span></a>
        @endforeach
    </div>
    @include('chloe::candidacy.wca._legend')

    @if ($rows->isNotEmpty())
        <div class="wca-table-wrap">
            <table class="wca-table is-stack is-compact">
                <thead><tr>
                    <th>Appeal No.</th><th>Student</th><th>Programme</th><th>Department</th><th class="wca-num">Requested (mo)</th><th class="wca-num">Approved (mo)</th>
                    <th>Status</th><th>Current step</th><th>Submitted</th><th>Last updated</th><th>Waiting days</th><th>Action</th>
                </tr></thead>
                <tbody>
                @foreach ($rows as $a)
                    @php $who = $a->identity(); @endphp
                    <tr>
                        <td class="wca-nowrap" data-label="Appeal No.">{{ $a->displayNo() }}</td>
                        <td data-label="Student">{{ $who?->name ?? '—' }}<br><span class="wca-muted wca-small">{{ $who?->studentId }}</span></td>
                        <td data-label="Programme">{{ $who?->programme ?? '—' }}</td>
                        <td data-label="Department">{{ $a->department?->name ?? '—' }}</td>
                        <td class="wca-num" data-label="Requested (mo)">{{ $a->requested_months }}</td>
                        <td class="wca-num" data-label="Approved (mo)">{{ $a->approved_months ?? '—' }}</td>
                        <td data-label="Status">@include('chloe::candidacy.wca._status_badge', ['appeal' => $a])</td>
                        <td data-label="Current step">{{ P::currentStep($a) }}</td>
                        <td data-label="Submitted">{{ P::date($a->submitted_at) }}</td>
                        <td data-label="Last updated">{{ P::date($a->last_action_at ?? $a->updated_at, 'j M Y, H:i') }}</td>
                        <td class="wca-num" data-label="Waiting days">{{ $a->current_stage ? $a->waitingDays() : '—' }}</td>
                        <td class="wca-nowrap" data-label="Action"><a class="wca-btn is-row is-primary" href="{{ $detail($a) }}">View details</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    @include('chloe::candidacy.wca._pagination', ['page' => $f['page'], 'pages' => $pages, 'total' => $total, 'perPage' => $perPage, 'noun' => 'appeals', 'pageUrl' => fn ($n) => $link($f['group'], $n > 1 ? $n : null)])
</section>
