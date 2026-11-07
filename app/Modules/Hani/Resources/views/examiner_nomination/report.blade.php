@extends('core::layouts.app')

@section('title', 'Examiner Report')

@section('content')

{{-- Scoped to this page; .data-table and .status-badge are already global
     via partials/stylesheets.blade.php. --}}
<style>
    .rep-wrap {
        background: var(--surface);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-lg);
        padding: 0 var(--space-6) var(--space-6);
        overflow-x: auto;
    }

    .rep-table { width: 100%; }
    .rep-table th, .rep-table td { padding-left: var(--space-3); vertical-align: top; }
    .rep-seat { display: block; font-size: var(--text-xs); color: var(--text-grey); }
    .rep-none { color: var(--text-grey); }

    .rep-band > td {
        padding: var(--space-4) var(--space-3);
        background: var(--surface-sunken);
        border-bottom: 1px solid var(--border-grey);
    }
    .rep-band h2 { margin: 0; font-size: var(--text-base); font-weight: 700; color: var(--navy); }
    .rep-band-meta { font-size: var(--text-sm); color: var(--text-grey); }

    /* Arrived from a Conflict Detection link. */
    .rep-table tr:target > td { background: var(--warning-bg); }

    .rep-actions { display: flex; gap: var(--space-3); flex-wrap: wrap; margin: 0 0 var(--space-4); }

    /* The same examiner named by two departments. Flagged, never
       auto-rejected -- the substitution is a human call. */
    .rep-clash {
        display: inline-block;
        margin-left: var(--space-2);
        padding: 1px var(--space-2);
        border-radius: var(--radius-sm);
        background: var(--warning-bg);
        border: 1px solid var(--warning-border);
        color: var(--warning-fg);
        font-size: var(--text-xs);
        font-weight: 600;
    }
</style>

<div class="card-container-inline">
    <x-core::page-header
        title="Examiner Report"
        :subtitle="$scopedToDepartment
            ? $scopedToDepartment.': every panel your department has filed, at whatever stage it has reached.'
            : 'Every department\'s panels merged into one list: the compiled report the chain signs off.'">
    </x-core::page-header>

    <div class="rep-actions">
        <a href="{{ route('examiner-nomination.export', ['format' => 'xlsx']) }}" class="btn-secondary">Download Excel</a>
        @if (auth()->user()->role !== \App\Modules\Core\Support\Role::NON_EXEC_CGS)
            <a href="{{ route('conflict-detection.index') }}" class="btn-secondary">Conflict detection</a>
        @endif
        <a href="{{ route('examiner-nomination.queue') }}" class="btn-secondary">Back to my queue</a>
    </div>

    @if ($clashes->isNotEmpty())
        <p class="queue-intro">
            <b>{{ $clashes->count() }}</b>
            {{ Str::plural('examiner', $clashes->count()) }}
            {{ $clashes->count() === 1 ? 'is' : 'are' }} named by more than one department.
            Flagged below, not removed: deciding on a substitution is yours, not the system's.
        </p>
    @endif

    @if (collect($coiFlags)->flatten(2)->isNotEmpty())
        <p class="coi-note" style="color: var(--text-grey); font-size: var(--text-xs);">{{ \App\Modules\Hani\Support\ConflictOfInterest::NOTE }}</p>
    @endif

    @if ($total === 0)
        <div class="empty-state">
            <p>Nothing has been nominated yet.</p>
            <p class="queue-meta">Panels appear here as soon as a supervisor files one.</p>
        </div>
    @else
        <div class="rep-wrap">
            <table class="data-table rep-table">
                <thead>
                    <tr>
                        <th>Candidate</th>
                        <th>Thesis</th>
                        <th>Internal</th>
                        <th>External</th>
                        <th>Stage</th>
                    </tr>
                </thead>

                @foreach ($byFaculty as $faculty => $departments)
                    @foreach ($departments as $department => $rows)
                        <tbody>
                            <tr class="rep-band">
                                <td colspan="5">
                                    <h2>{{ $department }}</h2>
                                    <span class="rep-band-meta">
                                        {{ $faculty }} · {{ $rows->count() }}
                                        {{ Str::plural('candidate', $rows->count()) }}
                                    </span>
                                </td>
                            </tr>

                            @foreach ($rows as $nomination)
                                @php($application = $nomination->application)
                                <tr id="nomination-{{ $nomination->id }}">
                                    <td>
                                        {{ $application?->student?->name }}
                                        <span class="rep-seat">
                                            {{ $application?->student?->matric_no }} · #{{ $application?->id }}
                                        </span>
                                        {{-- Declines are recorded here, by the department's AE or the Senior Executive. --}}
                                        @if ($application?->status === 'approved' && (auth()->user()->role === \App\Modules\Core\Support\Role::SENIOR_EXEC_CGS || auth()->user()->role === \App\Modules\Core\Support\Role::ACADEMIC_EXEC))
                                            <a href="{{ route('examiner-panel.show', $nomination) }}" class="rep-seat">Manage panel</a>
                                        @endif
                                    </td>
                                    <td>{{ $nomination->thesis_title }}</td>

                                    @foreach (['internalMain' => 'internalBackup', 'externalMain' => 'externalBackup'] as $main => $backup)
                                        <td>
                                            @if ($nomination->$main)
                                                {{ $nomination->$main->name }}
                                                @include('hani::examiner_admin._email_badge', ['examiner' => $nomination->$main])
                                                @include('hani::examiner_nomination._coi', ['flags' => $coiFlags[$application?->id][$nomination->$main->id] ?? []])
                                                @if ($clashes->has($nomination->$main->id))
                                                    <span class="rep-clash"
                                                          title="Also named by: {{ $clashes[$nomination->$main->id]->join(', ') }}">
                                                        also elsewhere
                                                    </span>
                                                @endif
                                            @else
                                                <span class="rep-none">Not named</span>
                                            @endif

                                            <span class="rep-seat">
                                                backup:
                                                @if ($nomination->$backup)
                                                    {{ $nomination->$backup->name }}
                                                    @include('hani::examiner_admin._email_badge', ['examiner' => $nomination->$backup])
                                                    @include('hani::examiner_nomination._coi', ['flags' => $coiFlags[$application?->id][$nomination->$backup->id] ?? []])
                                                    @if ($clashes->has($nomination->$backup->id))
                                                        <span class="rep-clash">also elsewhere</span>
                                                    @endif
                                                @else
                                                    none
                                                @endif
                                            </span>
                                        </td>
                                    @endforeach

                                    <td>
                                        <span class="status-badge {{ $application?->status === 'approved' ? 'approved' : ($application?->status === 'rejected' ? 'rejected' : 'pending') }}">
                                            {{ $application?->currentStage()?->label ?? ucfirst((string) $application?->status) }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach
                @endforeach
            </table>
        </div>
    @endif
</div>

@endsection
