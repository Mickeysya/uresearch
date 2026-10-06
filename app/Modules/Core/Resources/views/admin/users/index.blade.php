@extends('core::layouts.app')

@section('title', 'Users and Roles')

@section('content')

{{-- Scoped to this page; .data-table, .status-badge and .empty-state are
     already global via partials/stylesheets.blade.php. --}}
<style>
    .user-table-wrap {
        background: var(--surface);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-lg);
        padding: 0 var(--space-6) var(--space-6);
        overflow-x: auto;
    }

    .user-table { width: 100%; }
    .user-table td:last-child { text-align: right; white-space: nowrap; }
    .user-table td:last-child a { padding: var(--space-1) var(--space-3); font-size: var(--text-sm); }
    .user-email { display: block; font-size: var(--text-sm); color: var(--text-grey); }

    .user-filters {
        display: flex; gap: var(--space-3); flex-wrap: wrap; align-items: flex-end; margin: 0 0 var(--space-4);
    }
    .user-filters label { display: block; font-size: var(--text-sm); color: var(--text-grey); margin-bottom: var(--space-1); }
    .user-filters select, .user-filters input { margin: 0; }
</style>

<div class="card-container-inline">
    <x-core::page-header
        title="Users and Roles"
        subtitle="Staff accounts across every department. Students are created through their own module, not here.">
        <a href="{{ route('admin.users.create') }}" class="btn">Add a user</a>
    </x-core::page-header>

    <form method="GET" action="{{ route('admin.users.index') }}" class="user-filters">
        <div>
            <label for="search">Search</label>
            <input type="search" name="search" id="search" value="{{ $filters['search'] }}" placeholder="Name or email">
        </div>
        <div>
            <label for="role">Role</label>
            <select name="role" id="role">
                <option value="">All roles</option>
                @foreach ($roles as $role)
                    <option value="{{ $role }}" @selected($filters['role'] === $role)>{{ \App\Modules\Core\Support\Role::label($role) }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn-secondary">Filter</button>
    </form>

    @if ($users->isEmpty())
        <div class="empty-state">
            <p>No accounts match this filter.</p>
        </div>
    @else
        <div class="user-table-wrap">
            <table class="data-table user-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Department</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr>
                            <td>
                                {{ $user->name }}
                                <span class="user-email">{{ $user->email }}</span>
                            </td>
                            <td><span class="status-badge pending">{{ $user->roleLabel() }}</span></td>
                            <td>{{ $user->department ?: '—' }}</td>
                            <td><a href="{{ route('admin.users.edit', $user) }}" class="btn-secondary">Edit</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @include('core::partials.pagination', ['paginator' => $users, 'label' => 'User pages'])
    @endif
</div>

@endsection
