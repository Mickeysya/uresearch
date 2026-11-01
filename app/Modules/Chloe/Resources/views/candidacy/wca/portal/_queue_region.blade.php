@php use App\Modules\Chloe\Support\CandidacyAppealPresenter as P; use App\Modules\Chloe\Support\CandidacyPortalQuery as Q;
    $tabNames = ['pending' => 'Pending my action', 'ongoing' => 'Ongoing', 'history' => 'History', 'decided' => 'Decided by me'];
    $detail = fn ($a) => route('candidacy.appeals.show', ['appeal' => $a->id, 'return' => $returnTo]);
@endphp
<section class="wca-card" aria-label="Appeals">
    <div class="wca-tabs" role="tablist">
        @foreach ($tabs as $t)
            <a class="wca-tab {{ $tab === $t ? 'is-active' : '' }}" role="tab" aria-selected="{{ $tab === $t ? 'true' : 'false' }}" href="{{ route('candidacy.portal.queue', array_merge($query, ['tab' => $t === 'pending' ? null : $t])) }}">{{ $tabNames[$t] }} <span class="wca-count">{{ $counts[$t] }}</span></a>
        @endforeach
        @if ($isCgs)
            <a class="wca-tabs-end" href="{{ route('candidacy.cgs.operations') }}">View all appeals &rarr;</a>
        @endif
    </div>

    @if ($tab === 'pending')
        <ul class="wca-legend" aria-label="Waiting days">
            <li><span class="wca-dot is-slate"></span>Under {{ config('candidacy.waiting_marker.gold_days') }} days</li>
            <li><span class="wca-dot is-gold"></span>{{ config('candidacy.waiting_marker.gold_days') }} days or more</li>
            <li><span class="wca-dot is-red"></span>{{ config('candidacy.waiting_marker.red_days') }} days or more</li>
        </ul>
    @else
        @include('chloe::candidacy.wca._legend')
    @endif

    @if ($rows->isEmpty())
        <p class="wca-empty">{{ ['pending' => 'Nothing is pending your action.', 'ongoing' => 'No ongoing appeals.', 'history' => 'No appeals with a final result yet.', 'decided' => 'You have not decided any appeal yet.'][$tab] }}</p>
    @else
        <div class="wca-table-wrap">
            <table class="wca-table is-stack">
                <thead><tr>
                    <th>Appeal No.</th><th>Student</th><th>Programme</th><th>Department</th><th class="wca-num">Requested</th><th class="wca-num">Round</th>
                    @if ($tab === 'pending')<th>Arrived at my stage</th><th>Waiting days</th>
                    @elseif ($tab === 'ongoing')<th>Current step</th><th>Last updated</th>
                    @elseif ($tab === 'history')<th>My decision</th><th>Final outcome</th>
                    @else<th>My decision</th><th>Status</th>@endif
                    <th></th>
                </tr></thead>
                <tbody>
                @foreach ($rows as $a)
                    @php $who = $a->identity(); $days = $a->waitingDays(); $mark = Q::marker($days); @endphp
                    <tr>
                        <td class="wca-nowrap" data-label="Appeal No.">{{ $a->displayNo() }}</td>
                        <td data-label="Student">{{ $who?->name ?? '—' }}<br><span class="wca-muted wca-small">{{ $who?->studentId }}</span></td>
                        <td data-label="Programme">{{ $who?->programme ?? '—' }}</td>
                        <td data-label="Department">{{ $a->department?->name ?? '—' }}</td>
                        <td class="wca-num" data-label="Requested">{{ $a->requested_months }} mo</td>
                        <td class="wca-num" data-label="Round">{{ $a->round }}</td>
                        @if ($tab === 'pending')
                            <td class="wca-nowrap" data-label="Arrived at my stage">{{ P::date($a->stage_entered_at, 'j M Y, H:i') }}</td>
                            <td class="wca-nowrap" data-label="Waiting days">
                                <span class="wca-dot is-{{ $mark ?? 'slate' }} wca-tip" data-tip="{{ $days }} day(s) at this step"></span>{{ $days }}
                                @if ($a->needs_cgs_recheck)<br><span class="wca-tag">Re-check after Dean return</span>@endif
                            </td>
                        @elseif ($tab === 'ongoing')
                            <td data-label="Current step">{{ P::currentStep($a) }}</td>
                            <td class="wca-nowrap" data-label="Last updated">{{ P::date($a->last_action_at ?? $a->updated_at, 'j M Y, H:i') }}</td>
                        @elseif ($tab === 'history')
                            <td data-label="My decision">{{ Q::myDecision($user, $a) }}</td>
                            <td data-label="Final outcome">@include('chloe::candidacy.wca._status_badge', ['appeal' => $a])</td>
                        @else
                            @php $mine = $a->approvals->where('user_id', $user->id)->sortBy('id')->last(); @endphp
                            <td data-label="My decision">{{ ucfirst($mine?->decision ?? '—') }}<br><span class="wca-muted wca-small">{{ P::date($mine?->decided_at, 'j M Y, H:i') }}</span></td>
                            <td data-label="Status">@include('chloe::candidacy.wca._status_badge', ['appeal' => $a])</td>
                        @endif
                        <td class="wca-nowrap" data-label="Open"><a class="wca-btn is-small is-primary" href="{{ $detail($a) }}">{{ $tab === 'pending' ? 'Review' : 'View details' }}</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
