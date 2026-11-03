@extends('core::layouts.app')

@section('title', 'Examiner Dashboard')

@section('content')
@include('core::dashboard.partials.chartjs')

{{-- Page-scoped, tokens only, so light and dark both work. --}}
<style>
    .exd-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 16px; margin-top: 16px; }
    .exd-card { background: var(--surface); border: 1px solid var(--border-subtle); border-radius: 12px; padding: 16px 18px; min-width: 0; }
    .exd-card h3 { margin: 0 0 4px; color: var(--navy); font-size: var(--text-md); }
    .exd-card .exd-note { margin: 0 0 12px; color: var(--text-grey); font-size: var(--text-xs); }
    .exd-chart { position: relative; height: 240px; }
    .exd-legend { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; font-size: var(--text-xs); color: var(--text-grey); padding: 0; list-style: none; }
    .exd-legend li { display: inline-flex; align-items: center; gap: 6px; }
    .exd-swatch { width: 10px; height: 10px; border-radius: 3px; display: inline-block; }
    .exd-swatch.is-available { background: var(--success-fg); }
    .exd-swatch.is-on_gap { background: var(--warning-fg); }
    .exd-swatch.is-assigned { background: var(--info-fg); }
    .exd-swatch.is-unavailable { background: var(--danger-fg); }
    .exd-swatch.is-theses { background: var(--accent-solid); }
    .exd-swatch.is-examiners { background: var(--success-fg); }
    .exd-wide { grid-column: 1 / -1; }
    .exd-gap { color: var(--danger-fg); font-weight: 700; }
    .exd-card table { width: 100%; font-size: var(--text-sm); }
</style>

<div class="card-container-inline">
    <x-core::page-header
        title="Examiner Dashboard"
        subtitle="{{ $department ? 'Examiners in '.$department.' and its nominations.' : 'The whole examiner pool and every department\'s nominations.' }} {{ $total }} {{ Str::plural('examiner', $total) }} on record." />

    {{-- Read by public/js/hani/examiner-dashboard.js. A data block, never executed. --}}
    <script type="application/json" id="examiner-dashboard-data">@json($chartData)</script>

    <div class="exd-grid">
        <section class="exd-card">
            <h3>Pool by state</h3>
            <p class="exd-note">State is derived from the stored dates, so it is always current.</p>
            <div class="exd-chart"><canvas id="exd-states" role="img" aria-label="Examiners by state: @foreach ($states as $k => $v){{ \App\Modules\Hani\Support\ExaminerDashboard::STATES[$k] }} {{ $v }}@if (! $loop->last), @endif @endforeach"></canvas></div>
            <ul class="exd-legend">
                @foreach ($states as $key => $count)
                    <li><span class="exd-swatch is-{{ $key }}"></span>{{ \App\Modules\Hani\Support\ExaminerDashboard::STATES[$key] }}: {{ $count }}</li>
                @endforeach
            </ul>
        </section>

        <section class="exd-card">
            <h3>Nominations by stage</h3>
            <p class="exd-note">Pending at each stage of the chain, and lists released in the last 12 months.</p>
            <div class="exd-chart"><canvas id="exd-stages" role="img" aria-label="Nominations by stage: @foreach ($stages as $row){{ $row['label'] }} {{ $row['count'] }}@if (! $loop->last), @endif @endforeach"></canvas></div>
        </section>

        <section class="exd-card exd-wide">
            <h3>Pool by department</h3>
            <p class="exd-note">The {{ count($byDepartment) }} largest departments, by state. External examiners count under the UTP department they relate to.</p>
            <div class="exd-chart" style="height: 280px;"><canvas id="exd-departments" role="img" aria-label="Examiners by department and state"></canvas></div>
            <ul class="exd-legend">
                @foreach (\App\Modules\Hani\Support\ExaminerDashboard::STATES as $key => $label)
                    <li><span class="exd-swatch is-{{ $key }}"></span>{{ $label }}</li>
                @endforeach
            </ul>
        </section>

        <section class="exd-card">
            <h3>Returning to Available in the next {{ config('hani.dashboard.returning_days') }} days</h3>
            <p class="exd-note">Their cooling-off period ends, or their tie-up runs out.</p>
            @if ($returning)
                <table class="data-table">
                    <thead><tr><th>Examiner</th><th>Type</th><th>Now</th><th>Available from</th></tr></thead>
                    <tbody>
                        @foreach ($returning as $row)
                            <tr>
                                <td>{{ $row['examiner']->name }}<span style="display: block; color: var(--text-grey); font-size: var(--text-xs);">{{ $row['examiner']->department }}</span></td>
                                <td>{{ $row['examiner']->typeLabel() }}</td>
                                <td>{{ $row['examiner']->stateLabel() }}</td>
                                <td>{{ $row['on']->format('j M Y') }} <span style="color: var(--text-grey);">({{ $row['days'] }} {{ Str::plural('day', $row['days']) }})</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="empty-state">Nobody returns within the window.</p>
            @endif
        </section>

        <section class="exd-card">
            <h3>Top expertise in the pool</h3>
            <p class="exd-note">Keywords held by the most active examiners.</p>
            <div class="exd-chart"><canvas id="exd-keywords" role="img" aria-label="Top expertise keywords: @foreach ($poolKeywords as $row){{ $row['keyword'] }} {{ $row['count'] }}@if (! $loop->last), @endif @endforeach"></canvas></div>
        </section>

        <section class="exd-card exd-wide">
            <h3>Expertise gaps: recent theses against the pool</h3>
            <p class="exd-note">
                Topics from thesis titles in the last 12 months, and how many active examiners' expertise covers each (using the same terms as the examiner suggestions).
                Fewer than {{ config('hani.dashboard.gap_threshold') }} is flagged as a gap: a reason to recruit or import examiners in that area.
            </p>
            @if ($gaps)
                <div class="exd-chart" style="height: 260px;"><canvas id="exd-gaps" role="img" aria-label="Thesis topics against examiners covering them"></canvas></div>
                <ul class="exd-legend">
                    <li><span class="exd-swatch is-theses"></span>Theses</li>
                    <li><span class="exd-swatch is-examiners"></span>Examiners covering it</li>
                </ul>
                <table class="data-table" style="margin-top: 12px;">
                    <thead><tr><th>Topic</th><th>Theses</th><th>Examiners covering it</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($gaps as $row)
                            <tr>
                                <td>{{ $row['topic'] }}</td>
                                <td>{{ $row['theses'] }}</td>
                                <td>{{ $row['examiners'] }}</td>
                                <td>@if ($row['gap'])<span class="exd-gap">Gap</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="empty-state">No theses in the last 12 months to compare yet.</p>
            @endif
        </section>
    </div>
</div>

<script src="{{ asset('js/hani/examiner-dashboard.js') }}?v={{ @filemtime(public_path('js/hani/examiner-dashboard.js')) ?: 1 }}" defer></script>
@endsection
