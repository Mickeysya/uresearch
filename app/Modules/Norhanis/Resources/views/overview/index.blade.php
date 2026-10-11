@extends('core::layouts.app')

@section('title', 'Travel, Claims & Publication Applications')

@section('content')
    @php($registry = app(\App\Modules\Core\Services\ModuleRegistry::class))
    @php($moduleKeys = \App\Modules\Norhanis\Http\Controllers\ApplicationOverviewController::MODULES)

    <x-core::page-header title="Travel, Claims & Publication Applications"
                         subtitle="{{ $ownStudentsOnly
                             ? 'Every application your students have submitted, and where each one has reached.'
                             : 'Every application submitted, and where each one has reached.' }}" />

    {{-- How many of each, by where they stand. Each figure is a link that
         filters the list below to exactly those rows. --}}
    <table class="recent-activity-table">
        <tr>
            <th>Module</th>
            @foreach ($statuses as $status)
                <th>{{ ucfirst($status) }}</th>
            @endforeach
            <th>Total</th>
        </tr>
        @foreach ($moduleKeys as $key)
            @php($row = $counts->get($key, collect()))
            <tr>
                <td><a href="{{ route('norhanis.overview', ['module' => $key]) }}">{{ $registry->get($key)->label() }}</a></td>
                @foreach ($statuses as $status)
                    <td><a href="{{ route('norhanis.overview', ['module' => $key, 'status' => $status]) }}">{{ $row->get($status, 0) }}</a></td>
                @endforeach
                <td><b>{{ $row->sum() }}</b></td>
            </tr>
        @endforeach
    </table>

    <form method="GET" class="queue-tools" style="margin-top: 20px;">
        <div class="queue-tool-field">
            <label for="overview-q">Student name, matric no. or application no.</label>
            <input type="search" name="q" id="overview-q" value="{{ $filters['q'] }}" placeholder="e.g. Aina, 22001001 or #42">
        </div>

        <div class="queue-tool-field">
            <label for="overview-module">Module</label>
            <select name="module" id="overview-module">
                <option value="">All three</option>
                @foreach ($moduleKeys as $key)
                    <option value="{{ $key }}" @selected($filters['module'] === $key)>{{ $registry->get($key)->label() }}</option>
                @endforeach
            </select>
        </div>

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
            <label for="overview-sort">Order</label>
            <select name="sort" id="overview-sort">
                <option value="newest" @selected($filters['sort'] === 'newest')>Newest first</option>
                <option value="oldest" @selected($filters['sort'] === 'oldest')>Oldest first</option>
            </select>
        </div>

        <button type="submit">Show</button>
        <a href="{{ route('norhanis.overview') }}" class="queue-clear">Clear</a>
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
                    <th>Module</th>
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
                        <td>{{ $module->label() }}</td>
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

        <div style="margin-top: 16px;">
            {{ $applications->links() }}
        </div>
    @endif
@endsection
