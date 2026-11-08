@extends('core::layouts.app')

@section('title', 'Stage Gate Monitoring')

@push('head')
    @include('haziq::partials.styles')
@endpush

@section('content')
<div class="card-container-inline">
    @unless ($isStudent ?? false)
    <div class="card card-wide">
        <h2>Stage Gate Bottleneck</h2>
        <div class="card-divider"></div>
        <p class="queue-meta">Which stage has the most students sitting past their deadline right now.</p>

        @include('core::dashboard.partials.chartjs')
        <div style="height: 220px;">
            <canvas id="stage-gate-bottleneck" role="img"
                    aria-label="Students past deadline by stage: {{ $bottleneckChart->map(fn ($b) => $b['label'].' '.$b['count'])->implode(', ') }}"></canvas>
        </div>

        @push('scripts')
            <script @cspNonce>
                (function () {
                    var el = document.getElementById('stage-gate-bottleneck');
                    if (! el || typeof Chart === 'undefined') return;

                    new Chart(el, {
                        type: 'bar',
                        data: {
                            labels: @json($bottleneckChart->pluck('label')),
                            datasets: [{
                                label: 'Past deadline',
                                data: @json($bottleneckChart->pluck('count')),
                                backgroundColor: '#C28E40',
                                borderRadius: 5,
                                borderSkipped: false,
                                maxBarThickness: 64,
                            }],
                        },
                        options: {
                            layout: { padding: { top: 18 } },
                            animation: { duration: 650, easing: 'easeOutQuart' },
                            scales: {
                                x: { grid: { display: false }, border: { display: false } },
                                y: {
                                    beginAtZero: true,
                                    border: { display: false },
                                    grid: { color: '#F1F3F7' },
                                    ticks: { precision: 0 },
                                },
                            },
                            plugins: {
                                datalabels: {
                                    display: true,
                                    anchor: 'end',
                                    align: 'top',
                                    offset: 2,
                                    color: '#23283A',
                                    font: { size: 11, weight: '700' },
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function (ctx) {
                                            return ' ' + ctx.parsed.y + (ctx.parsed.y === 1 ? ' student' : ' students') + ' past deadline';
                                        },
                                    },
                                },
                            },
                        },
                    });
                })();
            </script>
        @endpush

        <div class="haziq-filter-bar" style="margin-top: 18px;">
            <div class="haziq-repeatable-row" style="flex: 1; min-width: 160px;">
                <p style="grid-column: 1 / -1; margin: 0;"><b>Active GRA</b><br>
                    <span style="font-size: 22px;">{{ $activeCount }}</span></p>
            </div>
            <div class="haziq-repeatable-row" style="flex: 1; min-width: 160px; border-color: #D0342C;">
                <p style="grid-column: 1 / -1; margin: 0;"><b>Terminated</b><br>
                    <span style="font-size: 22px; color: #D0342C;">{{ $terminatedCount }}</span></p>
            </div>
            <div class="haziq-repeatable-row" style="flex: 1; min-width: 160px; border-color: #C28E40;">
                <p style="grid-column: 1 / -1; margin: 0;"><b>Allowance Suspended</b><br>
                    <span style="font-size: 22px; color: #8A5F14;">{{ $suspended->count() }}</span></p>
            </div>
        </div>

        @if ($suspended->isNotEmpty())
            <h3 style="margin-top: 20px;">Students With Allowance Suspended</h3>
            <div style="overflow-x: auto;">
                <table class="haziq-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Stage</th>
                            <th>Grace Ended</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($suspended as $gate)
                            <tr>
                                <td>{{ $gate->application->student->name }}</td>
                                <td>Stage {{ $gate->stage_order }}<br><span style="color: var(--text-grey); font-size: 12px;">{{ $gate->stage_name }}</span></td>
                                <td>{{ $gate->grace_end_date->format('j M Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
    @endunless

    <div class="card card-wide">
        @if ($isStudent ?? false)
            <h2>My Stage Gate Progress</h2>
            <div class="card-divider"></div>
            <p class="queue-meta">
                Deadlines and grace periods are fixed from your admission date and never move —
                this always reflects what the daily evaluation last computed.
            </p>

            @php($openGates = $gates->filter(fn ($g) => $g->completion_status !== 'completed'))
            @if ($openGates->isNotEmpty())
                @include('core::dashboard.partials.chartjs')
                <p class="queue-meta" style="margin-top: 14px;">How close each open stage is to its grace period running out.</p>
                <div style="height: {{ 50 + $openGates->count() * 36 }}px;">
                    <canvas id="my-stage-gate-risk" role="img"
                            aria-label="Risk per stage: {{ $openGates->map(fn ($g) => 'Stage '.$g->stage_order.' '.($riskByGate[$g->id] ?? 0).'%')->implode(', ') }}"></canvas>
                </div>

                @push('scripts')
                    <script @cspNonce>
                        (function () {
                            var el = document.getElementById('my-stage-gate-risk');
                            if (! el || typeof Chart === 'undefined') return;

                            var risk = @json($openGates->map(fn ($g) => $riskByGate[$g->id] ?? 0)->values());
                            var colors = risk.map(function (r) {
                                return r >= 100 ? '#D0342C' : (r >= 60 ? '#C28E40' : '#07794A');
                            });

                            new Chart(el, {
                                type: 'bar',
                                data: {
                                    labels: @json($openGates->map(fn ($g) => 'Stage '.$g->stage_order.' — '.$g->stage_name)->values()),
                                    datasets: [{
                                        label: 'Risk of missing grace period',
                                        data: risk,
                                        backgroundColor: colors,
                                        borderRadius: 5,
                                        borderSkipped: false,
                                    }],
                                },
                                options: {
                                    indexAxis: 'y',
                                    animation: { duration: 650, easing: 'easeOutQuart' },
                                    scales: {
                                        x: { min: 0, max: 100, grid: { color: '#F1F3F7' }, border: { display: false }, ticks: { callback: function (v) { return v + '%'; } } },
                                        y: { grid: { display: false }, border: { display: false } },
                                    },
                                    plugins: {
                                        legend: { display: false },
                                        datalabels: {
                                            display: true,
                                            anchor: 'end',
                                            align: 'end',
                                            color: '#23283A',
                                            font: { size: 11, weight: '700' },
                                            formatter: function (v) { return v + '%'; },
                                        },
                                        tooltip: {
                                            callbacks: {
                                                label: function (ctx) {
                                                    return ' ' + ctx.parsed.x + '% of the way through the grace period';
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

            @if ($isTerminated ?? false)
                @foreach (($candidatureStatus ?? []) as $appTerminatedId => $status)
                    @continue($status !== 'terminated')
                    @php($appeal = ($latestAppealByApplication ?? collect())->get($appTerminatedId))
                    <div class="alert-banner alert-banner-danger" role="alert">
                        <strong>Your candidature on application #{{ $appTerminatedId }} has been terminated.</strong>
                        A Stage Gate requirement was not completed within its grace period.
                        @if ($appeal && $appeal->isPending())
                            <br>Your appeal is pending a Senior Director's decision.
                        @else
                            {{-- A decided appeal (approved OR rejected) only ever
                                 speaks to the termination it was decided against.
                                 Reaching this banner at all means candidature_status
                                 is terminated right now — recomputeApplication()
                                 doesn't carry an old decision forward across a new
                                 service_start_date correction's re-termination, so
                                 neither an approved nor a rejected decision blocks a
                                 fresh appeal here. Confirmed explicitly 2026-10-22:
                                 "tak kisah sebelum ni dah pernah appeal ke tak...
                                 appeal data before this patutnya tak visible dah." --}}
                            @if ($appeal && $appeal->status === 'rejected')
                                <br>Your last appeal was rejected
                                @if ($appeal->decision_notes)
                                    ({{ $appeal->decision_notes }})
                                @endif
                            @endif
                            <br><a href="{{ route('candidature-appeal.create', $appTerminatedId) }}">Appeal this termination</a>
                        @endif
                    </div>
                @endforeach
            @endif
        @else
            <h2>Stage Gate Monitoring</h2>
            <div class="card-divider"></div>
            <p class="queue-meta">
                Deadlines and grace periods are fixed from each student's admission date and never
                move — this list only ever reflects what the daily evaluation already computed.
            </p>

            <form method="GET" action="{{ route('stage-gate.index') }}" class="haziq-filter-bar">
                <select name="stage" data-autosubmit>
                    <option value="">All Stages</option>
                    @foreach ([1, 2, 3, 4] as $order)
                        <option value="{{ $order }}" @selected(request('stage') == $order)>Stage {{ $order }}</option>
                    @endforeach
                </select>

                <select name="timeline_status" data-autosubmit>
                    <option value="">Any Timeline</option>
                    @foreach (['upcoming' => 'Upcoming', 'due_soon' => 'Due Soon', 'grace_period' => 'Grace Period', 'grace_expired' => 'Grace Expired'] as $value => $label)
                        <option value="{{ $value }}" @selected(request('timeline_status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="completion_status" data-autosubmit>
                    <option value="">Any Completion</option>
                    @foreach (['not_recorded' => 'Not Recorded', 'completed' => 'Completed', 'not_completed' => 'Not Completed'] as $value => $label)
                        <option value="{{ $value }}" @selected(request('completion_status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="allowance_status" data-autosubmit>
                    <option value="">Any Allowance</option>
                    @foreach (['eligible' => 'Eligible', 'withheld' => 'Withheld', 'backdated' => 'Backdated', 'forfeited' => 'Forfeited'] as $value => $label)
                        <option value="{{ $value }}" @selected(request('allowance_status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
            @include('haziq::partials.filter_autosubmit_scripts')
        @endif

        <div style="overflow-x: auto;">
            <table class="haziq-table">
                <thead>
                    <tr>
                        @unless ($isStudent ?? false)
                            <th>Student</th>
                        @endunless
                        <th>Status</th>
                        <th>Stage</th>
                        <th>Deadline</th>
                        <th>Grace Ends</th>
                        <th>Completion</th>
                        <th>Timeline</th>
                        @unless ($isStudent ?? false)<th>Risk</th>@endunless
                        <th>Allowance</th>
                        @if ($canAct)<th>Action</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($gates as $gate)
                        <tr>
                            @unless ($isStudent ?? false)
                                <td>{{ $gate->application->student->name }}@if ($gate->application->student->matric_no) <br><span style="color: var(--text-grey); font-size: 12px;">{{ $gate->application->student->matric_no }}</span>@endif</td>
                            @endunless
                            <td><x-core::status-badge :status="($candidatureStatus ?? [])[$gate->application_id] ?? 'active'" /></td>
                            <td>Stage {{ $gate->stage_order }}<br><span style="color: var(--text-grey); font-size: 12px;">{{ $gate->stage_name }}</span></td>
                            <td>{{ $gate->deadline_date->format('j M Y') }}</td>
                            <td>{{ $gate->grace_end_date->format('j M Y') }}</td>
                            <td><x-core::status-badge :status="$gate->completion_status" /></td>
                            <td>
                                @unless ($gate->completion_status === 'completed')
                                    <x-core::status-badge :status="$gate->timeline_status" />
                                @endunless
                            </td>
                            @unless ($isStudent ?? false)
                                <td>
                                    @php($risk = ($riskByGate ?? [])[$gate->id] ?? null)
                                    @if ($risk !== null)
                                        <span style="font-weight: 600; color: {{ $risk >= 100 ? '#D0342C' : ($risk >= 60 ? '#8A5F14' : '#07794A') }};">{{ $risk }}%</span>
                                    @else
                                        <span style="color: var(--text-grey);">—</span>
                                    @endif
                                </td>
                            @endunless
                            <td>
                                <x-core::status-badge :status="($governingAllowance ?? [])[$gate->application_id] ?? $gate->allowance_status" />
                                @if ((($governingAllowance ?? [])[$gate->application_id] ?? $gate->allowance_status) !== $gate->allowance_status)
                                    <br><span style="color: var(--text-grey); font-size: 11px;">this stage: {{ $gate->allowance_status }}</span>
                                @endif
                            </td>
                            @if ($canAct)
                                <td>
                                    @if ($gate->completion_status === 'completed')
                                        <span style="color: var(--text-grey); font-size: 12.5px;">Completed — final, cannot be changed.</span>
                                    @else
                                        <div class="haziq-actions">
                                            <form method="POST" action="{{ route('stage-gate.complete', $gate) }}">
                                                @csrf
                                                <button type="submit" data-confirm="Mark Stage {{ $gate->stage_order }} ({{ $gate->stage_name }}) complete? This is final — it cannot be changed back afterward.">Mark Complete</button>
                                            </form>
                                            <form method="POST" action="{{ route('stage-gate.not-complete', $gate) }}">
                                                @csrf
                                                <button type="submit" class="btn-secondary" data-confirm="Mark Stage {{ $gate->stage_order }} ({{ $gate->stage_name }}) not complete?">Not Complete</button>
                                            </form>
                                        </div>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canAct ? 10 : (($isStudent ?? false) ? 7 : 9) }}"><div class="empty-state">No Stage Gates {{ ($isStudent ?? false) ? 'yet.' : 'match these filters.' }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top: 16px;">{{ $gates->links() }}</div>
    </div>
</div>

@if ($canAct)
    @include('haziq::partials.confirm_scripts')
@endif
@include('haziq::partials.assistant_widget')
@endsection
