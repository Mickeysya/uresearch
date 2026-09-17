{{--
    The five queues, as a list rather than a bar chart.

    Same information the chart carried, minus the four empty categories, and
    every row is a link straight into that queue. A count on its own is not
    enough -- "2 waiting" reads very differently at 3 days and at 40 -- so
    each row carries the oldest wait on it, toned the same way the queue
    screen tones its rows.
--}}
<section class="sdash-card chair-panel">
    <header class="sdash-card-head">
        <h3>Your queues</h3>
    </header>

    @if (isset($unavailable['queues']))
        @include('core::dashboard.partials.skeleton', ['type' => 'rows', 'rows' => 4])
    @elseif ($queues->isEmpty())
        <div class="empty-state">You own no approval stages.</div>
    @else
        <ul class="chair-queue-list">
            @foreach ($queues as $queue)
                @php $tone = \App\Modules\Core\Services\ChairDashboard::toneFor($queue['oldest']); @endphp
                <li>
                    <a href="{{ $queue['route'] }}" class="chair-queue-row @if ($queue['count'] === 0) is-clear @endif">
                        <span class="chair-queue-label">{{ $queue['label'] }}</span>

                        @if ($queue['oldest'] !== null)
                            <span class="chair-queue-age tone-{{ $tone }}">
                                oldest {{ $queue['oldest'] }}d
                            </span>
                        @endif

                        <span class="chair-queue-count">{{ $queue['count'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</section>
