{{--
    Four figures, fixed -- not one per queue.

    "Longest wait" is the one that earns its place: it is what a Chair is
    actually measured on and it is what the per-queue cards could never show.
    It is toned, so a number that is fine looks fine and a number that is not
    reads red without anyone having to know the threshold.
--}}
@php
    $waitTone = \App\Modules\Core\Services\ChairDashboard::toneFor($longestWait);

    $cards = [
        [
            'tone' => 'orange', 'icon' => 'clock', 'panel' => 'queues',
            'label' => 'Awaiting your decision',
            'value' => $awaitingMe,
            'note' => $awaitingMe === 0 ? 'your queues are clear' : 'across your five stages',
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
        ],
        [
            'tone' => 'green', 'icon' => 'check', 'panel' => 'decided',
            'label' => 'Decided by you',
            'value' => $decided['total'],
            'note' => $decided['rejected'] > 0
                ? $decided['rejected'].' returned · last 30 days'
                : 'last 30 days',
        ],
        [
            'tone' => 'blue', 'icon' => 'people', 'panel' => 'nominations',
            'label' => 'Panels you filed',
            'value' => $nominations->count(),
            'note' => 'examiner nominations',
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
        </div>
    @endforeach
</div>
