@extends('core::layouts.app')

@section('title', 'TA Hours')

@push('head')
    @include('haziq::partials.styles')
@endpush

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>TA Hours</h2>
        <div class="card-divider"></div>

        @if ($student)
            <p class="queue-meta">In lieu of the waived tuition fee. Total logged: <b>{{ number_format($total, 2) }} hrs</b>
                <span style="color: var(--text-grey);">(cap: 21 hrs/month, no carry-forward)</span></p>

            @include('core::dashboard.partials.chartjs')
            <p class="queue-meta" style="margin-top: 14px;">Hours logged over the last 6 months, against the monthly cap.</p>
            <div style="height: 200px;">
                <canvas id="ta-hours-trend" role="img"
                        aria-label="TA hours by month: {{ $monthlyTrend->map(fn ($m) => $m['month'].' '.$m['hours'].' hrs')->implode(', ') }}"></canvas>
            </div>

            @push('scripts')
                <script @cspNonce>
                    (function () {
                        var el = document.getElementById('ta-hours-trend');
                        if (! el || typeof Chart === 'undefined') return;

                        var hours = @json($monthlyTrend->pluck('hours'));
                        var colors = hours.map(function (h) {
                            return h > 21 ? Chart.uresearchToken('--danger-fg', '#D0342C')
                                : h >= 18 ? Chart.uresearchToken('--warning-fg', '#C28E40')
                                : Chart.uresearchToken('--success-fg', '#07794A');
                        });

                        new Chart(el, {
                            type: 'bar',
                            data: {
                                labels: @json($monthlyTrend->pluck('month')),
                                datasets: [{
                                    label: 'Hours',
                                    data: hours,
                                    backgroundColor: colors,
                                    borderRadius: 5,
                                    borderSkipped: false,
                                    maxBarThickness: 48,
                                }],
                            },
                            options: {
                                animation: { duration: 650, easing: 'easeOutQuart' },
                                plugins: {
                                    legend: { display: false },
                                    datalabels: { display: true, anchor: 'end', align: 'top', offset: 2, color: function () { return Chart.uresearchToken('--text-body', '#23283A'); }, font: { size: 11, weight: '700' } },
                                },
                                scales: {
                                    y: { beginAtZero: true, max: 24, grid: { color: function () { return Chart.uresearchToken('--border-grey', '#F1F3F7'); } }, border: { display: false } },
                                    x: { grid: { display: false }, border: { display: false } },
                                },
                            },
                        });
                    })();
                </script>
            @endpush
        @else
            @if ($byStudentThisMonth->isNotEmpty())
                @include('core::dashboard.partials.chartjs')
                <p class="queue-meta" style="margin-top: 4px; margin-bottom: 14px;">This month's hours per student, against the 21hr cap — check before logging more for someone near it.</p>
                <div style="height: {{ 50 + $byStudentThisMonth->count() * 32 }}px;">
                    <canvas id="ta-hours-by-student" role="img"
                            aria-label="Hours this month by student: {{ $byStudentThisMonth->map(fn ($r) => $r['student']->name.' '.$r['hours'].' hrs')->implode(', ') }}"></canvas>
                </div>

                @push('scripts')
                    <script @cspNonce>
                        (function () {
                            var el = document.getElementById('ta-hours-by-student');
                            if (! el || typeof Chart === 'undefined') return;

                            var hours = @json($byStudentThisMonth->pluck('hours'));
                            var colors = hours.map(function (h) {
                                return h > 21 ? Chart.uresearchToken('--danger-fg', '#D0342C')
                                    : h >= 18 ? Chart.uresearchToken('--warning-fg', '#C28E40')
                                    : Chart.uresearchToken('--success-fg', '#07794A');
                            });

                            new Chart(el, {
                                type: 'bar',
                                data: {
                                    labels: @json($byStudentThisMonth->map(fn ($r) => $r['student']->name)),
                                    datasets: [{
                                        label: 'Hours this month',
                                        data: hours,
                                        backgroundColor: colors,
                                        borderRadius: 5,
                                        borderSkipped: false,
                                    }],
                                },
                                options: {
                                    indexAxis: 'y',
                                    animation: { duration: 650, easing: 'easeOutQuart' },
                                    plugins: {
                                        legend: { display: false },
                                        datalabels: { display: true, anchor: 'end', align: 'end', color: function () { return Chart.uresearchToken('--text-body', '#23283A'); }, font: { size: 11, weight: '700' } },
                                    },
                                    scales: {
                                        x: { beginAtZero: true, max: 24, grid: { color: function () { return Chart.uresearchToken('--border-grey', '#F1F3F7'); } }, border: { display: false } },
                                        y: { grid: { display: false }, border: { display: false } },
                                    },
                                },
                            });
                        })();
                    </script>
                @endpush
            @endif

            <form method="POST" action="{{ route('ta-hours.store') }}" style="margin-bottom: 24px;">
                @csrf
                <label for="student_id">Student</label>
                <select name="student_id" id="student_id" required>
                    <option value="">Select a student&hellip;</option>
                    @foreach ($students as $s)
                        <option value="{{ $s->id }}" @selected((string) old('student_id') === (string) $s->id)>
                            {{ $s->name }}@if ($s->matric_no) ({{ $s->matric_no }})@endif
                        </option>
                    @endforeach
                </select>
                @error('student_id') <p class="field-error">{{ $message }}</p> @enderror

                <label for="activity_type">Activity Type</label>
                <input type="text" name="activity_type" id="activity_type" placeholder="Tutorial, Lab Demonstration, Invigilation..." required value="{{ old('activity_type') }}">

                <label for="activity_date">Date</label>
                <input type="date" name="activity_date" id="activity_date" required value="{{ old('activity_date') }}">

                <label for="hours">Hours</label>
                <input type="number" step="0.25" min="0.25" max="21" name="hours" id="hours" required value="{{ old('hours') }}">

                <label for="description">Description</label>
                <input type="text" name="description" id="description" value="{{ old('description') }}">

                <button type="submit">Record Hours</button>
            </form>
        @endif

        <div style="overflow-x: auto;">
            <table class="haziq-table">
                <thead>
                    <tr>
                        @unless ($student)<th>Student</th>@endunless
                        <th>Activity</th>
                        <th>Date</th>
                        <th>Hours</th>
                        <th>Recorded By</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            @unless ($student)<td>{{ $log->student->name }}</td>@endunless
                            <td>{{ $log->activity_type }}@if ($log->description) <br><span style="color: var(--text-grey); font-size: 12px;">{{ $log->description }}</span>@endif</td>
                            <td>{{ $log->activity_date->format('j M Y') }}</td>
                            <td>{{ number_format($log->hours, 2) }}</td>
                            <td>{{ $log->recordedBy?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $student ? 4 : 5 }}"><div class="empty-state">No TA hours recorded yet.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top: 16px;">{{ $logs->links() }}</div>
    </div>
</div>
@include('haziq::partials.assistant_widget')
@endsection
