@extends('core::layouts.app')

@section('title', 'Update Email: '.$examiner->name)

@section('content')
<div class="card-container-inline">
    <x-core::page-header
        :title="'Update Email: '.$examiner->name"
        subtitle="Only the email changes here. The old address and your reason are kept in the examiner's contact history." />

    <div class="card card-wide">
        <form method="POST" action="{{ route('examiner-admin.email.update', $examiner) }}">
            @csrf
            @method('PUT')

            <p class="fstep-hint">Email on record: {{ $examiner->email ?? 'none' }}</p>

            <label for="email">New email</label>
            <input type="email" name="email" id="email" required maxlength="150" value="{{ old('email') }}"
                   class="@error('email') is-invalid @enderror">
            @error('email') <p class="field-error">{{ $message }}</p> @enderror

            <label for="reason">Reason</label>
            <input type="text" name="reason" id="reason" required maxlength="500" value="{{ old('reason') }}"
                   placeholder="e.g. New address from the examiner's university staff page"
                   class="@error('reason') is-invalid @enderror">
            @error('reason') <p class="field-error">{{ $message }}</p> @enderror

            <div class="decision-row">
                <a href="{{ route('examiner-admin.show', $examiner) }}" class="btn-secondary">Cancel</a>
                <button type="submit">Save email</button>
            </div>
        </form>
    </div>
</div>
@endsection
