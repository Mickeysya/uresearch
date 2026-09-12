{{--
    "Applications by Status" — a Chart.js bar chart.

    "Pending" is split by whether anyone has acted yet. An application nobody
    has touched is a different administrative problem from one mid-chain, and
    a single "pending" bar hides that.

    Chart.js rather than hand-drawn bars so the axis, gridlines and tooltips
    come for free — and so the figures stay reachable on a narrow screen
    where the axis labels are clipped.
--}}
@php
    $tones = ['muted'=>'#B8C0D0','warn'=>'#E9B23C','info'=>'#5B8FD4','good'=>'#00BF63','critical'=>'#D0342C'];
    $chartId = 'adm-status-'.uniqid();
@endphp

@include('core::dashboard.partials.chartjs')

<section class="sdash-card">
    @if (isset($unavailable['applications']))
        @include('core::dashboard.partials.skeleton', ['type' => 'gauge'])
    @endif

    <header class="sdash-card-head">
        <h3>Applications by Status</h3>
        <a href="{{ route('admin.applications.index') }}" class="sdash-action">View Details</a>
    </header>

    <div class="sdash-card-body adm-chart-body">
        <div class="chart-bars-wrap">
            <canvas id="{{ $chartId }}" role="img"
                    aria-label="Applications by status: {{ $byStatus->map(fn ($b) => $b['label'].' '.$b['count'])->implode(', ') }}"></canvas>
        </div>
    </div>
</section>

@push('scripts')
    <script @cspNonce>
        (function () {
            var el = document.getElementById(@json($chartId));
            if (! el || typeof Chart === 'undefined') return;

            new Chart(el, {
                type: 'bar',
                data: {
                    labels: @json($byStatus->pluck('label')),
                    datasets: [{
                        label: "Applications",
                        data: @json($byStatus->pluck('count')),
                        backgroundColor: @json($byStatus->map(fn ($b) => $tones[$b['tone']])),
                        borderRadius: 5,
                        borderSkipped: false,
                        maxBarThickness: 54,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    // Headroom so the value printed above the tallest bar is not clipped.
                    layout: { padding: { top: 18 } },
                    animation: { duration: 650, easing: 'easeOutQuart' },
                    scales: {
                        x: {
                            grid: { display: false },
                            border: { display: false },
                            ticks: {
                                autoSkip: false,
                                maxRotation: 0,
                                // Long labels wrap instead of rotating, which
                                // stays readable as the panel narrows.
                                callback: function (value) {
                                    var label = this.getLabelForValue(value);
                                    return label.length > 12 ? label.split(' ') : label;
                                },
                            },
                        },
                        y: {
                            beginAtZero: true,
                            border: { display: false },
                            grid: { color: '#F1F3F7' },
                            ticks: { precision: 0, maxTicksLimit: 5 },
                        },
                    },
                    plugins: {
                        legend: { display: false },
                        // The number above each bar, so a value is readable
                        // without hovering. Zero is shown too: "no drafts" is
                        // information, and a silently absent bar is not.
                        datalabels: {
                            display: true,
                            anchor: 'end',
                            align: 'top',
                            offset: 2,
                            color: '#23283A',
                            font: { size: 11, weight: '700' },
                            formatter: function (value) { return value; },
                        },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    return ' ' + ctx.parsed.y + (ctx.parsed.y === 1 ? ' application' : ' applications');
                                },
                            },
                        },
                    },
                },
            });
        })();
    </script>
@endpush
