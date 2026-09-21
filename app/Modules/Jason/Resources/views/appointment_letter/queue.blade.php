@extends('core::layouts.app')

@section('title', 'Appointment Letter: ' . $stage->queueTitle())

@section('content')
    {{-- Core's layout flashes session messages but never renders the
         validation error bag, and nothing may print above the page header,
         so a failed rule is folded into the intro the partial renders. --}}
    @php
        $errorLine = $errors->any()
            ? '<b class="field-error">'.e(implode(' ', $errors->all())).'</b> '
            : '';
    @endphp

    @include('core::partials.queue', [
        'moduleLabel' => 'Appointment Letter',
        'decideRoute' => 'appointment-letter.decide',
        'detailView' => 'jason::appointment_letter._detail',
        'intro' => $errorLine !== '' ? $errorLine : null,
    ])

    {{-- Below the list, not above the page title. It is context on the
         queue, so it reads after the queue. --}}
    @if (collect($workload)->sum('count') > 0)
        @include('core::dashboard.partials.chartjs')
        <div class="chart-card" style="margin-top: var(--space-6);">
            <h3>Pending Nominations by Approver</h3>
            <canvas id="workloadChart"></canvas>
        </div>
    @endif
@endsection

@push('scripts')
    @if (collect($workload)->sum('count') > 0)
        {{-- Chart.js comes from core::dashboard.partials.chartjs (public/js), not
             a CDN: script-src names no external origin, so the CDN copy was
             blocked and this chart never drew. The nonce is required for the
             same reason, and the bar colour reads a token so it survives the
             dark theme. --}}
        <script @cspNonce>
            (function () {
                var el = document.getElementById('workloadChart');
                if (! el || typeof Chart === 'undefined') return;

                new Chart(el, {
                    type: 'bar',
                    data: {
                        labels: @json(array_column($workload, 'label')),
                        datasets: [{
                            label: 'Pending',
                            data: @json(array_column($workload, 'count')),
                            backgroundColor: function () { return Chart.uresearchToken('--navy', '#284B80'); },
                            borderRadius: 6,
                        }],
                    },
                    options: {
                        scales: { y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } } },
                        plugins: { legend: { display: false } },
                    },
                });
            })();
        </script>
    @endif
@endpush
