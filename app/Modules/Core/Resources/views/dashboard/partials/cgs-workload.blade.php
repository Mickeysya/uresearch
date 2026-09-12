{{--
    "Workload Overview" — what is on this role's desk, split by module.

    A Chart.js doughnut. Rows come from CgsDashboard::workload(), which asks
    ModuleRegistry which stages this role owns, so a teammate's new module
    appears here on its own.

    The legend stays hand-written rather than using Chart.js's: it carries a
    share bar as well as the number, and sits beside the chart rather than
    above it. Chart.js's own legend is switched off in the shared defaults.
--}}
@php
    $total = (int) $workload->sum('count');
    $palette = ['#5B8FD4', '#4FA86B', '#E9B23C', '#D0342C', '#6B4FD8', '#17A8B8'];
    $chartId = 'cgs-workload-'.uniqid();
@endphp

@include('core::dashboard.partials.chartjs')

<section class="sdash-card">
    @if (isset($unavailable['applications']))
        @include('core::dashboard.partials.skeleton', ['type' => 'gauge'])
    @endif

    <header class="sdash-card-head">
        <h3>Workload Overview</h3>
    </header>

    <div class="sdash-card-body cgs-workload-body">
        <div class="chart-donut-wrap">
            @if ($total > 0)
                <canvas id="{{ $chartId }}" role="img"
                        aria-label="{{ $total }} applications pending, split by module"></canvas>
            @else
                <div class="chart-donut-empty" aria-hidden="true"></div>
            @endif

            <div class="chart-donut-centre">
                <span class="cgs-donut-label">Total</span>
                <span class="cgs-donut-number" data-count-to="{{ $total }}">{{ $total }}</span>
                <span class="cgs-donut-label">Pending</span>
            </div>
        </div>

        <ul class="cgs-workload-legend">
            @forelse ($workload as $i => $row)
                <li title="{{ $row['label'] }}: {{ $row['count'] }} pending ({{ $row['share'] }}%)">
                    <span class="sdash-dot" style="background: {{ $palette[$i % count($palette)] }}" aria-hidden="true"></span>
                    <span class="cgs-legend-label">{{ $row['label'] }}</span>
                    <span class="cgs-legend-count">{{ $row['count'] }} <span>({{ $row['share'] }}%)</span></span>
                </li>
            @empty
                <li class="cgs-legend-empty">Nothing is waiting on you.</li>
            @endforelse
        </ul>
    </div>

    <footer class="sdash-note {{ $total > 0 ? '' : 'tone-good' }}">
        <span class="sdash-note-icon" aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => 'info'])</span>
        <span>
            @if ($total > 0)
                You have {{ $total }} {{ Str::plural('application', $total) }} pending your action.
            @else
                Your queues are clear.
            @endif
        </span>
    </footer>
</section>

@if ($total > 0)
    @push('scripts')
        <script @cspNonce>
            (function () {
                var el = document.getElementById(@json($chartId));
                if (! el || typeof Chart === 'undefined') return;

                new Chart(el, {
                    type: 'doughnut',
                    data: {
                        labels: @json($workload->pluck('label')),
                        datasets: [{
                            label: "Pending",
                            data: @json($workload->pluck('count')),
                            backgroundColor: @json(collect($workload)->keys()->map(fn ($i) => $palette[$i % count($palette)])),
                            borderWidth: 2,
                            borderColor: '#FFFFFF',
                            hoverOffset: 6,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '68%',
                        animation: { animateRotate: true, duration: 700 },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                        var pct = total ? Math.round(ctx.parsed / total * 100) : 0;
                                        return ' ' + ctx.parsed + ' pending (' + pct + '%)';
                                    },
                                },
                            },
                        },
                    },
                });
            })();
        </script>
    @endpush
@endif

@include('core::dashboard.partials.count-up')
