@extends('core::layouts.app')

@section('title', 'Conflict Detection')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Conflict Detection — Faculty Compilation</h2>
        <div class="card-divider"></div>
        <p class="queue-meta">
            Department nomination lists merged by faculty. An examiner flagged in red is
            nominated by more than one department at once — nothing here is auto-rejected;
            decide on a substitution manually using the pool state shown.
        </p>

        @forelse ($byFaculty as $faculty => $rows)
            <h3 style="color: var(--navy); font-size: 15px;">{{ $faculty }}</h3>
            <table class="recent-activity-table">
                <thead>
                    <tr><th>Examiner</th><th>Departments nominating</th><th>Pool state</th><th>Nominations</th></tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr @if ($row['conflicted']) style="background: #fdecea;" @endif>
                            <td>
                                {{ $row['examiner']->name }}
                                @if ($row['conflicted'])
                                    <span class="status-badge rejected">Conflict</span>
                                @endif
                            </td>
                            <td>{{ $row['departments']->implode(', ') ?: '—' }}</td>
                            <td>{{ ucwords(str_replace('_', ' ', $row['examiner']->state())) }}</td>
                            <td>
                                @foreach ($row['nominations'] as $nomination)
                                    #{{ $nomination->application_id }}@if (! $loop->last), @endif
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @empty
            <div class="empty-state">No active nominations to compile yet.</div>
        @endforelse
    </div>
</div>
@endsection
