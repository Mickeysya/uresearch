{{--
    "Attendance Overview" — a full-circle progress ring, redesigned
    2026-10-29 from the earlier semicircle-plus-band-legend layout.

    A Chart.js chart rather than hand-drawn SVG, so it shares the same
    external tooltip as every other chart in the portal instead of relying on
    the browser's native <title> pop-up, which was slow, unstyled and clipped
    at the panel edge.

    One ring now, not two: the band scale used to be drawn as a second thin
    ring around the reading. That information now lives in the status chip
    next to it instead ("Critical · < 75%"), so the dial itself only needs
    to show the one real figure -- this student's value against the
    remainder.

    To change where Good/Warning/Critical start, edit attendanceBands() in
    app/Modules/Core/Services/StudentDashboard.php — the dial colour, the
    status chip and the target figure all follow.
--}}
@php
    use App\Modules\Core\Services\StudentDashboard;
    use Illuminate\Support\Str;

    // Nothing on file is not zero attendance -- see
    // StudentDashboard::STARTING_PERCENTAGE. The skeleton above still covers
    // the other case, where no module supplies attendance at all.
    $pct = $attendance !== null
        ? (float) $attendance->percentage
        : StudentDashboard::STARTING_PERCENTAGE;
    $tone = StudentDashboard::toneFor($pct);
    $bands = StudentDashboard::attendanceBands();

    $toneColours = ['good' => '#00BF63', 'warn' => '#E9B23C', 'critical' => '#D0342C', 'none' => '#C7CEDB'];

    // The chip ("Critical · < 75%") and the Target stat both read straight
    // off attendanceBands() rather than repeating a threshold number here,
    // so they can never drift from what toneFor() actually used.
    $currentBand = collect($bands)->firstWhere('tone', $tone);
    $goodBand = collect($bands)->firstWhere('tone', 'good');
    $bandQualifier = fn (array $band) => trim(Str::between($band['label'], '(', ')'));

    $chartId = 'attendance-gauge-'.uniqid();
@endphp

@include('core::dashboard.partials.chartjs')

