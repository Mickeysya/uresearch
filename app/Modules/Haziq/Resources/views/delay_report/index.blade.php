@extends('core::layouts.app')

@section('title', 'Delay by Actor')

@push('head')
    @include('haziq::partials.styles')
@endpush

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Delay by Actor"
        subtitle="How long an actor takes once an application actually reaches them, read off the real approval history. Not a workflow, just a read — pick one scope below, GRA/GA Application or GRA/GA Extension never average together." />

    @if ($bottleneck)
        <div class="card card-wide" style="border-left: 4px solid var(--warning-fg);">
            <h3 style="margin-top: 0;">Predicted bottleneck ({{ \App\Modules\Haziq\Support\DelayByActorReport::SCOPES[$module]['label'] }})</h3>
            {{-- Confirmed explicitly 2026-11-10: "boleh je predict even
                 baru ada satu application approved or rejected... jangan
                 tunggu banyak application baru predict, nak cegah awal
                 kan" -- one completed application is a real, clean signal
                 now that rows() only ever counts a finished journey
                 (approved or rejected), never a still-pending one. The
                 earlier "Admin predicted to cause delay" case that looked
                 wrong wasn't really a sample-size problem -- it was a
                 still-pending application's partial history being
                 counted at all, which rows() no longer does. The decision
                 count is still shown plainly, so "based on 1 decision" is
                 visible, not hidden -- just not suppressed outright. --}}
            <p class="queue-meta" style="margin-bottom: 0;">
                <b>{{ \App\Modules\Core\Support\Role::label($bottleneck['role']) }}</b> is the role predicted to
                cause the most delay for an application right now, averaging
                {{ $bottleneck['share_percent'] ?? '?' }}% of an application's total wait
                ({{ $bottleneck['avg_days'] }} {{ Str::plural('day', $bottleneck['avg_days']) }} on average).
                @unless ($bottleneck['decisions'] >= \App\Modules\Haziq\Support\DelayByActorReport::RELIABLE_SAMPLE)
                    Based on only {{ $bottleneck['decisions'] }} {{ Str::plural('decision', $bottleneck['decisions']) }}
                    so far, so treat this as an early signal, not a guarantee.
                @endunless
            </p>
        </div>
    @endif

    <div class="card card-wide">
        <form method="GET" action="{{ route('delay-report.index') }}" class="haziq-filter-bar">
            <select name="module" data-autosubmit>
                @foreach (\App\Modules\Haziq\Support\DelayByActorReport::SCOPES as $key => $scope)
                    <option value="{{ $key }}" @selected($module === $key)>{{ $scope['label'] }}</option>
                @endforeach
            </select>

            <select name="role" data-autosubmit>
                <option value="">Any role (general)</option>
                @foreach ($roles as $r)
                    <option value="{{ $r }}" @selected($role === $r)>{{ \App\Modules\Core\Support\Role::label($r) }}</option>
                @endforeach
            </select>

            @if ($role)
                <select name="actor" data-autosubmit>
                    <option value="">Every {{ \App\Modules\Core\Support\Role::label($role) }} (role average)</option>
                    @foreach ($actors as $a)
                        <option value="{{ $a->id }}" @selected($actorId === $a->id)>{{ $a->name }}</option>
                    @endforeach
                </select>
            @endif
        </form>
        @include('haziq::partials.filter_autosubmit_scripts')

        <p class="queue-meta" style="margin-top: 14px;">
            @if ($actorId)
                Showing that one individual only.
            @elseif ($role)
                Every individual who has held {{ \App\Modules\Core\Support\Role::label($role) }}, since one
                role-level number hides how spread out its own people are.
            @else
                Every role, each already averaged across everyone who has held it, for comparison.
            @endif
            Each bar is a prediction, not just a history: the average delay to expect
            from a new application at that scope, in days. A bar marked
            "limited history" has fewer than {{ \App\Modules\Haziq\Support\DelayByActorReport::RELIABLE_SAMPLE }}
            decisions behind it, so treat the number as a rough guide, not a guarantee.
        </p>

        @if ($chartBars->isEmpty())
            <div class="empty-state">Nothing decided yet for this filter.</div>
        @else
            <div style="height: {{ max(220, 60 + $chartBars->count() * 36) }}px;">
                <canvas id="delay-prediction" role="img"
                        aria-label="Predicted delay in days: {{ $chartBars->map(fn ($s) => $s['label'].' '.$s['avg_days'].' days, '.($s['share_percent'] ?? '0').'% of total wait')->implode(', ') }}"></canvas>
            </div>

            @include('core::dashboard.partials.chartjs')
            @push('scripts')
                <script @cspNonce>
                    (function () {
                        var el = document.getElementById('delay-prediction');
                        if (! el || typeof Chart === 'undefined') return;

                        var points = @json($chartBars);
                        if (! points.length) return;

                        new Chart(el, {
                            type: 'bar',
                            data: {
                                labels: points.map(function (p) { return p.label; }),
                                datasets: [{
                                    label: 'Predicted days to decide',
                                    data: points.map(function (p) { return p.avg_days; }),
                                    backgroundColor: points.map(function (p) {
                                        return p.reliable
                                            ? Chart.uresearchToken('--navy', '#284B80')
                                            : Chart.uresearchToken('--warning-fg', '#C28E40');
                                    }),
                                    borderRadius: 5,
                                    borderSkipped: false,
                                    maxBarThickness: 36,
                                }],
                            },
                            options: {
                                indexAxis: 'y',
                                animation: { duration: 650, easing: 'easeOutQuart' },
                                plugins: {
                                    legend: { display: false },
                                    datalabels: {
                                        display: true, anchor: 'end', align: 'right', offset: 4,
                                        color: function () { return Chart.uresearchToken('--text-body', '#23283A'); }, font: { size: 11, weight: '700' },
                                        formatter: function (value, ctx) {
                                            var p = points[ctx.dataIndex];
                                            var label = value + 'd';
                                            if (p.share_percent !== null) label += ' (' + p.share_percent + '% of wait)';
                                            if (! p.reliable) label += ' — limited history';
                                            return label;
                                        },
                                    },
                                },
                                scales: {
                                    x: { beginAtZero: true, grid: { color: function () { return Chart.uresearchToken('--border-grey', '#F1F3F7'); } }, border: { display: false } },
                                    y: { grid: { display: false }, border: { display: false } },
                                },
                            },
                        });
                    })();
                </script>
            @endpush
        @endif
    </div>

    <div class="card card-wide">
        <h3 style="margin-top: 0;">By role</h3>
        <p class="queue-meta" style="margin-bottom: 14px;">
            Every role reached by the applications matching the module filter above,
            averaged across everyone who has ever held that role.
        </p>

        @if ($byRole->isEmpty())
            <div class="empty-state">Nothing decided yet for this filter.</div>
        @else
            <table class="haziq-table">
                <thead>
                    <tr>
                        <th>Role</th>
                        <th>Actors</th>
                        <th>Decisions made</th>
                        <th>Average time held</th>
                        <th>Share of total wait</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($byRole as $r)
                        <tr>
                            <td>{{ \App\Modules\Core\Support\Role::label($r['role']) }}</td>
                            <td>{{ $r['actors'] }}</td>
                            <td>{{ $r['decisions'] }}</td>
                            <td>{{ $r['avg_days'] }} {{ Str::plural('day', $r['avg_days']) }} ({{ $r['avg_hours'] }} hrs)</td>
                            <td>{{ $r['share_percent'] !== null ? $r['share_percent'].'%' : 'n/a' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card card-wide">
        <h3 style="margin-top: 0;">By individual actor</h3>
        <p class="queue-meta" style="margin-bottom: 14px;">
            Supervisor and Research Centre only: each student has one specific
            person in these roles, so a person-level number here actually predicts
            something about that student's next application. Admin, CGS, and
            Senior Director are general: any member can pick up any application,
            so there is nobody specific to predict for; the table above already
            covers those at the role level.
            "vs role average" is how much slower (positive) or faster (negative) this
            person runs compared to everyone else who has ever held the same role.
        </p>

        @if ($byActor->isEmpty())
            <div class="empty-state">Nobody has decided a stage yet for this filter.</div>
        @else
            <table class="haziq-table">
                <thead>
                    <tr>
                        <th>Actor</th>
                        <th>Role</th>
                        <th>Decisions made</th>
                        <th>Average time held</th>
                        <th>vs role average</th>
                        <th>Share of total wait</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($byActor as $a)
                        <tr>
                            <td>{{ $a['approver_name'] }}</td>
                            <td>{{ \App\Modules\Core\Support\Role::label($a['role']) }}</td>
                            <td>{{ $a['decisions'] }}</td>
                            <td>{{ $a['avg_days'] }} {{ Str::plural('day', $a['avg_days']) }} ({{ $a['avg_hours'] }} hrs)</td>
                            <td>
                                @if ($a['vs_role_percent'] > 0)
                                    <span style="color: var(--danger-fg); font-weight: 600;">+{{ $a['vs_role_percent'] }}% slower</span>
                                @elseif ($a['vs_role_percent'] < 0)
                                    <span style="color: var(--success-fg); font-weight: 600;">{{ $a['vs_role_percent'] }}% faster</span>
                                @else
                                    <span class="queue-meta">about the same</span>
                                @endif
                            </td>
                            <td>{{ $a['share_percent'] !== null ? $a['share_percent'].'%' : 'n/a' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card card-wide">
        <h3 style="margin-top: 0;">Every decision, every application</h3>
        <p class="queue-meta" style="margin-bottom: 14px;">
            When each actor received an application matching the filter above and how
            long they held it before deciding. "Received" is the moment the previous
            stage was decided, or submission for the first stage.
        </p>

        @if ($rows->isEmpty())
            <div class="empty-state">Nothing decided yet for this filter.</div>
        @else
            <table class="haziq-table">
                <thead>
                    <tr>
                        <th>Application</th>
                        <th>Stage</th>
                        <th>Actor</th>
                        <th>Received</th>
                        <th>Decided</th>
                        <th>Held for</th>
                        <th>Decision</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ $row['application_ref'] }}</td>
                            <td>{{ $row['stage_label'] }}</td>
                            <td>{{ $row['approver_name'] }}</td>
                            <td>{{ $row['received_at']->format('j M Y, g:ia') }}</td>
                            <td>{{ $row['decided_at']->format('j M Y, g:ia') }}</td>
                            <td>{{ round($row['delay_hours'] / 24, 1) }} days</td>
                            <td><x-core::status-badge :status="$row['decision']" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection
