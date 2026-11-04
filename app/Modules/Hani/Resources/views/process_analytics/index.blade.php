@extends('core::layouts.app')

@section('title', 'Process Analytics')

@section('content')
@include('core::dashboard.partials.chartjs')

{{-- Page-scoped, tokens only, so light and dark both work (as the Examiner Dashboard). --}}
<style>
    .pa-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 16px; margin-top: 16px; }
    .pa-card { background: var(--surface); border: 1px solid var(--border-subtle); border-radius: 12px; padding: 16px 18px; min-width: 0; }
    .pa-card h3 { margin: 0 0 4px; color: var(--navy); font-size: var(--text-md); }
    .pa-card h4 { margin: 14px 0 6px; color: var(--navy); font-size: var(--text-sm); }
    .pa-source { margin: 0 0 12px; color: var(--text-grey); font-size: var(--text-xs); }
    .pa-chart { position: relative; height: 240px; }
    .pa-wide { grid-column: 1 / -1; }
    .pa-card table { width: 100%; font-size: var(--text-sm); }
    .pa-periods { display: inline-flex; border: 1px solid var(--border-subtle); border-radius: 8px; overflow: hidden; }
    .pa-periods a { padding: 6px 12px; font-size: var(--text-sm); color: var(--text-body); text-decoration: none; background: var(--surface); }
    .pa-periods a + a { border-left: 1px solid var(--border-subtle); }
    .pa-periods a[aria-current="page"] { background: var(--accent-solid); color: var(--text-on-accent); font-weight: 700; }
    .pa-headline { margin-top: 16px; padding: 12px 16px; border-radius: 10px; background: var(--warning-bg); color: var(--warning-fg); border: 1px solid var(--warning-border); font-size: var(--text-sm); }
    .pa-slowest td { background: var(--warning-bg); color: var(--warning-fg); font-weight: 700; }
    .pa-flag { color: var(--danger-fg); font-weight: 700; }
    .pa-legend { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; font-size: var(--text-xs); color: var(--text-grey); padding: 0; list-style: none; }
    .pa-legend li { display: inline-flex; align-items: center; gap: 6px; }
    .pa-swatch { width: 10px; height: 10px; border-radius: 3px; display: inline-block; }
    .pa-swatch.is-average { background: var(--accent-solid); }
    .pa-swatch.is-median { background: var(--info-fg); }
    .pa-swatch.is-slowest { background: var(--warning-fg); }
    .pa-swatch.is-limit { background: var(--danger-fg); }
    .pa-swatch.is-first { background: var(--accent-solid); }
    .pa-swatch.is-later { background: var(--warning-fg); }
</style>

@php
    $scope = $department ? 'candidates and examiners in '.$department : 'the whole university';
    $period = 'the last '.$months.' months ('.$from->format('j M Y').' to '.$to->format('j M Y').')';
@endphp

