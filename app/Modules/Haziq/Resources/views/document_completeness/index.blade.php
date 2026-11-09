@extends('core::layouts.app')

@section('title', 'Document Completeness')

@push('head')
    @include('haziq::partials.styles')
@endpush

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Document Completeness"
        subtitle="Who has uploaded every required document for their GRA or GA application, and who has not. A missing document never blocks an application from moving to its next approver; it only drives the weekly reminder below." />

    <div class="card card-wide">
        <form method="GET" action="{{ route('document-completeness.index') }}" class="haziq-filter-bar">
            <select name="module" data-autosubmit>
                <option value="">GRA and GA</option>
                <option value="gra_application" @selected($module === 'gra_application')>GRA Application</option>
                <option value="ga_application" @selected($module === 'ga_application')>GA Application</option>
            </select>
        </form>
        @include('haziq::partials.filter_autosubmit_scripts')

        @if ($rows->isEmpty())
            <div class="empty-state">No active GRA or GA application for this filter.</div>
        @else
            <div style="height: 220px; max-width: 420px;">
                <canvas id="document-completeness-chart" role="img"
                        aria-label="Document completeness: {{ $counts['complete'] }} complete, {{ $counts['incomplete'] }} incomplete"></canvas>
            </div>

            @include('core::dashboard.partials.chartjs')
            @push('scripts')
                <script @cspNonce>
                    (function () {
                        var el = document.getElementById('document-completeness-chart');
                        if (! el || typeof Chart === 'undefined') return;

                        new Chart(el, {
                            type: 'doughnut',
                            data: {
                                labels: ['Complete', 'Incomplete'],
                                datasets: [{
                                    data: [{{ $counts['complete'] }}, {{ $counts['incomplete'] }}],
                                    // --tone-* on purpose, not --success-fg/--warning-fg: these
                                    // are the solid fill under a WHITE datalabel, and the tone
                                    // tokens are deliberately the same saturated colour in both
                                    // themes (tokens.css) so white text stays legible on them
                                    // either way -- the status -fg tokens turn light in dark
                                    // mode specifically for text-on-tint use, which would wash
                                    // out white text on top of them here.
                                    backgroundColor: [
                                        Chart.uresearchToken('--tone-green', '#00A857'),
                                        Chart.uresearchToken('--tone-orange', '#F08A24'),
                                    ],
                                    borderWidth: 0,
                                }],
                            },
                            options: {
                                cutout: '62%',
                                animation: { duration: 650, easing: 'easeOutQuart' },
                                plugins: {
                                    legend: { display: true, position: 'bottom' },
                                    datalabels: { display: true, color: '#fff', font: { size: 13, weight: '700' } },
                                },
                            },
                        });
                    })();
                </script>
            @endpush

            <p class="queue-meta" style="margin-top: 14px;">
                {{ $counts['complete'] }} {{ Str::plural('student', $counts['complete']) }} with every required
                document on record, and {{ $counts['incomplete'] }} still missing at least one.
            </p>

            <form method="POST" action="{{ route('document-completeness.remind-all', $module ? ['module' => $module] : []) }}" style="margin: 8px 0 16px;">
                @csrf
                <button type="submit" class="btn" @disabled($counts['incomplete'] === 0)
                        data-confirm="Send a reminder email and in-app notification to every student with an incomplete application right now, instead of waiting for the weekly schedule. Continue?">
                    Send Reminder to All ({{ $counts['incomplete'] }})
                </button>
            </form>
            @include('haziq::partials.confirm_scripts')

            <div style="overflow-x: auto;">
                <table class="haziq-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Module</th>
                            <th>Status</th>
                            <th>Missing Documents</th>
                            <th>Last Reminded</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>{{ $row['student']?->name ?? '—' }}
                                    <br><span style="color: var(--text-grey); font-size: 12px;">
                                        {{ $row['student']?->matric_no }} &middot; {{ $row['application']->reference() }}
                                    </span>
                                </td>
                                <td>{{ $row['module'] }}</td>
                                <td>
                                    @if ($row['complete'])
                                        <span class="status-badge approved">Complete</span>
                                    @else
                                        <span class="status-badge pending">Incomplete</span>
                                    @endif
                                </td>
                                <td>{{ $row['missing']->isEmpty() ? '—' : $row['missing']->implode(', ') }}</td>
                                <td>{{ $row['lastReminded'] ? $row['lastReminded']->format('j M Y, g:ia') : 'Never' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
