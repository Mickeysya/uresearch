{{--
    Five figures, fixed -- not one per queue.

    "Longest wait" is the one that earns its place: it is what a Chair is
    actually measured on and it is what the per-queue cards could never show.
    It is toned, so a number that is fine looks fine and a number that is not
    reads red without anyone having to know the threshold.

    Each card ends in a pill that goes somewhere, the same as the CGS and
    student cards: a figure you cannot act on is a poster. Where there is
    genuinely nowhere to send someone -- "decided by you" has no screen of its
    own yet -- the pill renders flat, which is the shape those two dashboards
    already use for the same case.
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
            'tone' => 'blue', 'icon' => 'people', 'panel' => 'nominations',
            'label' => 'Panels you filed',
            'value' => $nominations->count(),
            'note' => 'examiner nominations',
            'pill' => 'Nominate a panel',
            'pillTone' => 'info',
            'url' => route('appointment-letter.create'),
        ],
    ];
@endphp

<div class="sdash-stats chair-stats">
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
