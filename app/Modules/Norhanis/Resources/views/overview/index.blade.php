@extends('core::layouts.app')

@php
    $title = $scope ? $modules[$scope].' Applications' : 'Travel, Claims & Publication Applications';
@endphp

@section('title', $title)

@section('content')
    @include('core::dashboard.partials.chartjs')

    <x-core::page-header :title="$title" :subtitle="$subtitle">
        @if (in_array($role, \App\Modules\Norhanis\Http\Controllers\ApplicationReportController::REPORT_ROLES, true))
            <a href="{{ route('norhanis.report', ['module' => $scope]) }}" class="btn-secondary">Reports &amp; analytics</a>
        @endif
    </x-core::page-header>

    {{-- ---- Dashboard ------------------------------------------------------
         A summary of what this reader may see -- the same rows as the list
         below, never more. Every figure, bar and slice is a link that filters
         the list to exactly those rows. The deeper analysis (days per stage,
         monthly intake, export) stays on Reports & analytics. --}}
    @php
        $total = array_sum($totals);
        $cards = [
            ['tone' => 'purple', 'icon' => 'doc', 'label' => 'Total Applications', 'count' => $total,
             'pill' => 'View all', 'pillTone' => 'info', 'url' => route('norhanis.overview', ['module' => $scope])],
            ['tone' => 'orange', 'icon' => 'clock', 'label' => 'Pending', 'count' => $totals['pending'],
             'pill' => 'View pending', 'pillTone' => 'warn', 'url' => route('norhanis.overview', ['module' => $scope, 'status' => 'pending'])],
            ['tone' => 'green', 'icon' => 'check', 'label' => 'Approved', 'count' => $totals['approved'],
             'pill' => 'View approved', 'pillTone' => 'good', 'url' => route('norhanis.overview', ['module' => $scope, 'status' => 'approved'])],
            ['tone' => 'red', 'icon' => 'cross', 'label' => 'Rejected', 'count' => $totals['rejected'],
             'pill' => 'View rejected', 'pillTone' => 'critical', 'url' => route('norhanis.overview', ['module' => $scope, 'status' => 'rejected'])],
        ];

        // Only for a reader who holds a stage in what is shown.
        if (isset($waiting[$role])) {
            $cards[] = ['tone' => 'blue', 'icon' => 'person', 'label' => 'Waiting on You', 'count' => $waiting[$role],
                        'pill' => 'View mine', 'pillTone' => 'info', 'url' => route('norhanis.overview', ['module' => $scope, 'stage' => $role])];
        }
    @endphp

    <div class="sdash-stats ovw-stats">
        @foreach ($cards as $card)
            <div class="sdash-stat tone-{{ $card['tone'] }}">
                <div class="sdash-stat-top">
                    <span class="sdash-stat-icon" aria-hidden="true">
                        @include('core::dashboard.partials.icon', ['name' => $card['icon']])
                    </span>
                    <span class="sdash-stat-label">{{ $card['label'] }}</span>
                </div>
                <span class="sdash-stat-value">{{ number_format($card['count']) }}</span>
                <a href="{{ $card['url'] }}" class="sdash-pill pill-{{ $card['pillTone'] }}">{{ $card['pill'] }}</a>
            </div>
        @endforeach
    </div>

    <div class="ovw-charts">
        @if ($mine)
        {{-- Anyone but CGS: their own decisions. Where an application went
             after they passed it on is in the list's "Now with" column. --}}
        <div class="app-item">
            <h4>My decisions</h4>
            <p class="queue-meta">What is waiting for you, and what you have already decided. Click a bar to list them.</p>

            <div class="chart-bars-wrap ovw-bars">
                <canvas id="overview-mine" role="img" aria-label="Applications waiting on you, passed on by you and rejected by you. The same figures are listed below the chart."></canvas>
            </div>

            <p class="ovw-figures">
                @foreach ($mine['labels'] as $i => $label)
                    <a href="{{ $mine['links'][$i] }}">{{ $label }}: <b>{{ $mine['counts'][$i] }}</b></a>
                @endforeach
            </p>
        </div>
        @else
        <div class="app-item">
            <h4>Pending, waiting for</h4>
            <p class="queue-meta">Pending applications by whose stage they are on. The tallest bar is where they are backing up; click one to list them.</p>

            <div class="chart-bars-wrap ovw-bars">
                <canvas id="overview-waiting" role="img" aria-label="Pending applications at each approval stage. The same figures are listed below the chart."></canvas>
            </div>

            {{-- The figures as text and links too: for a screen reader, and
                 for anyone who would rather read than hover. --}}
            <p class="ovw-figures">
                @foreach ($stages as $stageRole => $stageLabel)
                    <a href="{{ route('norhanis.overview', ['module' => $scope, 'stage' => $stageRole]) }}" @class(['is-mine' => $stageRole === $role])>
                        {{ $stageLabel }}@if ($stageRole === $role) (you)@endif: <b>{{ $waiting[$stageRole] }}</b>
                    </a>
                @endforeach
            </p>

            @if (! $scope && count($modules) > 1)
                <p class="queue-meta ovw-legend">
                    @foreach ($modules as $slug => $label)
                        <span><span data-series="{{ $slug }}" class="ovw-swatch"></span>{{ $label }}</span>
                    @endforeach
                </p>
            @endif
        </div>
        @endif

        <div class="app-item">
            <h4>Outcomes</h4>
            <p class="queue-meta">{{ $outcomesNote }}</p>

            <div class="ovw-donut">
                <div class="chart-donut-wrap">
                    @if ($total === 0)
                        <div class="chart-donut-empty"></div>
                    @else
                        <canvas id="overview-status" role="img" aria-label="Applications by status. The same figures are listed beside the chart."></canvas>
                    @endif
                    <div class="chart-donut-centre">
                        <b>{{ number_format($total) }}</b>
                        <span class="queue-meta">submitted</span>
                    </div>
                </div>

                <ul class="ovw-status-list">
                    @foreach ($statuses as $status)
                        <li>
                            <span data-status="{{ $status }}" class="ovw-swatch"></span>
                            <a href="{{ route('norhanis.overview', ['module' => $scope, 'status' => $status]) }}">{{ ucfirst($status) }}</a>
                            <b>{{ $totals[$status] }}</b>
                            <span class="queue-meta">{{ $total ? round($totals[$status] / $total * 100) : 0 }}%</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>

    <form method="GET" action="{{ route('norhanis.overview', ['module' => $scope]) }}" class="queue-tools" style="margin-top: 20px;">
        <div class="queue-tool-field">
            <label for="overview-q">Student name, matric no. or application no.</label>
            <input type="search" name="q" id="overview-q" value="{{ $filters['q'] }}" placeholder="e.g. Aina, 22001001 or #42">
        </div>

        @unless ($scope)
            <div class="queue-tool-field">
                <label for="overview-module">Module</label>
                <select name="module" id="overview-module">
                    <option value="">All</option>
                    @foreach ($modules as $slug => $label)
                        <option value="{{ $slug }}" @selected($filters['module'] === $slug)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @endunless

        <div class="queue-tool-field">
            <label for="overview-status">Status</label>
            <select name="status" id="overview-status">
                <option value="">Any</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>

        <div class="queue-tool-field">
            <label for="overview-stage">Waiting for</label>
            <select name="stage" id="overview-stage">
                <option value="">Anyone</option>
                @foreach ($stages as $stageRole => $stageLabel)
                    <option value="{{ $stageRole }}" @selected($filters['stage'] === $stageRole)>{{ $stageLabel }}@if ($stageRole === $role) (my stage)@endif</option>
                @endforeach
            </select>
        </div>

        @if ($mine)
            <div class="queue-tool-field">
                <label for="overview-decided">My decision</label>
                <select name="decided" id="overview-decided">
                    <option value="">Any</option>
                    <option value="passed" @selected($filters['decided'] === 'passed')>{{ $mine['labels'][1] }}</option>
                    <option value="rejected" @selected($filters['decided'] === 'rejected')>{{ $mine['labels'][2] }}</option>
                </select>
            </div>
        @endif

        <div class="queue-tool-field">
            <label for="overview-sort">Order</label>
            <select name="sort" id="overview-sort">
                <option value="newest" @selected($filters['sort'] === 'newest')>Newest first</option>
                <option value="oldest" @selected($filters['sort'] === 'oldest')>Oldest first</option>
            </select>
        </div>

        <button type="submit">Show</button>
        <a href="{{ route('norhanis.overview', ['module' => $scope]) }}" class="queue-clear">Clear</a>
    </form>

    <p class="queue-meta" style="margin-top: 12px;">
        @if ($applications->total() === 0)
            No applications match.
        @else
            Showing {{ $applications->firstItem() }} to {{ $applications->lastItem() }} of {{ $applications->total() }}.
        @endif
    </p>

    @if ($applications->isNotEmpty())
        <div style="overflow-x: auto;">
            <table class="recent-activity-table">
                <tr>
                    <th>No.</th>
                    <th>Student</th>
                    @unless ($scope) <th>Module</th> @endunless
                    <th>Application</th>
                    <th>Status</th>
                    <th>Now with</th>
                    <th>Submitted</th>
                    <th></th>
                </tr>
                @foreach ($applications as $application)
                    @php
                        $module = $application->module();
                        $stage = $application->isOpen() ? $application->currentStage() : null;
                    @endphp
                    <tr>
                        <td>#{{ $application->id }}</td>
                        <td>
                            <b>{{ $application->student?->name ?? 'Unknown student' }}</b>
                            @if ($application->student?->matric_no)
                                <br><span style="color: var(--text-grey);">{{ $application->student->matric_no }}</span>
                            @endif
                        </td>
                        @unless ($scope) <td>{{ $module->label() }}</td> @endunless
                        <td>{{ $module->summary($application) }}</td>
                        <td><x-core::status-badge :status="$application->status" /></td>
                        <td>{{ $stage?->label ?? 'Closed' }}</td>
                        <td>{{ $application->submitted_at?->format('j M Y') }}</td>
                        <td>
                            {{-- Only when it is on this reader's own stage: that
                                 is the one place they can act on it. --}}
                            <span style="display: inline-flex; flex-wrap: wrap; gap: 6px;">
                                @if ($stage && $stage->role === $role)
                                    <a href="{{ route($module->queueRoute(), ['stage' => $stage->key, 'q' => '#'.$application->id]) }}" class="btn-secondary">Review</a>
                                @endif
                                {{-- Every row here is one this reader may see, so
                                     the role decides the downloads: CGS gets the
                                     summary and all files, the supervisor only the
                                     student's files, Chair and Dean neither. --}}
                                @if (in_array($role, \App\Modules\Norhanis\Http\Controllers\ApplicationPackController::SUMMARY_ROLES, true))
                                    <a href="{{ route('norhanis.pack.pdf', $application) }}" class="btn-secondary" title="Summary of the application as a PDF">PDF</a>
                                    <a href="{{ route('norhanis.pack.zip', $application) }}" class="btn-secondary" title="The summary and every file you may open, as a ZIP">ZIP</a>
                                @elseif ($role === \App\Modules\Core\Support\Role::SUPERVISOR)
                                    <a href="{{ route('norhanis.pack.zip', $application) }}" class="btn-secondary" title="The files your student uploaded, as a ZIP">Files</a>
                                @endif
                            </span>
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>

        {{-- The app's own pager, as the queues use. Laravel's default one is
             Tailwind markup, and without Tailwind its arrow icons fill the page. --}}
        @include('core::partials.pagination', ['paginator' => $applications, 'label' => 'Application pages'])
    @endif
@endsection

@push('scripts')
    <style>
        /* The stat cards are the dashboards' own; --sdash-gap is set by the
           dashboard wrapper there, so it is set again here. */
        .ovw-stats { --sdash-gap: 16px; justify-content: flex-start; }
        .ovw-stats .sdash-stat { max-width: none; }

        .ovw-charts {
            display: grid;
            grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
            gap: 16px;
            margin-top: 16px;
        }

        .ovw-charts > .app-item { padding: var(--space-5); }
        .ovw-charts h4 { margin: 0 0 var(--space-1); }
        .ovw-bars { height: 220px; }

        .ovw-figures {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-2) var(--space-4);
            margin: var(--space-3) 0 0;
            font-size: var(--text-sm);
        }

        .ovw-figures a.is-mine { font-weight: var(--weight-semi); }

        .ovw-legend { display: flex; flex-wrap: wrap; gap: var(--space-4); margin: var(--space-2) 0 0; }
        .ovw-legend > span { display: inline-flex; align-items: center; gap: 6px; }

        .ovw-swatch { width: 12px; height: 12px; border-radius: 3px; display: inline-block; flex: 0 0 auto; }

        .ovw-donut { display: flex; align-items: center; gap: var(--space-6); flex-wrap: wrap; margin-top: var(--space-3); }

        .ovw-status-list { list-style: none; margin: 0; padding: 0; display: grid; gap: var(--space-2); }
        .ovw-status-list li { display: flex; align-items: center; gap: var(--space-2); }

        @media (max-width: 900px) {
            .ovw-charts { grid-template-columns: minmax(0, 1fr); }
        }
    </style>

    <script @cspNonce>
    (function () {
        var data = {{ Illuminate\Support\Js::from($chart) }};

        function dark() {
            var theme = document.documentElement.getAttribute('data-theme');
            return theme ? theme === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
        }

        // The same colour per module as Reports & analytics, so a module
        // reads the same on both pages: [light surface, dark surface].
        var modulePalette = {
            travel: ['#2a78d6', '#3987e5'],
            claims: ['#eb6834', '#d95926'],
            publication: ['#1baf7a', '#199e70']
        };

        // Statuses take the stat cards' tones, so a slice matches its card.
        var statusTokens = { pending: ['--tone-orange', '#F08A24'], approved: ['--tone-green', '#00A857'], rejected: ['--tone-red', '#D0342C'] };

        function moduleColour(slug) { return modulePalette[slug] ? modulePalette[slug][dark() ? 1 : 0] : '#2a78d6'; }
        function statusColour(status) {
            var t = statusTokens[status];
            return typeof Chart !== 'undefined' && Chart.uresearchToken ? Chart.uresearchToken(t[0], t[1]) : t[1];
        }

        function paintSwatches() {
            document.querySelectorAll('[data-series]').forEach(function (el) { el.style.background = moduleColour(el.getAttribute('data-series')); });
            document.querySelectorAll('[data-status]').forEach(function (el) { el.style.background = statusColour(el.getAttribute('data-status')); });
        }

        paintSwatches();
        new MutationObserver(paintSwatches).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

        if (typeof Chart === 'undefined') return;

        // A bar or slice is a link to the rows behind it.
        function follow(links) {
            return {
                onClick: function (event, elements) {
                    if (elements.length) window.location.href = links[elements[0].index];
                },
                onHover: function (event, elements) {
                    event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
                }
            };
        }

        var slugs = Object.keys(data.modules);
        var bars = document.getElementById('overview-waiting');

        if (bars) {
            new Chart(bars, {
                type: 'bar',
                data: {
                    labels: data.stages,
                    datasets: slugs.map(function (slug) {
                        return {
                            label: data.modules[slug],
                            data: data.waiting[slug],
                            backgroundColor: function () { return moduleColour(slug); },
                            borderRadius: 4,
                            maxBarThickness: 40
                        };
                    })
                },
                options: Object.assign({
                    scales: {
                        x: { stacked: true, grid: { display: false } },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            ticks: { precision: 0 },
                            border: { display: false },
                            grid: { color: function () { return Chart.uresearchToken('--border-subtle', '#EDF0F4'); } }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function (item) { return item.dataset.label + ': ' + item.formattedValue + ' pending'; }
                            }
                        }
                    }
                }, follow(data.stageLinks))
            });
        }

        var mine = document.getElementById('overview-mine');

        if (mine && data.mine) {
            // Waiting, passed on, rejected: the same tones as the stat cards.
            var mineTones = ['pending', 'approved', 'rejected'];

            new Chart(mine, {
                type: 'bar',
                data: {
                    labels: data.mine.labels,
                    datasets: [{
                        label: 'Applications',
                        data: data.mine.counts,
                        backgroundColor: function (context) { return statusColour(mineTones[context.dataIndex]); },
                        borderRadius: 4,
                        maxBarThickness: 56
                    }]
                },
                options: Object.assign({
                    scales: {
                        x: { grid: { display: false } },
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0 },
                            border: { display: false },
                            grid: { color: function () { return Chart.uresearchToken('--border-subtle', '#EDF0F4'); } }
                        }
                    }
                }, follow(data.mine.links))
            });
        }

        var donut = document.getElementById('overview-status');

        if (donut) {
            var keys = Object.keys(statusTokens);

            new Chart(donut, {
                type: 'doughnut',
                data: {
                    labels: data.statuses,
                    datasets: [{
                        data: data.statusTotals,
                        // A function, so Chart.js re-reads it when the theme changes.
                        backgroundColor: function (context) { return statusColour(keys[context.dataIndex]); },
                        borderWidth: 0
                    }]
                },
                options: Object.assign({ cutout: '68%' }, follow(data.statusLinks))
            });
        }
    })();
    </script>
@endpush
