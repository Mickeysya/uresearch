@extends('core::layouts.app')

@php($title = ($scope ? $modules[$scope] : 'Travel, Claims & Publication').': Reports & Analytics')

@section('title', $title)

@section('content')
    @include('core::dashboard.partials.chartjs')

    <x-core::page-header :title="$title"
                         subtitle="Applications in, where they wait, and how long each stage takes.">
        <a href="{{ route('norhanis.report.export', ['module' => $scope, 'period' => $period ?: null]) }}" class="btn-secondary">Export as CSV</a>
        <a href="{{ route('norhanis.overview', ['module' => $scope]) }}" class="btn-secondary">See the applications</a>
    </x-core::page-header>

    {{-- One row of filters above everything they change. --}}
    <form method="GET" action="{{ route('norhanis.report', ['module' => $scope]) }}" class="queue-tools">
        <div class="queue-tool-field">
            <label for="report-period">Submitted in</label>
            <select name="period" id="report-period">
                @foreach ($periods as $days => $label)
                    <option value="{{ $days }}" @selected($period === $days)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <button type="submit">Show</button>

        <span class="queue-meta">
            Module:
            <a href="{{ route('norhanis.report', ['period' => $period ?: null]) }}">All</a>
            @foreach ($choices as $slug => $label)
                &middot; <a href="{{ route('norhanis.report', ['module' => $slug, 'period' => $period ?: null]) }}">{{ $label }}</a>
            @endforeach
        </span>
    </form>

    {{-- ---- The totals -------------------------------------------------- --}}
    <div class="app-item" style="margin-top: 16px;">
        <h4>Applications and outcomes</h4>
        <table class="recent-activity-table">
            <tr>
                <th>Module</th>
                <th>Submitted</th>
                <th>Pending</th>
                <th>Approved</th>
                <th>Rejected</th>
                <th>Approval rate</th>
            </tr>
            @foreach ($modules as $slug => $label)
                @php($row = $totals[$slug])
                @php($decided = $row['approved'] + $row['rejected'])
                <tr>
                    <td>{{ $label }}</td>
                    <td><b>{{ array_sum($row) }}</b></td>
                    <td>{{ $row['pending'] }}</td>
                    <td>{{ $row['approved'] }}</td>
                    <td>{{ $row['rejected'] }}</td>
                    <td>{{ $decided ? round($row['approved'] / $decided * 100).'%' : 'None decided yet' }}</td>
                </tr>
            @endforeach
        </table>
        <p class="queue-meta">Approval rate is approved out of those decided; pending applications are not counted in it.</p>
    </div>

    {{-- The legend is HTML, shared by the three charts: Chart.js draws none
         in this app. The swatches are coloured by the script below, from the
         same values as the bars. --}}
    <p class="queue-meta" style="display: flex; flex-wrap: wrap; gap: 16px; margin-top: 16px;">
        @foreach ($modules as $slug => $label)
            <span style="display: inline-flex; align-items: center; gap: 6px;">
                <span data-series="{{ $slug }}" style="width: 12px; height: 12px; border-radius: 3px; display: inline-block;"></span>
                {{ $label }}
            </span>
        @endforeach
    </p>

    {{-- ---- Bottleneck -------------------------------------------------- --}}
    <div class="app-item">
        <h4>Where applications are waiting now</h4>
        <p class="queue-meta">Pending applications, by whose stage they are on. The tallest bar is where the queue is backing up.</p>

        <div class="chart-bars-wrap">
            <canvas id="report-waiting" role="img" aria-label="Pending applications at each approval stage, by module. The same figures are in the table below."></canvas>
        </div>

        <table class="recent-activity-table" style="margin-top: 12px;">
            <tr>
                <th>Module</th>
                @foreach ($stages as $stageRole => $stageLabel)
                    <th>{{ $stageLabel }}@if ($stageRole === $role) (you)@endif</th>
                @endforeach
            </tr>
            @foreach ($modules as $slug => $label)
                <tr>
                    <td>{{ $label }}</td>
                    @foreach ($stages as $stageRole => $stageLabel)
                        <td><a href="{{ route('norhanis.overview', ['module' => $slug, 'stage' => $stageRole]) }}">{{ $waiting[$slug][$stageRole] }}</a></td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    </div>

    {{-- ---- Time per stage ---------------------------------------------- --}}
    <div class="app-item">
        <h4>Average days spent on each stage</h4>
        <p class="queue-meta">From an application reaching a stage to that stage's decision, averaged over every decision made. A stage with no decisions yet has no figure.</p>

        <div class="chart-bars-wrap">
            <canvas id="report-days" role="img" aria-label="Average days an application spends on each approval stage, by module. The same figures are in the table below."></canvas>
        </div>

        <table class="recent-activity-table" style="margin-top: 12px;">
            <tr>
                <th>Module</th>
                @foreach ($stages as $stageLabel)
                    <th>{{ $stageLabel }}</th>
                @endforeach
            </tr>
            @foreach ($modules as $slug => $label)
                <tr>
                    <td>{{ $label }}</td>
                    @foreach ($stages as $stageRole => $stageLabel)
                        <td>{{ $average[$slug][$stageRole] === null ? 'No decisions' : $average[$slug][$stageRole].' days' }}</td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    </div>

    {{-- ---- Intake ------------------------------------------------------ --}}
    <div class="app-item">
        <h4>Applications submitted each month</h4>

        <div class="chart-bars-wrap">
            <canvas id="report-months" role="img" aria-label="Applications submitted in each of the last six months, by module. The same figures are in the table below."></canvas>
        </div>

        <table class="recent-activity-table" style="margin-top: 12px;">
            <tr>
                <th>Module</th>
                @foreach ($months['labels'] as $month)
                    <th>{{ $month }}</th>
                @endforeach
            </tr>
            @foreach ($modules as $slug => $label)
                <tr>
                    <td>{{ $label }}</td>
                    @foreach ($months['series'][$slug] as $count)
                        <td>{{ $count }}</td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    </div>

    {{-- ---- Longest waiting --------------------------------------------- --}}
    <div class="app-item">
        <h4>Waiting longest on their current stage</h4>

        @if ($longest->isEmpty())
            <p class="queue-meta">Nothing is pending.</p>
        @else
            <div style="overflow-x: auto;">
                <table class="recent-activity-table">
                    <tr>
                        <th>No.</th>
                        <th>Student</th>
                        <th>Module</th>
                        <th>Now with</th>
                        <th>Waiting there</th>
                    </tr>
                    @foreach ($longest as $row)
                        <tr>
                            <td>#{{ $row['application']->id }}</td>
                            <td>
                                <b>{{ $row['application']->student?->name ?? 'Unknown student' }}</b>
                                @if ($row['application']->student?->matric_no)
                                    <br><span style="color: var(--text-grey);">{{ $row['application']->student->matric_no }}</span>
                                @endif
                            </td>
                            <td>{{ $row['application']->module()->label() }}</td>
                            <td>{{ $row['application']->currentStage()?->label }}</td>
                            <td>{{ $row['days'] }} days</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
    </div>
