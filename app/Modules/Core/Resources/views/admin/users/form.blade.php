@extends('core::layouts.app')

@section('title', $user->exists ? 'Edit User' : 'Add a User')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        :title="$user->exists ? 'Edit User' : 'Add a User'"
        :subtitle="$user->exists ? 'Change their role or department, or fix their name and email.' : 'They can sign in with this password as soon as the account is saved.'">
        <a href="{{ route('admin.users.index') }}" class="btn-secondary">Back to the list</a>
    </x-core::page-header>

    <div class="card card-wide">
        <form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}">
            @csrf
            @if ($user->exists) @method('PUT') @endif

            <label for="name">Name</label>
            <input type="text" name="name" id="name" required value="{{ old('name', $user->name) }}"
                   class="@error('name') is-invalid @enderror">
            @error('name') <p class="field-error">{{ $message }}</p> @enderror

            <label for="email">Email</label>
            <input type="email" name="email" id="email" required value="{{ old('email', $user->email) }}"
                   class="@error('email') is-invalid @enderror">
            @error('email') <p class="field-error">{{ $message }}</p> @enderror

            @unless ($user->exists)
                <label for="password">Password</label>
                <input type="password" name="password" id="password" required
                       class="@error('password') is-invalid @enderror">
                <p class="field-hint">At least 8 characters. They can change it themselves from their profile afterwards.</p>
                @error('password') <p class="field-error">{{ $message }}</p> @enderror
            @endunless

            <label for="role">Role</label>
            <select name="role" id="role" required class="@error('role') is-invalid @enderror">
                <option value="">Choose a role</option>
                @foreach ($roles as $role)
                    <option value="{{ $role }}" @selected(old('role', $user->role) === $role)>
                        {{ \App\Modules\Core\Support\Role::label($role) }}
                    </option>
                @endforeach
            </select>
            @error('role') <p class="field-error">{{ $message }}</p> @enderror

            <label for="department">Department <span style="color: var(--text-grey);">(optional)</span></label>
            <select name="department" id="department" class="@error('department') is-invalid @enderror">
                <option value="">No department</option>
                @foreach ($departments as $department)
                    <option value="{{ $department }}" @selected(old('department', $user->department) === $department)>{{ $department }}</option>
                @endforeach
            </select>
            <p class="field-hint">
                Chair of Department and Academic Executive queues are filtered to this department, so it decides which applications they see.
            </p>
            @error('department') <p class="field-error">{{ $message }}</p> @enderror

            <label for="faculty">Faculty <span style="color: var(--text-grey);">(optional)</span></label>
            <input type="text" name="faculty" id="faculty" value="{{ old('faculty', $user->faculty) }}"
                   class="@error('faculty') is-invalid @enderror">
            @error('faculty') <p class="field-error">{{ $message }}</p> @enderror

            <button type="submit">{{ $user->exists ? 'Save changes' : 'Add user' }}</button>
        </form>
    </div>
</div>
@endsection
