{{--
    "How long they have waited" — the chart a Chair's and a Supervisor's
    screen actually earn.

    The generic approver dashboard drew a count per module, which for five or
    seven queues is a row of mostly-empty categories answering nothing. The
    question here is not "which module" but "how bad is the backlog", and that
    is a shape: a fat green bar is a healthy desk, a fat red one is not, and
    it reads the same whether you own two queues or ten.

    Colours come from the status tokens through Chart.uresearchToken(), not
    from literals, so the bars survive the dark theme and match the tone the
    same number already carries on the stat cards and the queue rows.
--}}
@php
    $total = (int) $ageing->sum('count');
    $chartId = 'approver-ageing-'.uniqid();
@endphp

@include('core::dashboard.partials.chartjs')

<section class="sdash-card approver-panel">
    @if (isset($unavailable['ageing']))
        @include('core::dashboard.partials.skeleton', ['type' => 'gauge'])
    @endif

    <header class="sdash-card-head">
        <h3>How long they have waited</h3>
        <span class="sdash-action">{{ $total }} pending</span>
    </header>

    <div class="sdash-card-body ageing-body approver-scroll">
        @if ($total === 0)
            <div class="empty-state">
                <p>Nothing is waiting on you.</p>
            </div>
        @else
            <div class="ageing-chart">
                <canvas id="{{ $chartId }}" role="img"
                        aria-label="{{ $total }} applications pending, grouped by how long they have waited: {{ $ageing->map(fn ($b) => $b['count'].' '.$b['label'])->implode(', ') }}"></canvas>
            </div>

            {{-- The same figures as text, for a screen reader: a canvas is
                 invisible beyond its label, and the datalabels above the bars
                 are pixels. Visually hidden rather than removed, so the panel
                 needs no scrollbar and the numbers are still announced. --}}
            <ul class="ageing-readout">
                @foreach ($ageing as $band)
                    <li>{{ $band['count'] }} waiting {{ strtolower($band['label']) }}</li>
                @endforeach
            </ul>
        @endif
    </div>
</section>

@if ($total > 0)
    @push('scripts')
        <script @cspNonce>
            (function () {
                var el = document.getElementById(@json($chartId));
                if (! el || typeof Chart === 'undefined') return;

                var tones = @json($ageing->pluck('tone'));
                var token = {
                    good: '--success-fg',
                    info: '--info-fg',
                    warn: '--warning-fg',
                    critical: '--danger-fg',
                };

                new Chart(el, {
                    type: 'bar',
                    data: {
                        labels: @json($ageing->pluck('short')),
                        datasets: [{
                            label: 'Pending',
                            data: @json($ageing->pluck('count')),
                            backgroundColor: tones.map(function (t) {
                                return Chart.uresearchToken(token[t], '#284B80');
                            }),
                            borderRadius: 6,
                            borderSkipped: false,
                            maxBarThickness: 48,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } },
                            x: { grid: { display: false } },
                        },
                        // Room above the tallest bar for its number.
                        layout: { padding: { top: 18 } },
                        plugins: {
                            legend: { display: false },
                            // The count above each bar, so it reads without
                            // hovering -- which is what the legend list under
                            // the chart was doing, at the cost of a scrollbar.
                            // Zero is drawn too: "none in this band" is
                            // information, and an absent bar is not.
                            datalabels: {
                                display: true,
                                anchor: 'end',
                                align: 'top',
                                offset: 2,
                                color: function () { return Chart.uresearchToken('--text-body', '#23283A'); },
                                font: { size: 12, weight: '700' },
                                formatter: function (value) { return value; },
                            },
                            // No `external` here on purpose: chartjs.blade.php
                            // makes the free-floating <body> tooltip the
                            // default, so this only adds the wording. Setting
                            // one locally would clip it back inside the card.
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        return ' ' + ctx.parsed.y + ' waiting ' + @json($ageing->pluck('label'))[ctx.dataIndex].toLowerCase();
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
