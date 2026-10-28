@extends('core::layouts.app')

@section('title', 'Processing Time Analytics')

@section('content')
@php
    // One colour per module, reused for both the chart and the overdue
    // table's module column, so the same module reads the same colour
    // everywhere on the page.
    $moduleTones = [
        'GA Extension' => '#5B8FD4',
        'Supervision' => '#00BF63',
        'Certification Letter' => '#E9B23C',
    ];
    $chartId = 'nureen-processing-'.uniqid();
@endphp

@include('core::dashboard.partials.chartjs')

<div class="card-container-inline">
    <x-core::page-header
        title="Processing Time Analytics"
        subtitle="Average days per stage, and applications waiting longer than their reminder threshold, across GA Extension, Supervision and Certification." />

    <div class="card card-wide" style="margin-bottom: var(--space-6);">
        <h3 style="margin-top: 0;">Average Days per Stage</h3>

        @if ($averages->isEmpty())
            <p class="queue-meta">No decisions recorded yet, so there is nothing to average.</p>
        @else
            <div style="position: relative; height: 340px;">
                <canvas id="{{ $chartId }}" role="img"
                        aria-label="Average days per stage: {{ $averages->map(fn ($a) => $a['module_label'].' '.$a['stage_label'].' '.$a['average_days'].' days')->implode(', ') }}"></canvas>
            </div>
        @endif
    </div>

    <div class="card card-wide">
        <h3 style="margin-top: 0;">Overdue Applications</h3>
        <p class="queue-meta" style="margin-top: -8px;">
            Pending longer than its module's reminder threshold, the same applications the
            stalled-reminder commands are emailing about.
        </p>

        @if ($overdue->isEmpty())
            <p class="queue-meta">Nothing is currently overdue.</p>
        @else
            <div class="table-scroll">
                <table class="spec-table">
                    <thead>
                        <tr>
                            <th>Module</th>
                            <th>Application</th>
                            <th>Student</th>
                            <th>Stage</th>
                            <th>Days Waiting</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($overdue as $row)
                            <tr>
                                <td>{{ $row['module_label'] }}</td>
                                <td>
                                    <a href="{{ route('applications.show', $row['application']) }}">
                                        #{{ $row['application']->id }}
                                    </a>
                                </td>
                                <td>{{ $row['application']->student?->name ?? '—' }}</td>
                                <td>{{ $row['stage_label'] }}</td>
                                <td style="color: var(--danger-fg); font-weight: var(--weight-semi);">
                                    {{ $row['days_waiting'] }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

@if ($averages->isNotEmpty())
    @push('scripts')
        <script @cspNonce>
            (function () {
                var el = document.getElementById(@json($chartId));
                if (! el || typeof Chart === 'undefined') return;

                // A plain vertical bar, matching the admin dashboard's
                // Applications-by-Status chart exactly (same scale shape,
                // same datalabel anchor/align, same tick-wrap callback) --
                // a horizontal (indexAxis: 'y') variant with two-line array
                // labels looked right on first paint but the bars vanished
                // after the first theme-change repaint (Chart.instances[key]
                // .update('none') in chartjs.blade.php's retheme()); this
                // shape is already proven across both themes.
                new Chart(el, {
                    type: 'bar',
                    data: {
                        labels: @json($averages->map(fn ($a) => "{$a['module_label']} {$a['stage_label']}")),
                        datasets: [{
                            label: 'Average days',
                            data: @json($averages->pluck('average_days')),
                            backgroundColor: @json($averages->map(fn ($a) => $moduleTones[$a['module_label']] ?? '#8A94A6')),
                            borderRadius: 5,
                            borderSkipped: false,
                            maxBarThickness: 54,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        layout: { padding: { top: 18 } },
                        animation: { duration: 650, easing: 'easeOutQuart' },
                        scales: {
                            x: {
                                grid: { display: false },
                                border: { display: false },
                                ticks: {
                                    autoSkip: false,
                                    maxRotation: 0,
                                    callback: function (value) {
                                        var label = this.getLabelForValue(value);
                                        return label.length > 12 ? label.split(' ') : label;
                                    },
                                },
                            },
                            y: {
                                beginAtZero: true,
                                border: { display: false },
                                grid: { color: function () { return Chart.uresearchToken('--border-grey', '#F1F3F7'); } },
                                ticks: { precision: 1, maxTicksLimit: 6 },
                                title: { display: true, text: 'Average days' },
                            },
                        },
                        plugins: {
                            legend: { display: false },
                            datalabels: {
                                display: true,
                                anchor: 'end',
                                align: 'top',
                                offset: 2,
                                color: function () { return Chart.uresearchToken('--text-body', '#23283A'); },
                                font: { size: 11, weight: '700' },
                                formatter: function (value) { return value + 'd'; },
                            },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        return ' ' + ctx.parsed.y + ' day' + (ctx.parsed.y === 1 ? '' : 's') + ' on average';
                                    },
                                },
                            },
                        },
                    },
                });
            })();
        </script>
    @endpush
@endif
@endsection
