@extends('core::layouts.app')

@section('title', $department->exists ? 'Rename Department' : 'Add a Department')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        :title="$department->exists ? 'Rename Department' : 'Add a Department'"
        :subtitle="$department->exists
            ? 'Every account currently filed under the old name is moved to the new one, including the Chair and Academic Executive queues it filters.'
            : 'It appears on the department field the moment it is added.'">
        <a href="{{ route('admin.departments.index') }}" class="btn-secondary">Back to the list</a>
    </x-core::page-header>

    <div class="card card-wide">
        <form method="POST" action="{{ $department->exists ? route('admin.departments.update', $department) : route('admin.departments.store') }}">
            @csrf
            @if ($department->exists) @method('PUT') @endif

            <label for="name">Department name</label>
            <input type="text" name="name" id="name" required
                   value="{{ old('name', $department->name) }}"
                   placeholder="e.g. Computer &amp; Information Science"
                   class="@error('name') is-invalid @enderror">
            @error('name') <p class="field-error">{{ $message }}</p> @enderror

            <button type="submit">{{ $department->exists ? 'Save the new name' : 'Add department' }}</button>
        </form>
    </div>
</div>
@endsection