<section class="sdash-card sdash-attendance tone-{{ $tone }}">
    @if (isset($unavailable['attendance']))
        @include('core::dashboard.partials.skeleton', ['type' => 'gauge'])
    @endif

    <header class="sdash-card-head">
        <h3>Attendance Overview</h3>
        <a href="{{ route('attendance.overview') }}" class="sdash-action sdash-action-plain">
            View Details
            <span aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => 'chevron'])</span>
        </a>
    </header>

    <div class="sdash-card-body sdash-attendance-body">
        <div class="chart-gauge-wrap chart-gauge-circle tone-{{ $tone }}">
            <canvas id="{{ $chartId }}" role="img"
                    aria-label="Current attendance {{ $pct }} percent{{ $attendance ? '' : ', nothing recorded yet' }}"></canvas>

            <div class="chart-gauge-value chart-gauge-value-circle tone-{{ $tone }}">
                <span class="sdash-gauge-number"
                      data-count-to="{{ $pct }}" data-count-decimals="1" data-count-suffix="%" data-count-duration="950">{{ rtrim(rtrim(number_format($pct, 1), '0'), '.') }}%</span>
                <span class="sdash-gauge-caption">attendance</span>
            </div>
        </div>

        <div class="sdash-gauge-side">
            @if ($currentBand)
                <span class="sdash-status-chip tone-{{ $tone }}">
                    <span class="sdash-dot tone-{{ $tone }}" aria-hidden="true"></span>
                    {{ Str::before($currentBand['label'], '(') }}
                    <span class="sdash-status-chip-qualifier">&middot; {{ $bandQualifier($currentBand) }}</span>
                </span>
            @endif

            @if ($attendance)
                <div class="sdash-stat-grid">
                    <div class="sdash-stat-cell">
                        <span class="sdash-stat-cell-label">Sessions attended</span>
                        <span class="sdash-stat-cell-value">{{ $attendance->sessions_attended }} <span class="sdash-fact-unit">/ {{ $attendance->sessions_total }}</span></span>
                    </div>
                    <div class="sdash-stat-cell">
                        <span class="sdash-stat-cell-label">Missed</span>
                        <span class="sdash-stat-cell-value">{{ $attendance->sessions_total - $attendance->sessions_attended }}</span>
                    </div>
                    <div class="sdash-stat-cell">
                        <span class="sdash-stat-cell-label">Period ending</span>
                        <span class="sdash-stat-cell-value">{{ $attendance->period_end->format('j M Y') }}</span>
                    </div>
                    @if ($goodBand)
                        <div class="sdash-stat-cell">
                            <span class="sdash-stat-cell-label">Target</span>
                            <span class="sdash-stat-cell-value">{{ $bandQualifier($goodBand) }}</span>
                        </div>
                    @endif
                </div>
            @else
                <p class="sdash-stat-grid-empty">Nothing has been uploaded yet, so you start at {{ rtrim(rtrim(number_format($pct, 1), '0'), '.') }}%.</p>
            @endif
        </div>
    </div>

    {{-- What to do next, worded entirely by the module supplying attendance
         (SuppliesAttendance::actionFor()) -- never the projection, only
         plain arithmetic on the student's own sessions. See
         Services\StudentDashboard::attendanceAction(). --}}
    @if ($attendanceAction)
        <div class="sdash-action-strip tone-{{ $attendanceAction['pillTone'] }}">
            <div class="sdash-action-strip-text">
                <span class="sdash-action-strip-label">{{ $attendanceAction['label'] }}</span>
                <span class="sdash-action-strip-value">{{ $attendanceAction['value'] }}</span>
                <span class="sdash-action-strip-note">{{ $attendanceAction['note'] }}</span>
            </div>
            @if ($attendanceAction['pill'])
                <a href="{{ $attendanceAction['url'] }}" class="sdash-pill pill-{{ $attendanceAction['pillTone'] }}">{{ $attendanceAction['pill'] }}</a>
            @endif
        </div>
    @endif

    {{-- Only when it adds something: the at-risk appeal link. "Nothing
         uploaded yet" is already said beside the dial, and "good standing"
         is what the Good chip already says. --}}
    @if ($attendance && $attendance->at_risk)
        <footer class="sdash-note tone-{{ $tone }}">
            <span class="sdash-note-icon" aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => 'info'])</span>
            <span>You're flagged as <strong>at-risk</strong>. Think this is wrong?</span>
            <a href="{{ route('attendance-appeal.create') }}" class="sdash-note-cta">File an appeal</a>
        </footer>
    @endif
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
                            label: 'Attendance',
                            data: [pct, 100 - pct],
                            segmentLabels: ['Your attendance', 'Remaining'],
                            // Index 0 is the reading itself and keeps its
                            // tone colour; index 1 is the empty remainder,
                            // which is track, not data.
                            backgroundColor: function (ctx) {
                                return ctx.dataIndex === 0
                                    ? toneColour
                                    : Chart.uresearchToken('--border-grey', '#E7EBF2');
                            },
                            borderWidth: 0,
                            // Rounded caps read as a pill at a partial
                            // percentage, but at 100% the single segment IS
                            // the whole circle -- its start and end sit at
                            // the same point, so rounding them still carves
                            // two notches out of what should be one closed
                            // ring. No rounding, no notch, once it's full.
                            borderRadius: pct >= 100 ? 0 : 6,
                            spacing: pct > 0 && pct < 100 ? 3 : 0,
                        },
                    ],
                },
                options: {
                    // A full circle. Chart.js doughnuts already start at
                    // twelve o'clock when rotation is left at its default
                    // (0) -- an explicit -90 here used to subtract another
                    // quarter-turn on top of that, starting the sweep at
                    // nine o'clock instead. cutout controls the ring's
                    // thickness (there is no literal stroke-width on a
                    // Chart.js arc) -- 83% leaves a ring about 60% as thick
                    // as the original 72%, in the same 1 - cutout
                    // proportion either way.
                    rotation: 0,
                    circumference: 360,
                    cutout: '83%',
                    animation: { animateRotate: true, duration: 900 },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            filter: function (ctx) {
                                // "Remaining" is the empty part of the dial,
                                // not a fact about the student.
                                return ctx.dataIndex !== 1;
                            },
                            callbacks: {
                                title: function (items) {
                                    return items[0].dataset.segmentLabels[items[0].dataIndex];
                                },
                                label: function (ctx) {
                                    return ' ' + ctx.parsed + '%, and the threshold is 80%';
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
