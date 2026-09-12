{{--
    "Attendance Overview" — the semicircular gauge and its legend.

    Plain SVG, no charting library: it is one arc, it has to scale with the
    panel, and the band colours have to match the legend exactly. Both read
    the same bands from StudentDashboard::attendanceBands(), so they cannot
    drift apart.

    To change where Good/Warning/Critical start, edit attendanceBands() in
    app/Modules/Core/Services/StudentDashboard.php — the arc, the tick marks,
    the legend and the status line below all follow.
--}}
@php
    use App\Modules\Core\Services\StudentDashboard;

    $pct = $attendance?->percentage !== null ? (float) $attendance->percentage : null;
    $tone = StudentDashboard::toneFor($pct);
    $bands = StudentDashboard::attendanceBands();

    // Semicircle geometry: centre (100,100), r=78, drawn left to right.
    $cx = 100; $cy = 100; $r = 78;
    $arc = M_PI * $r;
    $filled = $pct !== null ? ($pct / 100) * $arc : 0;

    // Position on the arc for a given percentage.
    $pointAt = function (float $p) use ($cx, $cy, $r) {
        $t = M_PI * (1 - ($p / 100));
        return [round($cx + $r * cos($t), 2), round($cy - $r * sin($t), 2)];
    };

    // Notches on the track where one band gives way to the next, so the
    // thresholds in the legend are visible on the dial itself.
    $ticks = [];
    foreach ($bands as $band) {
        if ($band['from'] > 0) {
            $t = M_PI * (1 - ($band['from'] / 100));
            $ticks[] = [
                round($cx + ($r - 9) * cos($t), 2), round($cy - ($r - 9) * sin($t), 2),
                round($cx + ($r + 9) * cos($t), 2), round($cy - ($r + 9) * sin($t), 2),
            ];
        }
    }

    [$mx, $my] = $pct !== null ? $pointAt($pct) : [0, 0];
    $activeTone = $tone;
@endphp

<section class="sdash-card sdash-attendance">
    @if (isset($unavailable['attendance']))
        @include('core::dashboard.partials.skeleton', ['type' => 'gauge'])
    @endif

    <header class="sdash-card-head">
        <h3>Attendance Overview</h3>
        <a href="{{ route('attendance.overview') }}" class="sdash-action">View Details</a>
    </header>

    <div class="sdash-card-body sdash-attendance-body">
        <div class="sdash-gauge-wrap">
            <svg class="sdash-gauge" viewBox="0 0 200 116" role="img"
                 aria-label="{{ $pct !== null ? 'Current attendance '.$pct.' percent' : 'No attendance data' }}">
                {{-- track: a real grey, not near-white, so the dial reads as a
                     full object even when the value is low --}}
                <path d="M 22 100 A 78 78 0 0 1 178 100" fill="none" stroke="#DFE4EE" stroke-width="18" stroke-linecap="round"/>

                {{-- band thresholds --}}
                @foreach ($ticks as $tick)
                    <line x1="{{ $tick[0] }}" y1="{{ $tick[1] }}" x2="{{ $tick[2] }}" y2="{{ $tick[3] }}" stroke="#FFFFFF" stroke-width="2.5" opacity="0.9"/>
                @endforeach

                {{-- the value itself, coloured by the band it lands in.
                     --arc / --arc-offset drive the sweep animation in CSS. --}}
                @if ($pct !== null)
                    <path class="sdash-gauge-fill tone-{{ $activeTone }}"
                          d="M 22 100 A 78 78 0 0 1 178 100"
                          fill="none" stroke-width="18" stroke-linecap="round"
                          style="--arc: {{ round($arc, 2) }}; --arc-offset: {{ round($arc - $filled, 2) }};">
                        <title>Current attendance {{ $pct }}% — {{ collect($bands)->firstWhere('tone', $tone)['label'] ?? '' }}. Threshold is 80%.</title>
                    </path>

                    <g class="sdash-gauge-marker" style="--mx: {{ $mx }}px; --my: {{ $my }}px;">
                        <circle cx="{{ $mx }}" cy="{{ $my }}" r="9" fill="#FFFFFF"/>
                        <circle cx="{{ $mx }}" cy="{{ $my }}" r="5" fill="#23283A"/>
                    </g>
                @endif
            </svg>

            <div class="sdash-gauge-value tone-{{ $tone }}">
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

@include('core::dashboard.partials.count-up')
