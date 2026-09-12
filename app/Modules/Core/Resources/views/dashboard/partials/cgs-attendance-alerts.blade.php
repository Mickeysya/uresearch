{{--
    "Attendance Alerts" — every student's latest attendance record, counted
    across the bands.

    Bands come from StudentDashboard::attendanceBands(), NOT from thresholds
    of their own, so a student reading "Good" on their dashboard is counted
    as Good here. The 80% figure in the footer is a separate measure: 80% is
    the mandatory compliance threshold, while the bands are the traffic-light
    scale, and they deliberately do not line up.
--}}
<section class="sdash-card">
    @if (isset($unavailable['attendance']))
        @include('core::dashboard.partials.skeleton', ['type' => 'rows', 'rows' => 3])
    @endif

    <header class="sdash-card-head">
        <h3>Attendance Alerts</h3>
        <a href="{{ route('attendance.at-risk') }}" class="sdash-action">View All</a>
    </header>

    <div class="sdash-card-body">
        @forelse ($attendance['bands'] as $band)
            <a href="{{ route('attendance.at-risk') }}" class="sdash-row cgs-band-row">
                <span class="sdash-row-icon tone-{{ $band['tone'] }}" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => $band['tone'] === 'good' ? 'check' : 'warning'])
                </span>
                <span class="sdash-row-main">
                    <span class="sdash-row-title">{{ $band['label'] }}</span>
                </span>
                <span class="cgs-band-count tone-{{ $band['tone'] }}">{{ $band['count'] }}</span>
                <span class="sdash-row-chevron" aria-hidden="true">
                    @include('core::dashboard.partials.icon', ['name' => 'chevron'])
                </span>
            </a>
        @empty
            <p class="sdash-empty">No attendance has been uploaded yet.</p>
        @endforelse
    </div>

    <footer class="sdash-note {{ $attendance['belowThreshold'] > 0 ? 'tone-critical' : 'tone-good' }}">
        <span class="sdash-note-icon" aria-hidden="true">@include('core::dashboard.partials.icon', ['name' => 'info'])</span>
        <span>
            @if ($attendance['total'] === 0)
                Upload a UTrace export to start tracking attendance.
            @elseif ($attendance['belowThreshold'] > 0)
                {{ $attendance['belowThreshold'] }} {{ Str::plural('student', $attendance['belowThreshold']) }}
                {{ $attendance['belowThreshold'] === 1 ? 'is' : 'are' }} below the 80% threshold.
            @else
                Every student is above the 80% threshold.
            @endif
        </span>
    </footer>
</section>
