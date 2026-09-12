@extends('core::layouts.app')

@section('title', 'Dashboard')

@include('core::dashboard.partials.chartjs')

@section('content')
    <div class="welcome-banner">
        <h2>Welcome, {{ auth()->user()->name }}</h2>
        <p>{{ auth()->user()->roleLabel() }}@if (auth()->user()->department) &middot; {{ auth()->user()->department }}@endif</p>
    </div>

    <div class="stat-cards-row">
        <div class="stat-card accent-blue">
            <div class="stat-number">{{ $total }}</div>
            <div class="stat-label">Awaiting your action</div>
        </div>
        @foreach ($chart as $bar)
            <div class="stat-card accent-gold">
                <div class="stat-number">{{ $bar['count'] }}</div>
                <div class="stat-label">{{ $bar['label'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="dashboard-grid">
        <div class="chart-card">
            <h3>Applications Pending Your Action</h3>
            @if ($total === 0)
                <p style="color: var(--text-grey); font-size: 13px;">Your queues are clear.</p>
            @else
                <canvas id="dashboardChart"></canvas>
            @endif
        </div>

        <div>
            <h3 style="color: var(--navy); font-size: 15px; margin-top: 0;">Recent activity</h3>
            <table class="recent-activity-table">
                <thead>
                    <tr><th>#</th><th>Type</th><th>Student</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @forelse ($recent as $application)
                        <tr>
                            <td>{{ $application->id }}</td>
                            <td>{{ $application->module()->label() }}</td>
                            <td>{{ $application->student->name }}</td>
                            <td><x-core::status-badge :status="$application->status" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" style="color: var(--text-grey);">No activity yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('scripts')
    @if ($total > 0)
        <script @cspNonce>
            new Chart(document.getElementById('dashboardChart'), {
                type: 'bar',
                data: {
                    labels: @json(array_column($chart, 'label')),
                    datasets: [{
                        label: 'Pending',
                        data: @json(array_column($chart, 'count')),
                        backgroundColor: '#284B80',
                        borderRadius: 6,
                    }],
                },
                options: {
                    scales: { y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } } },
                    plugins: { legend: { display: false } },
                },
            });
        </script>
    @endif
@endpush
