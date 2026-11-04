{{--
    "Attendance Alerts" — every student's latest attendance record, counted
    across the bands.

    Bands come from StudentDashboard::attendanceBands(), NOT from thresholds
    of their own, so a student reading "Good" on their dashboard is counted
    as Good here. The 80% figure in the footer is a separate measure: 80% is
    the mandatory compliance threshold, while the bands are the traffic-light
    scale, and they deliberately do not line up.

    Warning colour only where there is something to act on. This used to draw
    a warning and a critical triangle, with red and amber zeros, before any
    attendance had been uploaded at all -- an alarm with nothing behind it.
    Now: nothing uploaded is one calm line with the next step; a band with
    nobody in it is grey; tone appears only on a band that has students.
--}}
@php
    $total = (int) $attendance['total'];
    $below = (int) $attendance['belowThreshold'];
@endphp

<section class="sdash-card">
    @if (isset($unavailable['attendance']))
        @include('core::dashboard.partials.skeleton', ['type' => 'rows', 'rows' => 3])
    @endif

    <header class="sdash-card-head">
        <h3>Attendance Alerts</h3>
        @if ($total > 0)
            <a href="{{ route('attendance.at-risk') }}" class="sdash-action">View All</a>
        @endif
    </header>

    @if ($total === 0)
        <div class="sdash-card-body att-empty">
            <span class="att-empty-icon" aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => 'upload'])</span>
            <p><b>No attendance uploaded yet.</b><br>Alerts appear here once a UTrace export is uploaded.</p>
            @if (Route::has('attendance.upload.form'))
                <a href="{{ route('attendance.upload.form') }}" class="btn-secondary"
                   data-tip="Import the latest UTrace attendance export">Upload attendance</a>
            @endif
        </div>
    @else
        <div class="sdash-card-body">
            {{-- The whole cohort as one bar, so the split reads before the numbers. --}}
            {{-- An SVG so the segment widths are attributes, not inline styles. --}}
            @php $x = 0; @endphp
            <svg class="att-split" viewBox="0 0 100 10" preserveAspectRatio="none" role="img"
                 aria-label="{{ $attendance['bands']->map(fn ($b) => $b['count'].' '.$b['label'])->implode(', ') }}">
                @foreach ($attendance['bands'] as $band)
                    @if ($band['count'] > 0)
                        @php $w = $band['count'] / $total * 100; @endphp
                        <rect class="att-split-seg tone-{{ $band['tone'] }}" x="{{ round($x, 3) }}" y="0" width="{{ round($w, 3) }}" height="10"
                              data-tip="{{ $band['label'] }}: {{ $band['count'] }} of {{ $total }}"></rect>
                        @php $x += $w; @endphp
                    @endif
                @endforeach
            </svg>
            <p class="att-total">{{ $total }} {{ Str::plural('student', $total) }} tracked</p>

            @foreach ($attendance['bands'] as $band)
                @php $active = $band['count'] > 0; @endphp
                <a href="{{ route('attendance.at-risk') }}" class="sdash-row cgs-band-row {{ $active ? '' : 'is-quiet' }}"
                   data-tip="{{ $active ? 'See the '.$band['count'].' '.Str::plural('student', $band['count']).' in this band' : 'Nobody in this band' }}">
                    <span class="sdash-row-icon tone-{{ $active ? $band['tone'] : 'none' }}" aria-hidden="true">
                        @include('core::dashboard.partials.icon', ['name' => $band['tone'] === 'good' ? 'check' : ($active ? 'warning' : 'check')])
                    </span>
                    <span class="sdash-row-main">
                        <span class="sdash-row-title">{{ $band['label'] }}</span>
                    </span>
                    <span class="cgs-band-count tone-{{ $active ? $band['tone'] : 'none' }}">{{ $band['count'] }}</span>
                    <span class="sdash-row-chevron" aria-hidden="true">
                        @include('core::dashboard.partials.icon', ['name' => 'chevron'])
                    </span>
                </a>
            @endforeach
        </div>

        <footer class="sdash-note {{ $below > 0 ? 'tone-critical' : 'tone-good' }}">
            <span class="sdash-note-icon" aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => $below > 0 ? 'warning' : 'check'])</span>
            <span>
                @if ($below > 0)
                    {{ $below }} {{ Str::plural('student', $below) }} {{ $below === 1 ? 'is' : 'are' }} below the 80% threshold.
                @else
                    Every student is above the 80% threshold.
                @endif
            </span>
        </footer>
    @endif
</section>