<div class="card-container-inline">
    <x-core::page-header title="Process Analytics" :back="route('examiner-dashboard')" :back-history="false">
        <x-slot:subtitle>Where the time goes in examiner nomination, the viva and the re-viva: {{ $scope }}, over {{ $period }}. Read-only.</x-slot:subtitle>

        <nav class="pa-periods" aria-label="Period">
            @foreach ($periods as $p)
                <a href="{{ route('process-analytics', ['months' => $p]) }}" @if ($p === $months) aria-current="page" @endif>Last {{ $p }} months</a>
            @endforeach
        </nav>
        <a href="{{ route('process-analytics.export', ['format' => 'xlsx', 'months' => $months]) }}" class="btn-secondary">Download Excel</a>
        <a href="{{ route('process-analytics.export', ['format' => 'csv', 'months' => $months]) }}" class="btn-secondary">Download CSV</a>
    </x-core::page-header>

    {{-- Read by public/js/hani/process-analytics.js. A data block, never executed; it carries the nonce as the CSP test asks. --}}
    <script type="application/json" id="process-analytics-data" @cspNonce>@json($chartData)</script>

    @if ($slowest['overall'])
        <p class="pa-headline" data-slowest>
            Slowest stage: <strong>{{ $slowest['overall']['label'] }}</strong> ({{ $slowest['overall']['module'] }}),
            {{ $slowest['overall']['average'] }} days on average over {{ $slowest['overall']['count'] }} {{ Str::plural('decision', $slowest['overall']['count']) }},
            {{ $slowest['overall']['share'] }}% of that workflow's total time.
        </p>
    @endif

    <div class="pa-grid">
        {{-- Stage times, one card per module --}}
        @foreach ($stages as $key => $module)
            <section class="pa-card" id="stages-{{ $key }}">
                <h3>{{ $module['label'] }}: days per stage</h3>
                <p class="pa-source">Computed from approval_history: the calendar days between one decision and the next (the first stage from submission), for decisions made in {{ $period }}. A list sent back counts each visit. The stage with the largest share of time is highlighted.</p>
                @if (collect($module['stages'])->sum('count') > 0)
                    <div class="pa-chart"><canvas id="pa-stages-{{ $key }}" role="img" aria-label="{{ $module['label'] }} average days per stage: @foreach ($module['stages'] as $s){{ $s['label'] }} {{ $s['average'] ?? 'no data' }}@if (! $loop->last), @endif @endforeach"></canvas></div>
                    <ul class="pa-legend">
                        <li><span class="pa-swatch is-average"></span>Average</li>
                        <li><span class="pa-swatch is-median"></span>Median</li>
                        <li><span class="pa-swatch is-slowest"></span>Slowest stage (average)</li>
                    </ul>
                    <table class="data-table" style="margin-top: 10px;">
                        <thead><tr><th>Stage</th><th>Decisions</th><th>Average</th><th>Median</th><th>Share</th></tr></thead>
                        <tbody>
                            @foreach ($module['stages'] as $s)
                                <tr @class(['pa-slowest' => ($slowest['perModule'][$key] ?? null) === $s['key']])>
                                    <td>{{ $s['label'] }}</td>
                                    <td>{{ $s['count'] }}</td>
                                    <td>{{ $s['average'] !== null ? $s['average'].' d' : '–' }}</td>
                                    <td>{{ $s['median'] !== null ? $s['median'].' d' : '–' }}</td>
                                    <td>{{ $s['share'] }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="empty-state">No decisions in this period.</p>
                @endif
            </section>
        @endforeach

        {{-- Overdue now --}}
        <section class="pa-card pa-wide" id="overdue">
            <h3>Overdue now ({{ count($overdue) }})</h3>
            <p class="pa-source">Computed from each open application's last decision against the per-stage limits in hani.sla (provisional), counted today; the same reading `hani:sla-reminders` uses. Not limited to the period. Stages another reminder already chases (re-viva panel review) are left out.</p>
            @if ($overdue)
                <table class="data-table">
                    <thead><tr><th>Reference</th><th>Workflow</th><th>Student</th><th>Stage</th><th>Since</th><th>Waited</th><th>Limit</th></tr></thead>
                    <tbody>
                        @foreach ($overdue as $row)
                            <tr>
                                <td>{{ $row['reference'] }}</td>
                                <td>{{ $row['module'] }}</td>
                                <td>{{ $row['student'] }}<span style="display: block; color: var(--text-grey); font-size: var(--text-xs);">{{ $row['department'] }}</span></td>
                                <td>{{ $row['stage'] }}</td>
                                <td>{{ $row['since']->format('j M Y') }}</td>
                                <td @class(['pa-flag' => $row['level'] >= 2])>{{ $row['days'] }} {{ $row['unit'] }}</td>
                                <td>{{ $row['limit'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="empty-state">Nothing is past its limit.</p>
            @endif
        </section>

        {{-- Viva pipeline --}}
        <section class="pa-card" id="pipeline">
            <h3>Viva pipeline</h3>
            <p class="pa-source">Computed from the {{ $pipeline['notices'] }} Notices of Thesis Submission filed in {{ $period }}: how many reached each step (softbound filed, every current main examiner's report recorded, viva date passed, result recorded), and the average days from the step before.</p>
            @if ($pipeline['notices'] > 0)
                <div class="pa-chart"><canvas id="pa-pipeline" role="img" aria-label="Viva pipeline: @foreach ($pipeline['steps'] as $s){{ $s['label'] }} {{ $s['count'] }}@if (! $loop->last), @endif @endforeach"></canvas></div>
                <table class="data-table" style="margin-top: 10px;">
                    <thead><tr><th>Step</th><th>Reached</th><th>Avg days from previous</th></tr></thead>
                    <tbody>
                        @foreach ($pipeline['steps'] as $s)
                            <tr><td>{{ $s['label'] }}</td><td>{{ $s['count'] }}</td><td>{{ $s['days'] ?? '–' }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="empty-state">No notices filed in this period.</p>
            @endif
        </section>

        {{-- Examiner report turnaround --}}
        <section class="pa-card" id="turnaround">
            <h3>Examiner report turnaround</h3>
            <p class="pa-source">Computed from viva examiner progress: calendar days from the examiner receiving the thesis to the report being recorded, for reports recorded in {{ $period }}, against the report limit in hani.examiner_progress.report_weeks.</p>
            @if (collect($turnaround)->sum('count') > 0)
                <div class="pa-chart"><canvas id="pa-turnaround" role="img" aria-label="Report turnaround: @foreach ($turnaround as $t){{ $t['label'] }} average {{ $t['average'] ?? 'no data' }} days, limit {{ $t['limit'] }}@if (! $loop->last); @endif @endforeach"></canvas></div>
                <ul class="pa-legend">
                    <li><span class="pa-swatch is-average"></span>Average</li>
                    <li><span class="pa-swatch is-median"></span>Median</li>
                    <li><span class="pa-swatch is-limit"></span>Limit</li>
                </ul>
                <table class="data-table" style="margin-top: 10px;">
                    <thead><tr><th>Programme</th><th>Reports</th><th>Average</th><th>Median</th><th>Limit</th><th>Late</th></tr></thead>
                    <tbody>
                        @foreach ($turnaround as $t)
                            <tr>
                                <td>{{ $t['label'] }}</td><td>{{ $t['count'] }}</td>
                                <td>{{ $t['average'] !== null ? $t['average'].' d' : '–' }}</td>
                                <td>{{ $t['median'] !== null ? $t['median'].' d' : '–' }}</td>
                                <td>{{ $t['limit'] }} d</td>
                                <td @class(['pa-flag' => $t['late'] > 0])>{{ $t['late'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="empty-state">No examiner reports recorded in this period.</p>
            @endif
        </section>

        {{-- Re-viva --}}
        <section class="pa-card" id="reviva">
            <h3>Re-viva rounds</h3>
            <p class="pa-source">Computed from re-viva rounds: the {{ $reviva['open'] }} open now, by current stage (today, not limited to the period), and the {{ $reviva['decided'] }} outcomes recorded in {{ $period }}, round 1 against later rounds.</p>
            <h4>Open rounds by stage</h4>
            <div class="pa-chart" style="height: 180px;"><canvas id="pa-reviva-stages" role="img" aria-label="Open re-viva rounds by stage: @foreach ($reviva['byStage'] as $s){{ $s['label'] }} {{ $s['count'] }}@if (! $loop->last), @endif @endforeach"></canvas></div>
            <h4>Outcomes recorded</h4>
            @if ($reviva['decided'] > 0)
                <div class="pa-chart" style="height: 200px;"><canvas id="pa-reviva-outcomes" role="img" aria-label="Re-viva outcomes: @foreach ($reviva['byOutcome'] as $level => $o)Level {{ $level }} {{ $o['first'] + $o['later'] }}@if (! $loop->last), @endif @endforeach"></canvas></div>
                <ul class="pa-legend">
                    <li><span class="pa-swatch is-first"></span>Round 1</li>
                    <li><span class="pa-swatch is-later"></span>Round 2 or later</li>
                </ul>
                <table class="data-table" style="margin-top: 10px;">
                    <thead><tr><th>Outcome</th><th>Round 1</th><th>Later</th></tr></thead>
                    <tbody>
                        @foreach ($reviva['byOutcome'] as $o)
                            <tr><td>{{ $o['label'] }}</td><td>{{ $o['first'] }}</td><td>{{ $o['later'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="empty-state">No outcomes recorded in this period.</p>
            @endif
        </section>

        {{-- Pool utilisation --}}
        <section class="pa-card" id="utilisation">
            <h3>Examiner pool utilisation</h3>
            <p class="pa-source">Computed from examiner seat history: active examiners{{ $department ? ' in '.$department : '' }} holding a main seat on a released panel at any point in each month (from release or promotion until the evaluation is complete, they are freed or they decline), as a share of the active pool that month, over {{ $period }}.</p>
            <div class="pa-chart"><canvas id="pa-utilisation" role="img" aria-label="Pool utilisation by month: @foreach ($utilisation as $m){{ $m['label'] }} {{ $m['percent'] }}%@if (! $loop->last), @endif @endforeach"></canvas></div>
            <table class="data-table" style="margin-top: 10px;">
                <thead><tr><th>Month</th><th>Holding a seat</th><th>Pool</th><th>Utilisation</th></tr></thead>
                <tbody>
                    @foreach ($utilisation as $m)
                        <tr><td>{{ $m['label'] }}</td><td>{{ $m['busy'] }}</td><td>{{ $m['pool'] }}</td><td>{{ $m['percent'] }}%</td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    </div>
</div>

<script src="{{ asset('js/hani/process-analytics.js') }}?v={{ @filemtime(public_path('js/hani/process-analytics.js')) ?: 1 }}" defer></script>
@endsection
