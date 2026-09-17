{{--
    "Cohort attendance" — a doughnut, and on purpose not another bar chart.

    The Chair's chart is about a DESK (how long has work been sitting). A
    supervisor's is about PEOPLE (is my cohort healthy), which is parts of a
    whole and reads better as a ring with the headcount in the middle. Same
    Chart.js, same tokens, different question.

    Bands come from StudentDashboard::attendanceBands(), so a student reading
    "Good" on their own dashboard is counted as Good here.
--}}
@php
    $total = (int) $attendanceSpread->sum('count');
    $chartId = 'sup-attendance-'.uniqid();

    // The status tokens, so the ring matches the figures beside the names in
    // the candidates panel and survives the dark theme.
    $tokens = ['good' => '--success-fg', 'warn' => '--warning-fg', 'critical' => '--danger-fg', 'none' => '--text-grey'];
@endphp

@include('core::dashboard.partials.chartjs')

<section class="sdash-card approver-panel">
    @if (isset($unavailable['candidates']))
        @include('core::dashboard.partials.skeleton', ['type' => 'gauge'])
    @endif

    <header class="sdash-card-head">
        <h3>Cohort attendance</h3>
        <span class="sdash-action">{{ $total }} {{ Str::plural('candidate', $total) }}</span>
    </header>

    {{-- approver-fit, not approver-scroll: a ring and four legend rows are a
         fixed amount of content, so the panel is sized to hold them rather
         than given a scrollbar. The ring takes whatever height is left after
         the legend and shrinks with the panel. --}}
    <div class="sdash-card-body approver-fit">
        @if ($total === 0)
            <div class="empty-state"><p>No candidates to report on.</p></div>
        @else
            <div class="chart-donut-wrap sup-donut">
                <canvas id="{{ $chartId }}" role="img"
                        aria-label="{{ $attendanceSpread->map(fn ($s) => $s['count'].' '.$s['label'])->implode(', ') }}"></canvas>

                <div class="chart-donut-centre">
                    <span class="cgs-donut-label">Candidates</span>
                    <span class="cgs-donut-number">{{ $total }}</span>
                </div>
            </div>

            <ul class="sup-donut-legend">
                @foreach ($attendanceSpread as $slice)
                    <li>
                        <span class="ageing-dot tone-{{ $slice['tone'] }}" aria-hidden="true"></span>
                        <span class="sup-donut-label">{{ $slice['label'] }}</span>
                        <span class="sup-donut-count">{{ $slice['count'] }}</span>
                    </li>
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

                var tokens = @json($attendanceSpread->pluck('tone')->map(fn ($t) => $tokens[$t] ?? '--text-grey'));

                new Chart(el, {
                    type: 'doughnut',
                    data: {
                        labels: @json($attendanceSpread->pluck('label')),
                        datasets: [{
                            data: @json($attendanceSpread->pluck('count')),
                            backgroundColor: tokens.map(function (t) {
                                return Chart.uresearchToken(t, '#5B8FD4');
                            }),
                            borderWidth: 2,
                            // The gap between slices is a cut down to the
                            // card, so it has to BE the card colour.
                            borderColor: function () { return Chart.uresearchToken('--surface', '#FFFFFF'); },
                            hoverOffset: 6,
                        }],
                    },
                    options: {
                        cutout: '68%',
                        animation: { animateRotate: true, duration: 700 },
                        plugins: {
                            legend: { display: false },
                            // A ring with four slices does not want numbers
                            // stamped across it; the legend beside it carries
                            // them. (A bar chart is the opposite -- see
                            // approver-ageing.)
                            datalabels: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        var sum = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                        var pct = sum ? Math.round(ctx.parsed / sum * 100) : 0;
                                        return ' ' + ctx.parsed + ' of ' + sum + ' (' + pct + '%)';
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
