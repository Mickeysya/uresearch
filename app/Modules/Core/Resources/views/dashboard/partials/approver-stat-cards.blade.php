{{--
    The five figures for an approving role with no bespoke panel of its own.

    Four are the ones every approver screen carries. The fifth is
    "Finalised by you" -- how many applications ENDED at a decision of
    theirs -- which is here rather than on the Chair's or Supervisor's
    because it is what matters most to a final approver: the Dean is the
    last stage on international travel and on an RPD appeal, so their
    approval is the one that finishes the thing.
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
            'tone' => 'green', 'icon' => 'check', 'panel' => 'decided',
            'label' => 'Decided by you',
            'value' => $decided['total'],
            'note' => $decided['rejected'] > 0
                ? $decided['rejected'].' returned · last 30 days'
                : 'last 30 days',
            'pill' => 'Last 30 days',
            'pillTone' => 'good',
            'url' => null,
        ],
        [
            'tone' => 'purple', 'icon' => 'stack', 'panel' => 'decided',
            'label' => 'Finalised by you',
            'value' => $finalised,
            'note' => 'ended at your decision',
            'pill' => 'All time',
            'pillTone' => 'info',
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
