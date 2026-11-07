@extends('core::layouts.app')

@section('title', 'Conflict Detection')

@section('content')
{{-- Every link leaving this page carries data-region-swap-ignore. --}}
<style>
    .cd-entry { padding: var(--space-2) 0; }
    .cd-entry + .cd-entry { border-top: 1px solid var(--border-subtle); }
    .cd-meta { color: var(--text-grey); font-size: var(--text-xs); }
    .cd-note { display: block; color: var(--text-grey); font-size: var(--text-xs); margin-top: var(--space-1); }
    .cd-entry details, .cd-pair details { margin-top: var(--space-2); }
    .cd-entry summary, .cd-pair summary { display: inline-block; cursor: pointer; }
    .cd-entry textarea { width: 100%; }
    .cd-pair { padding: var(--space-3) 0; border-bottom: 1px solid var(--border-subtle); }
    .cd-pair-actions { display: flex; flex-wrap: wrap; gap: var(--space-3); align-items: flex-start; margin-top: var(--space-2); }
    .cd-pair-actions details { margin-top: 0; }
    .cd-record { display: block; margin: var(--space-2) 0; font-weight: normal; }
</style>

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

        @error('keep_id') <p class="field-error">{{ $message }}</p> @enderror

        @if ($duplicatePairs)
            <h3 style="color: var(--navy); font-size: var(--text-md);">Possibly the same person on different lists</h3>

            @foreach ($duplicatePairs as $pair)
                <div class="cd-pair">
                    <b>{{ $pair['a']->name }}</b> and <b>{{ $pair['b']->name }}</b>
                    <span class="cd-meta">({{ $pair['score'] }}% similar)</span>:
                    @foreach ($pair['applications'] as $i => $on)
                        @if ($pair['links'][$i])<a href="{{ $pair['links'][$i] }}" data-region-swap-ignore>#{{ $on['nomination']->application_id }}</a>@else#{{ $on['nomination']->application_id }}@endif
                        <span class="cd-meta">({{ $on['department'] }}, {{ implode(' and ', array_unique($on['names'])) }})</span>@if (! $loop->last), @endif
                    @endforeach

                    <div class="cd-pair-actions">
                        <form method="POST" action="{{ route('examiner-admin.dismiss-duplicate') }}">
                            @csrf
                            <input type="hidden" name="examiner_id" value="{{ $pair['a']->id }}">
                            <input type="hidden" name="other_id" value="{{ $pair['b']->id }}">
                            <button type="submit" class="btn-secondary">Not the same person</button>
                        </form>

                        <details>
                            <summary class="btn-secondary">Same person: merge</summary>
                            <form method="POST" action="{{ route('conflict-detection.merge') }}" style="margin-top: var(--space-2);">
                                @csrf
                                <p class="cd-meta">Pick the record to keep. The other is marked Unavailable, never deleted. Its seats on pending and released panels, expertise and publications move to the record kept.</p>
                                <input type="hidden" name="record_a_id" value="{{ $pair['a']->id }}">
                                <input type="hidden" name="record_b_id" value="{{ $pair['b']->id }}">
                                @foreach ([$pair['a'], $pair['b']] as $record)
                                    <label class="cd-record">
                                        <input type="radio" name="keep_id" value="{{ $record->id }}" required>
                                        <b>{{ $record->name }}</b>
                                        <span class="cd-meta">
                                            {{ $record->email ?: 'no email' }} ·
                                            {{ $record->institution ?: $record->department }} ·
                                            {{ $record->stateLabel() }}
                                        </span>
                                    </label>
                                @endforeach
                                <button type="submit">Merge the records</button>
                            </form>
                        </details>
                    </div>
                </div>
            @endforeach
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
                                @foreach ($row['entries'] as $entry)
                                    @include('hani::conflict_detection._entry', ['entry' => $entry, 'row' => $row])
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
