@extends('core::layouts.app')

@section('title', $department->exists ? 'Edit Department' : 'Add a Department')

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        :title="$department->exists ? 'Edit Department' : 'Add a Department'"
        :subtitle="$department->exists
            ? 'Every account currently filed under the old name is moved to the new one, including the Chair and Academic Executive queues it filters. The faculty is this list\'s own and moves nobody.'
            : 'It appears on the department field the moment it is added, under the faculty you put it in.'">
        <a href="{{ route('admin.departments.index') }}" class="btn-secondary">Back to the list</a>
    </x-core::page-header>

    <div class="card card-wide">
        <form method="POST" action="{{ $department->exists ? route('admin.departments.update', $department) : route('admin.departments.store') }}">
            @csrf
            @if ($department->exists) @method('PUT') @endif

            <label for="name">Department name</label>
            <input type="text" name="name" id="name" required
                   value="{{ old('name', $department->name) }}"
                   placeholder="e.g. Computing"
                   class="@error('name') is-invalid @enderror">
            @error('name') <p class="field-error">{{ $message }}</p> @enderror

            <label for="faculty">Faculty <span style="color: var(--text-grey);">(optional)</span></label>
            <select name="faculty" id="faculty" class="@error('faculty') is-invalid @enderror">
                <option value="">Not under a faculty</option>
                @foreach (\App\Modules\Core\Support\Faculty::all() as $code)
                    <option value="{{ $code }}" @selected(old('faculty', $department->faculty) === $code)>
                        {{ \App\Modules\Core\Support\Faculty::label($code) }} ({{ $code }})
                    </option>
                @endforeach
            </select>
            <p class="field-hint">
                It groups the department on this list and on the department picker. CGS is not a choice: it coordinates
                postgraduate candidature through these same departments rather than having its own.
            </p>
            @error('faculty') <p class="field-error">{{ $message }}</p> @enderror

            <button type="submit">{{ $department->exists ? 'Save changes' : 'Add department' }}</button>
        </form>
    </div>
</div>
@endsection
