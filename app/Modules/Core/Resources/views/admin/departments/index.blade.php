@extends('core::layouts.app')

@section('title', 'Faculties and Departments')

@section('content')

{{-- Scoped to this page; .data-table, .status-badge and .empty-state are
     already global via partials/stylesheets.blade.php. --}}
<style>
    .dept-table-wrap {
        background: var(--surface);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-lg);
        padding: 0 var(--space-6) var(--space-6);
        overflow-x: auto;
    }

    .dept-table { width: 100%; }

    /* Every column reads down its own centre line, headings included. The
       base .data-table pads on the right only, which would pull centred
       content off that line, so this one pads both sides evenly. */
    .dept-table th,
    .dept-table td {
        text-align: center;
        padding-left: var(--space-3);
    }

    .dept-table td:last-child { white-space: nowrap; }
    .dept-table td:last-child a, .dept-table td:last-child button {
        margin: 0 var(--space-1); padding: var(--space-1) var(--space-3); font-size: var(--text-sm);
    }
    .dept-table tr.is-retired td { color: var(--text-grey); }
    .dept-count { color: var(--text-grey); font-variant-numeric: tabular-nums; }

    /* The faculty band. A row in the same table rather than a table each, so
       every column still lines up down the whole page. It is a heading rather
       than a cell, so it stays at the left edge while the columns centre. */
    .dept-faculty > td {
        text-align: left;
        padding: var(--space-4) var(--space-3);
        border-bottom: 1px solid var(--border-grey);
        background: var(--surface-sunken);
    }
    tbody:first-of-type .dept-faculty > td { border-radius: var(--radius-sm) var(--radius-sm) 0 0; }

    .dept-faculty-head { display: flex; align-items: baseline; gap: var(--space-3); flex-wrap: wrap; }
    .dept-faculty-head h2 {
        margin: 0;
        font-size: var(--text-base);
        font-weight: 700;
        color: var(--navy);
    }

    .dept-faculty-code {
        padding: 2px var(--space-2);
        border: 1px solid var(--border-grey);
        border-radius: var(--radius-sm);
        background: var(--surface-sunken);
        font-size: var(--text-xs);
        font-weight: 700;
        letter-spacing: 0.4px;
        color: var(--text-grey);
    }

    .dept-faculty-meta { font-size: var(--text-sm); color: var(--text-grey); }
    .dept-faculty-note { margin: var(--space-1) 0 0; font-size: var(--text-sm); color: var(--text-grey); max-width: 68ch; }

    .dept-name { font-weight: 600; }

    /* A department with accounts but nobody on the Academic Executive desk
       has an examiner-nomination queue no one can clear -- that is a gap
       worth colouring. One nobody is filed under yet is just empty. */
    .dept-exec-none { color: var(--warning-fg); }
    .dept-exec-empty { color: var(--text-grey); }
</style>

<div class="card-container-inline">
    <x-core::page-header
        title="Faculties and Departments"
        subtitle="The list every account's department is picked from, under the faculty it belongs to. Renaming one here updates every account already filed under the old name.">
        <a href="{{ route('admin.departments.create') }}" class="btn">Add a department</a>
    </x-core::page-header>

    @if ($groups->isEmpty())
        <div class="empty-state">
            <p>No departments yet.</p>
            <p class="queue-meta">Add the first one so it appears on the "add a user" form.</p>
            <a href="{{ route('admin.departments.create') }}" class="btn">Add a department</a>
        </div>
    @else
        <div class="dept-table-wrap">
            <table class="data-table dept-table">
                <thead>
                    <tr>
                        <th>Department</th>
                        <th>Academic Executive</th>
                        <th>Accounts</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>

                @foreach ($groups as $group)
                    <tbody>
                        <tr class="dept-faculty">
                            <td colspan="5">
                                <div class="dept-faculty-head">
                                    <h2>{{ $group['label'] }}</h2>
                                    @if ($group['code'])
                                        <span class="dept-faculty-code">{{ $group['code'] }}</span>
                                    @endif
                                    <span class="dept-faculty-meta">
                                        {{ $group['departments']->count() }}
                                        {{ \Illuminate\Support\Str::plural('department', $group['departments']->count()) }}
                                    </span>
                                </div>
                                @if ($group['note'])
                                    <p class="dept-faculty-note">{{ $group['note'] }}</p>
                                @endif
                            </td>
                        </tr>

                        @foreach ($group['departments'] as $department)
                            <tr @unless ($department->is_active) class="is-retired" @endunless>
                                <td class="dept-name">{{ $department->name }}</td>
                                <td>
                                    @php($covering = $execs[$department->name] ?? collect())
                                    @if ($covering->isNotEmpty())
                                        {{ $covering->pluck('name')->join(', ') }}
                                    @elseif (($counts[$department->name] ?? 0) > 0)
                                        <span class="dept-exec-none">Nobody assigned</span>
                                    @else
                                        <span class="dept-exec-empty">&mdash;</span>
                                    @endif
                                </td>
                                <td><span class="dept-count">{{ $counts[$department->name] ?? 0 }}</span></td>
                                <td>
                                    <span class="status-badge {{ $department->is_active ? 'approved' : 'draft' }}">
                                        {{ $department->is_active ? 'Active' : 'Retired' }}
                                    </span>
                                </td>
                                <td>
                                    <a href="{{ route('admin.departments.edit', $department) }}" class="btn-secondary">Edit</a>
                                    <form method="POST" action="{{ route('admin.departments.toggle', $department) }}" style="display:inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" @class(['btn-reject' => $department->is_active])>
                                            {{ $department->is_active ? 'Retire' : 'Reactivate' }}
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </div>
    @endif
</div>

@endsection
