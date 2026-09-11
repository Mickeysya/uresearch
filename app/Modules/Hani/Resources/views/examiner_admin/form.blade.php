@extends('core::layouts.app')

@section('title', 'Add Examiner')

@section('content')
<div class="card-container-inline">
    <div class="card card-wide">
        <h2>Add Examiner</h2>
        <div class="card-divider"></div>

        <form method="POST" action="{{ route('examiner-admin.store') }}">
            @csrf

            <label for="name">Name</label>
            <input type="text" name="name" id="name" required value="{{ old('name') }}"
                   class="@error('name') is-invalid @enderror">
            @error('name') <p class="field-error">{{ $message }}</p> @enderror

            <label for="email">Email</label>
            <input type="email" name="email" id="email" required value="{{ old('email') }}"
                   class="@error('email') is-invalid @enderror">
            @error('email') <p class="field-error">{{ $message }}</p> @enderror

            <label for="department">Department</label>
            <input type="text" name="department" id="department" required value="{{ old('department') }}"
                   class="@error('department') is-invalid @enderror">
            @error('department') <p class="field-error">{{ $message }}</p> @enderror

            <label for="faculty">Faculty <span style="color: var(--text-grey)">(optional, e.g. FOE, FSMC)</span></label>
            <input type="text" name="faculty" id="faculty" value="{{ old('faculty') }}"
                   class="@error('faculty') is-invalid @enderror">
            @error('faculty') <p class="field-error">{{ $message }}</p> @enderror

            <label for="type">Type</label>
            <select name="type" id="type" required class="@error('type') is-invalid @enderror">
                <option value="internal" @selected(old('type') == 'internal')>Internal</option>
                <option value="external" @selected(old('type') == 'external')>External</option>
            </select>
            @error('type') <p class="field-error">{{ $message }}</p> @enderror

            <button type="submit">Add Examiner</button>
        </form>
    </div>
</div>
@endsection
