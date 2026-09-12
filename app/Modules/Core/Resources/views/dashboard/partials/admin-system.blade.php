{{--
    "System Overview" — four health tiles plus the module mix donut.

    Every tile reads something the system genuinely records: a real database
    round trip, the `failed_jobs` / `jobs` tables, actual disk usage under
    storage/app, and sessions touched in the last fifteen minutes.

    The design's "Server Status" tile is replaced by Queue health: a tile
    rendered BY the server saying the server is up proves nothing, whereas
    failed jobs are a real and actionable signal.
--}}
@php
    $total = (int) $mix->sum('count');
    $palette = ['#5B8FD4', '#4FA86B', '#6B4FD8', '#E9B23C', '#D0342C', '#17A8B8'];
    $chartId = 'adm-mix-'.uniqid();
@endphp

@include('core::dashboard.partials.chartjs')

<section class="sdash-card">
    <header class="sdash-card-head">
        <h3>System Overview</h3>
        <a href="{{ route('admin.reports.index') }}" class="sdash-action">View Analytics</a>
    </header>

    <div class="sdash-card-body adm-system-body">
        <div class="adm-health">
            @foreach ($health as $tile)
                <div class="adm-tile tone-{{ $tile['tone'] }}" title="{{ $tile['label'] }}: {{ $tile['value'] }} — {{ $tile['note'] }}">
                    <span class="adm-tile-icon" aria-hidden="true">
                        @include('core::dashboard.partials.icon', ['name' => $tile['icon']])
                    </span>
                    <span class="adm-tile-label">{{ $tile['label'] }}</span>
                    <span class="adm-tile-value">{{ $tile['value'] }}</span>
                    <span class="adm-tile-note">{{ $tile['note'] }}</span>
                </div>
            @endforeach
        </div>

        <div class="adm-mix">
            <span class="adm-mix-title">Application Overview</span>

            <div class="adm-mix-body">
                <div class="chart-donut-wrap adm-donut-wrap">
                    @if ($total > 0)
                        <canvas id="{{ $chartId }}" role="img"
                                aria-label="{{ $total }} applications by module"></canvas>
                    @else
                        <div class="chart-donut-empty" aria-hidden="true"></div>
                    @endif
                    <div class="chart-donut-centre">
                        <span class="cgs-donut-number" data-count-to="{{ $total }}">{{ $total }}</span>
                        <span class="cgs-donut-label">Total</span>
                    </div>
                </div>
                </div>

                <ul class="adm-mix-legend">
                    @forelse ($mix as $i => $row)
                        <li title="{{ $row['label'] }}: {{ $row['count'] }} of {{ $total }} ({{ $row['share'] }}%)">
                            <span class="sdash-dot" style="background: {{ $palette[$i % count($palette)] }}" aria-hidden="true"></span>
                            <span class="cgs-legend-label">{{ $row['label'] }}</span>
                            {{-- Fills what was dead space between the label and the
                                 count, and carries the share as length as well as a
                                 number — the row reads at a glance now. --}}
                            <span class="adm-share" aria-hidden="true">
                                <span class="adm-share-fill" style="width: {{ max($row['share'], 2) }}%; background: {{ $palette[$i % count($palette)] }}"></span>
                            </span>
                            <span class="cgs-legend-count">{{ $row['count'] }} <span>({{ $row['share'] }}%)</span></span>
                        </li>
                    @empty
                        <li class="cgs-legend-empty">No applications yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</section>

@include('core::dashboard.partials.count-up')

@if ($total > 0)
    @push('scripts')
        <script>
            (function () {
                var el = document.getElementById(@json($chartId));
                if (! el || typeof Chart === 'undefined') return;

                new Chart(el, {
                    type: 'doughnut',
                    data: {
                        labels: @json($mix->pluck('label')),
                        datasets: [{
                            label: "Applications",
                            data: @json($mix->pluck('count')),
                            backgroundColor: @json($mix->keys()->map(fn ($i) => $palette[$i % count($palette)])),
                            borderWidth: 2,
                            borderColor: '#FFFFFF',
                            hoverOffset: 5,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '66%',
                        animation: { animateRotate: true, duration: 700 },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                        var pct = total ? Math.round(ctx.parsed / total * 100) : 0;
                                        return ' ' + ctx.parsed + ' of ' + total + ' (' + pct + '%)';
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
