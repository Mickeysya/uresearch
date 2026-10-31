@extends('core::layouts.app')

@section('title', 'Processing Time Analytics')

@section('content')
@php
    // One colour per module, reused for both the chart and the overdue
    // table's module column, so the same module reads the same colour
    // everywhere on the page.
    $moduleTones = [
        'GA Extension' => '#5B8FD4',
        'Supervision Request' => '#00BF63',
        'Certification Letter' => '#E9B23C',
    ];
    $chartId = 'nureen-processing-'.uniqid();
    $charted = $compliance->filter(fn ($row) => $row['charter_days'] !== null)->values();
@endphp

@include('core::dashboard.partials.chartjs')

<div class="card-container-inline">
    <x-core::page-header
        title="Processing Time Analytics"
        subtitle="How each approval stage of GA Extension, Supervision and Certification performs against CGS's Client Charter, in working days, and what is past its charter right now." />

    <div class="card card-wide" style="margin-bottom: var(--space-6);">
        <h3 style="margin-top: 0;">Decisions within the Client Charter</h3>
        <p class="queue-meta" style="margin-top: -8px;">
            Every decision recorded at each stage, counted in working days (Monday to Friday, excluding
            configured public holidays) from the day the application reached that stage.
        </p>

        @if ($compliance->isEmpty())
            <p class="queue-meta">No decisions recorded yet, so there is nothing to measure.</p>
        @else
            <div class="table-scroll">
                <table class="spec-table">
                    <thead>
                        <tr>
                            <th>Module</th>
                            <th>Stage</th>
                            <th>Decisions</th>
                            <th>Within Charter</th>
                            <th>Average Taken</th>
                            <th>Client Charter</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($compliance as $row)
                            <tr>
                                <td>{{ $row['module_label'] }}</td>
                                <td>{{ $row['stage_label'] }}</td>
                                <td>{{ $row['decisions'] }}</td>
                                <td>
                                    @if ($row['within_charter_percent'] === null)
                                        <span class="queue-meta">No charter</span>
                                    @else
                                        <span style="font-weight: var(--weight-semi); color: {{ $row['within_charter_percent'] >= 80 ? 'var(--success-fg)' : ($row['within_charter_percent'] >= 50 ? 'var(--warning-fg)' : 'var(--danger-fg)') }};">
                                            {{ rtrim(rtrim(number_format($row['within_charter_percent'], 1), '0'), '.') }}%
                                        </span>
                                        <span class="queue-meta">({{ $row['within_charter'] }} of {{ $row['decisions'] }})</span>
                                    @endif
                                </td>
                                <td>{{ $row['average_working_days'] }} {{ Str::plural('working day', $row['average_working_days'] == 1 ? 1 : 2) }}</td>
                                <td>
                                    @if ($row['charter_days'] === null)
                                        <span class="queue-meta">Not set</span>
                                    @else
                                        {{ $row['charter_days'] }} {{ Str::plural('working day', $row['charter_days']) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card card-wide" style="margin-bottom: var(--space-6);">
        <h3 style="margin-top: 0;">Average Working Days vs Client Charter</h3>

        @if ($charted->isEmpty())
            <p class="queue-meta">No decisions recorded yet at a stage with a Client Charter.</p>
        @else
            {{-- Chart.js never draws a legend in this app (see
                 chartjs.blade.php); every panel writes its own. --}}
            <p class="queue-meta" style="display: flex; flex-wrap: wrap; gap: var(--space-4); margin-top: -8px;">
                <span><span aria-hidden="true" style="display: inline-block; width: 10px; height: 10px; border-radius: 2px; background: #5B8FD4; margin-right: 6px;"></span>Average taken (coloured by module)</span>
                <span><span aria-hidden="true" style="display: inline-block; width: 10px; height: 10px; border-radius: 2px; background: var(--border-strong); margin-right: 6px;"></span>Client Charter</span>
            </p>
            <div style="position: relative; height: 340px;">
                <canvas id="{{ $chartId }}" role="img"
                        aria-label="Average working days against the Client Charter: {{ $charted->map(fn ($a) => $a['module_label'].' '.$a['stage_label'].' '.$a['average_working_days'].' taken, charter '.$a['charter_days'])->implode('; ') }}"></canvas>
            </div>
        @endif
    </div>

    <div class="card card-wide">
        <h3 style="margin-top: 0;">Overdue Applications</h3>
        <p class="queue-meta" style="margin-top: -8px;">
            Past the current stage's Client Charter due date: the same applications the reminder
            commands are emailing about. A stage with no charter uses its reminder threshold instead, marked below.
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
                            <th>Reached Stage</th>
                            <th>Due</th>
                            <th>Working Days Over</th>
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
                                <td>
                                    {{ $row['application']->student?->name ?? '—' }}
                                    @if ($row['application']->student?->matric_no)
                                        <span class="queue-meta">{{ $row['application']->student->matric_no }}</span>
                                    @endif
                                </td>
                                <td>{{ $row['stage_label'] }}</td>
                                <td>{{ $row['entered_at']->format('j M Y') }}</td>
                                <td>
                                    {{ $row['due_date']->format('j M Y') }}
                                    @unless ($row['on_charter'])
                                        <span class="queue-meta">(reminder threshold, no charter)</span>
                                    @endunless
                                </td>
                                <td style="color: var(--danger-fg); font-weight: var(--weight-semi);">
                                    {{ $row['working_days_overdue'] }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

@if ($charted->isNotEmpty())
    @push('scripts')
        <script @cspNonce>
            (function () {
                var el = document.getElementById(@json($chartId));
                if (! el || typeof Chart === 'undefined') return;

                // Two datasets side by side (average taken, charter) in
                // the same plain vertical bar, matching the admin dashboard's
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
                        labels: @json($charted->map(fn ($a) => "{$a['module_label']} {$a['stage_label']}")),
                        datasets: [{
                            label: 'Average taken',
                            data: @json($charted->pluck('average_working_days')),
                            backgroundColor: @json($charted->map(fn ($a) => $moduleTones[$a['module_label']] ?? '#8A94A6')),
                            borderRadius: 5,
                            borderSkipped: false,
                            maxBarThickness: 40,
                        }, {
                            // A function, not a value: re-read on every
                            // update(), so the theme-change repaint in
                            // chartjs.blade.php recolours it.
                            label: 'Client Charter',
                            data: @json($charted->pluck('charter_days')),
                            backgroundColor: function () { return Chart.uresearchToken('--border-strong', '#C5CBD6'); },
                            borderRadius: 5,
                            borderSkipped: false,
                            maxBarThickness: 40,
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
                                title: { display: true, text: 'Working days' },
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
                                        var days = ctx.parsed.y + ' working day' + (ctx.parsed.y === 1 ? '' : 's');
                                        return ' ' + (ctx.datasetIndex === 0 ? days + ' on average' : 'Charter: ' + days);
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
