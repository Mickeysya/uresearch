@extends('core::layouts.app')

@section('title', 'Appointment Letter — ' . $stage->queueTitle())

@section('content')
    @if ($errors->any())
        {{-- Core's layout flashes session messages but never renders the
             validation error bag, and the shared queue partial has no slot for
             it, so a failed rule would bounce the reviewer back to an unchanged
             page with no explanation. --}}
        <div class="message-error">
            @foreach ($errors->all() as $message)
                <p style="margin: 0;">{{ $message }}</p>
            @endforeach
        </div>
    @endif

    @if (collect($workload)->sum('count') > 0)
        <div class="chart-card" style="margin-bottom: 20px;">
            <h3>Pending Nominations by Approver</h3>
            <canvas id="workloadChart"></canvas>
        </div>
    @endif

    @include('core::partials.queue', [
        'moduleLabel' => 'Appointment Letter',
        'decideRoute' => 'appointment-letter.decide',
        'detailView' => 'jason::appointment_letter._detail',
    ])
@endsection

@push('scripts')
    @if (collect($workload)->sum('count') > 0)
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script>
            new Chart(document.getElementById('workloadChart'), {
                type: 'bar',
                data: {
                    labels: @json(array_column($workload, 'label')),
                    datasets: [{
                        label: 'Pending',
                        data: @json(array_column($workload, 'count')),
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
