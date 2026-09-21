@extends('core::layouts.app')

@section('title', 'Departments')

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
    .dept-table td:last-child { text-align: right; white-space: nowrap; }
    .dept-table td:last-child a, .dept-table td:last-child button {
        margin: 0 0 0 var(--space-2); padding: var(--space-1) var(--space-3); font-size: var(--text-sm);
    }
    .dept-table tr.is-retired td { color: var(--text-grey); }
    .dept-count { color: var(--text-grey); font-variant-numeric: tabular-nums; }
</style>

<div class="card-container-inline">
    <x-core::page-header
        title="Departments"
        subtitle="The list every account's department is picked from. Renaming one here updates every account already filed under the old name.">
        <a href="{{ route('admin.departments.create') }}" class="btn">Add a department</a>
    </x-core::page-header>

    @if ($departments->isEmpty())
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
                        <th>Name</th>
                        <th>Accounts</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($departments as $department)
                        <tr @unless ($department->is_active) class="is-retired" @endunless>
                            <td>{{ $department->name }}</td>
                            <td><span class="dept-count">{{ $counts[$department->name] ?? 0 }}</span></td>
                            <td>
                                <span class="status-badge {{ $department->is_active ? 'approved' : 'draft' }}">
                                    {{ $department->is_active ? 'Active' : 'Retired' }}
                                </span>
                            </td>
                            <td>
                                <a href="{{ route('admin.departments.edit', $department) }}" class="btn-secondary">Rename</a>
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
            </table>
        </div>
    @endif
</div>

@endsection
