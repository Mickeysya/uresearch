@extends('core::layouts.app')

@section('title', 'GRA Applicants')

@push('head')
    @include('haziq::partials.styles')
@endpush

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Pending by Stage</h2>
        <div class="card-divider"></div>
        <p class="queue-meta">Where applications are piling up right now. Click a bar to see who's there.</p>

        @include('core::dashboard.partials.chartjs')
        <div style="height: 240px; cursor: pointer;">
            <canvas id="gra-bottleneck" role="img"
                    aria-label="Pending GRA applications by stage: {{ $bottleneck->map(fn ($b) => $b['label'].' '.$b['count'])->implode(', ') }}"></canvas>
        </div>

        @push('scripts')
            <script @cspNonce>
                (function () {
                    var el = document.getElementById('gra-bottleneck');
                    if (! el || typeof Chart === 'undefined') return;

                    var stageKeys = @json($bottleneck->pluck('key'));
                    var selectedStage = @json($selectedStage);
                    var baseUrl = @json(route('gra-application.index'));

                    new Chart(el, {
                        type: 'bar',
                        data: {
                            labels: @json($bottleneck->pluck('label')),
                            datasets: [{
                                label: 'Pending',
                                data: @json($bottleneck->pluck('count')),
                                backgroundColor: stageKeys.map(function (k) {
                                    return k === selectedStage
                                        ? Chart.uresearchToken('--navy', '#284B80')
                                        : Chart.uresearchToken('--gold', '#C28E40');
                                }),
                                borderRadius: 5,
                                borderSkipped: false,
                                maxBarThickness: 64,
                            }],
                        },
                        options: {
                            layout: { padding: { top: 18 } },
                            animation: { duration: 650, easing: 'easeOutQuart' },
                            onClick: function (evt, elements) {
                                if (! elements.length) return;
                                var key = stageKeys[elements[0].index];
                                window.location.href = baseUrl + '?stage=' + encodeURIComponent(key);
                            },
                            onHover: function (evt, elements) {
                                evt.native.target.style.cursor = elements.length ? 'pointer' : 'default';
                            },
                            scales: {
                                x: { grid: { display: false }, border: { display: false } },
                                y: {
                                    beginAtZero: true,
                                    border: { display: false },
                                    grid: { color: function () { return Chart.uresearchToken('--border-grey', '#F1F3F7'); } },
                                    ticks: { precision: 0 },
                                },
                            },
                            plugins: {
                                datalabels: {
                                    display: true,
                                    anchor: 'end',
                                    align: 'top',
                                    offset: 2,
                                    color: function () { return Chart.uresearchToken('--text-body', '#23283A'); },
                                    font: { size: 11, weight: '700' },
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function (ctx) {
                                            return ' ' + ctx.parsed.y + (ctx.parsed.y === 1 ? ' application' : ' applications') + ' — click to view';
                                        },
                                    },
                                },
                            },
                        },
                    });
                })();
            </script>
        @endpush
    </div>

    <div class="card card-wide">
        @if ($selectedStage)
            <h2>{{ $selectedStageLabel }} — {{ $applications->total() }} {{ Str::plural('student', $applications->total()) }}</h2>
            <div class="card-divider"></div>
            <p class="queue-meta">
                Only students currently at this stage.
                <a href="{{ route('gra-application.index') }}">Clear filter — show everyone</a>
            </p>
        @else
            <h2>GRA Applicants</h2>
            <div class="card-divider"></div>
            <p class="queue-meta">Every applicant. Click a bar above to narrow this to one stage.</p>
        @endif

        <div style="overflow-x: auto;">
            <table class="haziq-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Programme</th>
                        <th>Submitted</th>
                        <th>Current Stage</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($applications as $application)
                        @php($profile = $profiles[$application->id]?->profile ?? null)
                        @php($stage = $application->currentStage())
                        @php($canView = $canViewDetail($application))
                        <tr>
                            <td>
                                @if ($canView)
                                    <a href="{{ route('gra-application.show', $application) }}">{{ $application->student->name }}</a>
                                @else
                                    {{ $application->student->name }}
                                    <br><span style="color: var(--text-grey); font-size: 11.5px;">Not at your stage yet</span>
                                @endif
                                @if ($application->student->matric_no)
                                    <br><span style="color: var(--text-grey); font-size: 12px;">{{ $application->student->matric_no }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($profile)
                                    {{ $profile->programme }} <x-core::status-badge :status="$profile->level_of_study" />
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $application->submitted_at?->format('j M Y') ?? '—' }}</td>
                            <td>{{ $stage?->label ?? '—' }}</td>
                            <td><x-core::status-badge :status="$application->status" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><div class="empty-state">{{ $selectedStage ? "Nobody's at this stage right now." : 'No GRA applicants yet.' }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top: 16px;">{{ $applications->links() }}</div>
    </div>
</div>
@endsection
