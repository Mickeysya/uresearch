{{--
    Five figures, fixed -- not one per queue, which for a supervisor would be
    seven cards mostly reading zero.

    Two are about the desk and two are about the people, which is the split
    that makes this screen a supervisor's rather than a generic approver's.
    Every card ends in a pill that goes somewhere; where there is genuinely
    nowhere to send someone the pill renders flat, the same shape the CGS and
    student cards already use.
--}}
@php
    $waitTone = \App\Modules\Core\Services\ApproverDashboard::toneFor($longestWait);

    $cards = [
        [
            'tone' => 'orange', 'icon' => 'clock', 'panel' => 'queues',
            'label' => 'Awaiting your decision',
            'value' => $awaitingMe,
            'note' => $awaitingMe === 0 ? 'your queues are clear' : 'across your stages',
            'pill' => $awaitingMe === 0 ? 'Nothing waiting' : 'Open the queue',
            'pillTone' => $awaitingMe === 0 ? 'good' : 'warn',
            'url' => $busiestRoute,
        ],
        [
            'tone' => $waitTone === 'critical' ? 'red' : ($waitTone === 'warn' ? 'orange' : 'green'),
            'icon' => 'clock', 'panel' => 'queues',
            'label' => 'Longest wait',
            'value' => $longestWait === null ? '0' : $longestWait,
            'suffix' => $longestWait === null ? '' : ' ' . Str::plural('day', $longestWait),
            'note' => match (true) {
                $longestWait === null => 'nothing is waiting',
                $waitTone === 'critical' => 'well past the 30-day mark',
                $waitTone === 'warn' => 'past a fortnight',
                default => 'within a fortnight',
            },
            'pill' => 'Go to the oldest',
            'pillTone' => $waitTone === 'critical' ? 'critical' : ($waitTone === 'warn' ? 'warn' : 'good'),
            'url' => $longestWaitRoute,
        ],
        [
            'tone' => $overdue > 0 ? 'red' : 'green',
            'icon' => $overdue > 0 ? 'warning' : 'check',
            'panel' => 'ageing',
            'label' => 'Overdue',
            'value' => $overdue,
            'note' => $overdue === 0 ? 'nothing past a fortnight' : 'waiting over a fortnight',
            'pill' => $overdue === 0 ? 'All on time' : 'Clear the backlog',
            'pillTone' => $overdue === 0 ? 'good' : 'critical',
            'url' => $overdue > 0 ? $longestWaitRoute : null,
        ],
        [
            'tone' => 'blue', 'icon' => 'people', 'panel' => 'candidates',
            'label' => 'My candidates',
            'value' => $candidateCount,
            'note' => $candidateCount === 1 ? 'student under your supervision' : 'students under your supervision',
            'pill' => 'Nominate examiners',
            'pillTone' => 'info',
            'url' => route('examiner-nomination.create'),
        ],
        [
            'tone' => $atRisk > 0 ? 'red' : 'green', 'icon' => $atRisk > 0 ? 'cross' : 'check',
            'panel' => 'candidates',
            'label' => 'Flagged at risk',
            'value' => $atRisk,
            'note' => $atRisk === 0 ? 'nobody is flagged' : 'on attendance',
            'pill' => $atRisk === 0 ? 'All clear' : 'See who',
            'pillTone' => $atRisk === 0 ? 'good' : 'critical',
            'url' => null,
        ],
    ];
@endphp

<div class="sdash-stats approver-stats">
    @foreach ($cards as $card)
        <div class="sdash-stat tone-{{ $card['tone'] }}">
            @if (isset($unavailable[$card['panel']]))
                @include('core::dashboard.partials.skeleton', ['type' => 'stat'])
            @endif

            <div class="sdash-stat-top">
                <span class="sdash-stat-icon" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => $card['icon']])
                </span>
                <span class="sdash-stat-label">{{ $card['label'] }}</span>
            </div>

            <div class="sdash-stat-value">{{ $card['value'] }}{{ $card['suffix'] ?? '' }}</div>
            <div class="sdash-stat-note">{{ $card['note'] }}</div>

            @if ($card['url'])
                <a href="{{ $card['url'] }}" class="sdash-pill pill-{{ $card['pillTone'] }}">{{ $card['pill'] }}</a>
            @else
                <span class="sdash-pill pill-{{ $card['pillTone'] }}">{{ $card['pill'] }}</span>
            @endif
        </div>
    @endforeach
</div>
