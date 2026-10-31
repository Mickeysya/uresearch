@php use App\Modules\Chloe\Support\CandidacyAppealPresenter as P; use App\Modules\Chloe\Support\CandidacyPortalQuery as Q; @endphp
<section class="wca-card" aria-label="Appeals">
    <div class="wca-tabs" role="tablist">
        <a class="wca-tab {{ $tab === 'waiting' ? 'is-active' : '' }}" role="tab" aria-selected="{{ $tab === 'waiting' ? 'true' : 'false' }}" href="{{ route('candidacy.portal.queue', array_merge($query, ['tab' => 'waiting'])) }}">Waiting for my action <span class="wca-count">{{ $waitingCount }}</span></a>
        <a class="wca-tab {{ $tab === 'decided' ? 'is-active' : '' }}" role="tab" aria-selected="{{ $tab === 'decided' ? 'true' : 'false' }}" href="{{ route('candidacy.portal.queue', array_merge($query, ['tab' => 'decided'])) }}">Decided by me <span class="wca-count">{{ $decidedCount }}</span></a>
    </div>

    @if ($tab === 'waiting')
        <ul class="wca-legend" aria-label="Waiting days">
            <li><span class="wca-dot is-slate"></span>Under {{ config('candidacy.waiting_marker.gold_days') }} days</li>
            <li><span class="wca-dot is-gold"></span>{{ config('candidacy.waiting_marker.gold_days') }} days or more</li>
            <li><span class="wca-dot is-red"></span>{{ config('candidacy.waiting_marker.red_days') }} days or more</li>
        </ul>
    @endif

    @if ($rows->isEmpty())
        <p class="wca-empty">{{ $tab === 'waiting' ? 'Nothing is waiting for you.' : 'You have not decided any appeal yet.' }}</p>
    @else
        <div class="wca-table-wrap">
            <table class="wca-table is-stack">
                <thead><tr>
                    <th>Appeal No.</th><th>Student</th><th>Programme</th><th>Department</th><th class="wca-num">Requested</th><th class="wca-num">Round</th>
                    @if ($tab === 'waiting')<th>Arrived at my stage</th><th>Waiting days</th>@else<th>My decision</th><th>Status</th>@endif
                    <th></th>
                </tr></thead>
                <tbody>
                @foreach ($rows as $a)
                    @php $who = $a->identity(); $mine = $a->approvals->where('user_id', $user->id)->sortBy('id')->last(); $days = $a->waitingDays(); $mark = Q::marker($days); @endphp
                    <tr>
                        <td class="wca-nowrap" data-label="Appeal No.">{{ $a->displayNo() }}</td>
                        <td data-label="Student">{{ $who?->name ?? '—' }}<br><span class="wca-muted wca-small">{{ $who?->studentId }}</span></td>
                        <td data-label="Programme">{{ $who?->programme ?? '—' }}</td>
                        <td data-label="Department">{{ $a->department?->name ?? '—' }}</td>
                        <td class="wca-num" data-label="Requested">{{ $a->requested_months }} mo</td>
                        <td class="wca-num" data-label="Round">{{ $a->round }}</td>
                        @if ($tab === 'waiting')
                            <td class="wca-nowrap" data-label="Arrived at my stage">{{ P::date($a->stage_entered_at, 'j M Y, H:i') }}</td>
                            <td class="wca-nowrap" data-label="Waiting days">
                                <span class="wca-dot is-{{ $mark ?? 'slate' }} wca-tip" data-tip="{{ $days }} day(s) at this step"></span>{{ $days }}
                                @if ($a->needs_cgs_recheck)<br><span class="wca-tag">Re-check after Dean return</span>@endif
                            </td>
                        @else
                            <td data-label="My decision">{{ ucfirst($mine?->decision ?? '—') }}<br><span class="wca-muted wca-small">{{ P::date($mine?->decided_at, 'j M Y, H:i') }}</span></td>
                            <td data-label="Status">@include('chloe::candidacy.wca._status_badge', ['appeal' => $a])</td>
                        @endif
                        <td class="wca-nowrap" data-label="Open"><a class="wca-btn is-small {{ $tab === 'waiting' ? 'is-primary' : '' }}" href="{{ route('candidacy.portal.review', $a) }}">{{ $tab === 'waiting' ? 'Review' : 'View' }}</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
