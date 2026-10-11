@extends('core::layouts.app')

@php($title = $scope ? $modules[$scope].' Applications' : 'Travel, Claims & Publication Applications')

@section('title', $title)

@section('content')
    <x-core::page-header title="{{ $title }}"
                         subtitle="{{ $ownStudentsOnly
                             ? 'Every application your students have submitted, and where each one has reached.'
                             : 'Every application submitted, and where each one has reached.' }}" />

    {{-- How many, by where they stand. Each figure is a link that filters the
         list below to exactly those rows. --}}
    <table class="recent-activity-table">
        <tr>
            <th>Module</th>
            @foreach ($statuses as $status)
                <th>{{ ucfirst($status) }}</th>
            @endforeach
            <th>Total</th>
        </tr>
        @foreach ($modules as $slug => $label)
            @php($row = $counts->get($keys[$slug], collect()))
            <tr>
                <td><a href="{{ route('norhanis.overview', ['module' => $slug]) }}">{{ $label }}</a></td>
                @foreach ($statuses as $status)
                    <td><a href="{{ route('norhanis.overview', ['module' => $slug, 'status' => $status]) }}">{{ $row->get($status, 0) }}</a></td>
                @endforeach
                <td><b>{{ $row->sum() }}</b></td>
            </tr>
        @endforeach
    </table>

    {{-- The pending ones, by whose stage they are waiting on. The reader's
         own stage is marked, since that is the pile that is theirs to clear. --}}
    <table class="recent-activity-table" style="margin-top: 12px;">
        <tr>
            <th>Pending, waiting for</th>
            @foreach ($stages as $stageRole => $stageLabel)
                <th>{{ $stageLabel }}@if ($stageRole === $role) (you)@endif</th>
            @endforeach
        </tr>
        <tr>
            <td>Applications</td>
            @foreach ($stages as $stageRole => $stageLabel)
                <td><a href="{{ route('norhanis.overview', ['module' => $scope, 'stage' => $stageRole]) }}">{{ $waiting[$stageRole] }}</a></td>
            @endforeach
        </tr>
    </table>

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
                    @php($module = $application->module())
                    @php($stage = $application->isOpen() ? $application->currentStage() : null)
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
                            @if ($stage && $stage->role === $role)
                                <a href="{{ route($module->queueRoute(), ['stage' => $stage->key, 'q' => '#'.$application->id]) }}" class="btn-secondary">Review</a>
                            @endif
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