@endsection

@push('scripts')
    <script @cspNonce>
    (function () {
        if (typeof Chart === 'undefined') return;

        var report = {{ Illuminate\Support\Js::from($chart) }};

        // One colour per module, fixed to the module and not to its position,
        // so showing a single module does not repaint it. Each is a pair:
        // the step for the light surface, then the one for the dark surface.
        var palette = {
            travel: ['#2a78d6', '#3987e5'],
            claims: ['#eb6834', '#d95926'],
            publication: ['#1baf7a', '#199e70']
        };

        function dark() {
            var theme = document.documentElement.getAttribute('data-theme');
            return theme ? theme === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
        }

        // Functions, not values: Chart.js re-reads them when the theme changes.
        function colour(slug) { return function () { return palette[slug][dark() ? 1 : 0]; }; }
        function token(name, fallback) { return function () { return Chart.uresearchToken(name, fallback); }; }

        function paintLegend() {
            document.querySelectorAll('[data-series]').forEach(function (swatch) {
                swatch.style.background = palette[swatch.getAttribute('data-series')][dark() ? 1 : 0];
            });
        }

        paintLegend();
        new MutationObserver(paintLegend).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

        var slugs = Object.keys(report.modules);
        var axes = {
            x: { grid: { display: false } },
            y: { beginAtZero: true, grid: { color: token('--border-subtle', '#EDF0F4') }, border: { display: false } }
        };

        function bars(id, series, unit, whole) {
            var canvas = document.getElementById(id);
            if (! canvas) return;

            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: report.stages,
                    datasets: slugs.map(function (slug) {
                        return {
                            label: report.modules[slug],
                            data: series[slug],
                            backgroundColor: colour(slug),
                            borderRadius: 4,
                            maxBarThickness: 26,
                            categoryPercentage: 0.7,
                            barPercentage: 0.85
                        };
                    })
                },
                options: {
                    scales: { x: axes.x, y: Object.assign({}, axes.y, whole ? { ticks: { precision: 0 } } : {}) },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function (item) { return item.dataset.label + ': ' + item.formattedValue + ' ' + unit; }
                            }
                        }
                    }
                }
            });
        }

        bars('report-waiting', report.waiting, 'pending', true);
        bars('report-days', report.days, 'days', false);

        var months = document.getElementById('report-months');

        if (months) {
            new Chart(months, {
                type: 'line',
                data: {
                    labels: report.months.labels,
                    datasets: slugs.map(function (slug) {
                        return {
                            label: report.modules[slug],
                            data: report.months.series[slug],
                            borderColor: colour(slug),
                            backgroundColor: colour(slug),
                            borderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            tension: 0
                        };
                    })
                },
                options: {
                    interaction: { mode: 'index', intersect: false },
                    scales: { x: axes.x, y: Object.assign({}, axes.y, { ticks: { precision: 0 } }) }
                }
            });
        }
    })();
    </script>
@endpush
