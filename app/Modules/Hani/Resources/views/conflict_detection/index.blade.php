@extends('core::layouts.app')

@section('title', 'Conflict Detection')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        title="Conflict Detection: Faculty Compilation"
        subtitle="Department nomination lists merged by faculty. An examiner marked Conflict is nominated by more than one department at once. Nothing here is auto-rejected. Decide on a substitution manually, using the pool state shown." />

    <div class="card card-wide" style="max-width: none;">
        @if ($scopedToDepartment)
            <p class="queue-intro">Showing the examiners on {{ $scopedToDepartment }}'s nominations, and every other department that has named them too.</p>
        @endif

        @if ($conflictCount)
            <p class="message-warning">{{ $conflictCount }} {{ Str::plural('examiner', $conflictCount) }} {{ $conflictCount === 1 ? 'is' : 'are' }} named by more than one department.</p>
        @endif

        @if ($duplicates)
            <h3 style="color: var(--navy); font-size: var(--text-md);">Possibly the same person on different lists</h3>
            <ul>
                @foreach (collect($duplicates)->flatten()->unique() as $flag)
                    <li>{{ $flag }}</li>
                @endforeach
            </ul>
        @endif

        @forelse ($byFaculty as $faculty => $rows)
            <h3 style="color: var(--navy); font-size: var(--text-md);">{{ $faculty }}</h3>
            <table class="recent-activity-table">
                <thead>
                    <tr><th>Examiner</th><th>Departments nominating</th><th>Pool state</th><th>Nominations</th></tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        {{-- A token, not a literal: the old #fdecea stayed light on the dark theme. --}}
                        <tr @if ($row['conflicted']) style="background: var(--danger-bg);" @endif>
                            <td>
                                {{ $row['examiner']->name }}
                                @if ($row['conflicted'])
                                    <span class="status-badge rejected">Conflict</span>
                                @endif
                            </td>
                            <td>{{ $row['departments']->implode(', ') ?: '—' }}</td>
                            <td>{{ $row['examiner']->stateLabel() }}</td>
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
