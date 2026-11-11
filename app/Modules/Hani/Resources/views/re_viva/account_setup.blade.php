@extends('core::layouts.guest')
@section('content')
<div class="card-container">
    <div class="card">
        <h2>Set your password</h2>
        <p class="fstep-hint">Your UResearch account: {{ $email }}</p>
        <div class="card-divider"></div>
        @if ($errors->any()) <p class="message-error">{{ $errors->first() }}</p> @endif
        <form method="POST" action="{{ $action }}">
            @csrf
            <label for="password">New password</label>
            <input type="password" id="password" name="password" required autocomplete="new-password">
            <label for="password_confirmation">Confirm password</label>
            <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
            <button type="submit">Set password</button>
        </form>
    </div>
</div>
@endsection
