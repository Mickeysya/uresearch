@extends('core::layouts.app')

@section('title', 'TA Hours')

@push('head')
    @include('norhanis::ta_hours._styles')
@endpush

@section('content')
<div class="card-container-inline">
    <x-core::page-header title="TA Hours" />

    <div class="card card-wide">

            @if ($byStudentThisMonth->isNotEmpty())
                @include('core::dashboard.partials.chartjs')
                <p class="queue-meta" style="margin-top: 4px; margin-bottom: 14px;">Approved hours this month per student, against the {{ rtrim(rtrim(number_format($cap, 2, '.', ''), '0'), '.') }}-hour cap. Hours are logged by the student and approved by their course lecturer.</p>
                <div style="height: {{ 50 + $byStudentThisMonth->count() * 32 }}px;">
                    <canvas id="ta-hours-by-student" role="img"
                            aria-label="Hours this month by student: {{ $byStudentThisMonth->map(fn ($r) => $r['student']->name.' '.$r['hours'].' hrs')->implode(', ') }}"></canvas>
                </div>

                @push('scripts')
                    <script @cspNonce>
                        (function () {
                            var el = document.getElementById('ta-hours-by-student');
                            if (! el || typeof Chart === 'undefined') return;

                            var cap = @json($cap);
                            var hours = @json($byStudentThisMonth->pluck('hours'));
                            var colors = hours.map(function (h) {
                                return h > cap ? Chart.uresearchToken('--danger-fg', '#D0342C')
                                    : h >= cap - 3 ? Chart.uresearchToken('--warning-fg', '#C28E40')
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
                                        x: { beginAtZero: true, suggestedMax: cap + 3, grid: { color: function () { return Chart.uresearchToken('--border-grey', '#F1F3F7'); } }, border: { display: false } },
                                        y: { grid: { display: false }, border: { display: false } },
                                    },
                                },
                            });
                        })();
                    </script>
                @endpush
            @endif


        <div style="overflow-x: auto;">
            <table class="ta-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Hour(s)</th>
                        <th>Activity</th>
                        <th>Course Lecturer</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td>{{ $log->student->name }}</td>
                            <td>{{ $log->activity_date->format('j M Y') }}</td>
                            <td>{{ $log->time_in ? $log->time_in.'–'.$log->time_out : '—' }}</td>
                            <td>{{ rtrim(rtrim(number_format((float) $log->hours, 2, '.', ''), '0'), '.') }}</td>
                            <td>{{ $log->activityLabel() }}</td>
                            <td>{{ $log->lecturerDisplay() }}</td>
                            <td><span class="status-badge {{ $log->state }}">{{ $log->stateLabel() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="empty-state">No TA hours submitted yet.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top: 16px;">{{ $logs->links() }}</div>
    </div>
</div>
@endsection
