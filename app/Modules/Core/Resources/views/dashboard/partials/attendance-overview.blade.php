{{--
    "Attendance Overview" — a semicircular gauge (a half-doughnut).

    A Chart.js chart rather than hand-drawn SVG, so it shares the same
    external tooltip as every other chart in the portal instead of relying on
    the browser's native <title> pop-up, which was slow, unstyled and clipped
    at the panel edge.

    Two concentric rings, which is how the band scale survives the move:

      outer, thin   the three bands — Critical / Warning / Good. Replaces the
                    notches the SVG drew, and each band is hoverable in its
                    own right.
      inner, thick  this student's value against the remainder.

    Chart.js keeps one `labels` array per chart, not per dataset, and the two
    rings have different segment counts — so each dataset carries its own
    `segmentLabels` and the tooltip callback reads from that.

    To change where Good/Warning/Critical start, edit attendanceBands() in
    app/Modules/Core/Services/StudentDashboard.php — the dial, the legend and
    the status line below all follow.
--}}
@php
    use App\Modules\Core\Services\StudentDashboard;

    $pct = $attendance?->percentage !== null ? (float) $attendance->percentage : null;
    $tone = StudentDashboard::toneFor($pct);
    $bands = StudentDashboard::attendanceBands();

    $toneColours = ['good' => '#00BF63', 'warn' => '#E9B23C', 'critical' => '#D0342C', 'none' => '#C7CEDB'];

    // Bands are declared high-to-low; a dial reads low-to-high, left to right.
    $ordered = collect($bands)->reverse()->values();
    $scale = $ordered->map(fn ($band, $i) => [
        'label' => $band['label'],
        'span' => ($ordered[$i + 1]['from'] ?? 100.0) - $band['from'],
        'colour' => $toneColours[$band['tone']],
    ]);

    $chartId = 'attendance-gauge-'.uniqid();
@endphp

@include('core::dashboard.partials.chartjs')

<section class="sdash-card sdash-attendance">
    @if (isset($unavailable['attendance']))
        @include('core::dashboard.partials.skeleton', ['type' => 'gauge'])
    @endif

    <header class="sdash-card-head">
        <h3>Attendance Overview</h3>
        <a href="{{ route('attendance.overview') }}" class="sdash-action">View Details</a>
    </header>

    <div class="sdash-card-body sdash-attendance-body">
        <div class="chart-gauge-wrap">
            <canvas id="{{ $chartId }}" role="img"
                    aria-label="{{ $pct !== null ? 'Current attendance '.$pct.' percent' : 'No attendance data' }}"></canvas>

            <div class="chart-gauge-value tone-{{ $tone }}">
                <span class="sdash-gauge-number"
                      @if ($pct !== null) data-count-to="{{ $pct }}" data-count-decimals="1" data-count-suffix="%" data-count-duration="950" @endif>{{ $pct !== null ? rtrim(rtrim(number_format($pct, 1), '0'), '.').'%' : '—' }}</span>
                <span class="sdash-gauge-caption">Current Attendance</span>
            </div>
        </div>

        <div class="sdash-gauge-side">
            <ul class="sdash-legend">
                @foreach ($bands as $band)
                    <li class="{{ $band['tone'] === $tone ? 'is-active' : '' }}" title="{{ $band['label'] }}{{ $band['tone'] === $tone ? ' — you are here' : '' }}">
                        <span class="sdash-dot tone-{{ $band['tone'] }}" aria-hidden="true"></span>
                        <span class="sdash-legend-label">{{ $band['label'] }}</span>
                        @if ($band['tone'] === $tone)
                            <span class="sdash-legend-now">You</span>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($attendance)
                <dl class="sdash-facts">
                    <div>
                        <dt>Sessions attended</dt>
                        <dd>{{ $attendance->sessions_attended }} <span>/ {{ $attendance->sessions_total }}</span></dd>
                    </div>
                    <div>
                        <dt>Period ending</dt>
                        <dd>{{ $attendance->period_end->format('j M Y') }}</dd>
                    </div>
                </dl>
            @endif
        </div>
    </div>

    <footer class="sdash-note tone-{{ $tone }}">
        <span class="sdash-note-icon" aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => 'info'])</span>
        <span>
            @if ($pct === null)
                No attendance has been uploaded for you yet.
            @elseif ($attendance->at_risk)
                You are flagged at-risk. <a href="{{ route('attendance-appeal.create') }}">File an appeal</a> if this looks wrong.
            @else
                You are in good standing. Keep it up!
            @endif
        </span>
    </footer>
</section>

@push('scripts')
    <script @cspNonce>
        (function () {
            var el = document.getElementById(@json($chartId));
            if (! el || typeof Chart === 'undefined') return;

            var pct = @json($pct);
            var toneColour = @json($toneColours[$tone]);

            new Chart(el, {
                type: 'doughnut',
                data: {
                    datasets: [
                        {
                            // Outer scale. Always the full range, so the bands
                            // read the same whatever this student scored.
                            label: 'Band',
                            data: @json($scale->pluck('span')),
                            segmentLabels: @json($scale->pluck('label')),
                            backgroundColor: @json($scale->pluck('colour')),
                            borderWidth: 0,
                            weight: 1,
                        },
                        {
                            label: 'Attendance',
                            data: pct === null ? [0, 100] : [pct, 100 - pct],
                            segmentLabels: ['Your attendance', 'Remaining'],
                            backgroundColor: [toneColour, '#E7EBF2'],
                            borderWidth: 0,
                            weight: 3.4,
                        },
                    ],
                },
                options: {
                    // A semicircle: start at nine o'clock and sweep 180 degrees.
                    rotation: 270,
                    circumference: 180,
                    cutout: '56%',
                    animation: { animateRotate: true, duration: 900 },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            filter: function (ctx) {
                                // "Remaining" is the empty part of the dial,
                                // not a fact about the student.
                                return ! (ctx.datasetIndex === 1 && ctx.dataIndex === 1);
                            },
                            callbacks: {
                                title: function (items) {
                                    var c = items[0];
                                    return c.dataset.segmentLabels[c.dataIndex];
                                },
                                label: function (ctx) {
                                    if (ctx.datasetIndex === 1) {
                                        return ' ' + ctx.parsed + '% — the threshold is 80%';
                                    }
                                    return ' covers ' + ctx.parsed + ' percentage points of the scale';
                                },
                            },
                        },
                    },
                },
            });
        })();
    </script>
@endpush

@include('core::dashboard.partials.count-up')
