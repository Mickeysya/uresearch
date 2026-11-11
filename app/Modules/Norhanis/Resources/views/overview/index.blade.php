@extends('core::layouts.app')

@php
    $title = $scope ? $modules[$scope].' Applications' : 'Travel, Claims & Publication Applications';
@endphp

@section('title', $title)

@section('content')
    <x-core::page-header :title="$title" :subtitle="$subtitle">
        @if (in_array($role, \App\Modules\Norhanis\Http\Controllers\ApplicationReportController::REPORT_ROLES, true))
            <a href="{{ route('norhanis.report', ['module' => $scope]) }}" class="btn-secondary">Reports &amp; analytics</a>
        @endif
    </x-core::page-header>

    {{-- ---- Dashboard ------------------------------------------------------
         A summary of the same rows as the list below. Every figure is a link
         that filters the list to exactly those rows. The deeper analysis
         (days per stage, monthly intake, charts, export) stays on Reports &
         analytics. --}}
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
                        <td>
                            {{ $stage?->label ?? 'Closed' }}
                            @if ($stage)
                                <br><span style="color: var(--text-grey);">Awaiting {{ \App\Modules\Norhanis\Support\StageAction::forStage($stage)->noun() }}</span>
                            @endif
                        </td>
                        <td>{{ $application->submitted_at?->format('j M Y') }}</td>
                        <td>
                            {{-- Only when it is on this reader's own stage: that
                                 is the one place they can act on it. --}}
                            <span style="display: inline-flex; flex-wrap: wrap; gap: 6px;">
                                @if ($stage && $stage->role === $role)
                                    <a href="{{ route($module->queueRoute(), ['stage' => $stage->key, 'q' => '#'.$application->id]) }}" class="btn-secondary">{{ \App\Modules\Norhanis\Support\StageAction::forStage($stage)->label() }}</a>
                                @endif
                                <x-core::doc-link class="btn-secondary" title="Summary of the application as a PDF" data-region-swap-ignore
                                    :url="route('norhanis.pack.pdf', [$application, 'inline' => 1])"
                                    :download="route('norhanis.pack.pdf', $application)" name="Application summary">PDF</x-core::doc-link>
                                <a href="{{ route('norhanis.pack.zip', $application) }}" class="btn-secondary" title="The summary and every file you may open, as a ZIP">ZIP</a>
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
    </style>
@endpush
