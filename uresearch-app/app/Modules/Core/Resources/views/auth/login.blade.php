@extends('core::layouts.guest')

@section('title', 'Log In')

@section('content')
<div class="card-container">
    <div class="card">
        <h2>Log In</h2>
        <div class="card-divider"></div>

        @if ($errors->any())
            <p class="message-error">{{ $errors->first() }}</p>
        @endif

        <form method="POST" action="{{ route('login.store') }}">
            @csrf

            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                   autocomplete="username" class="@error('email') is-invalid @enderror">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="current-password"
                   class="@error('password') is-invalid @enderror">

            <div class="checkbox-row">
                <input type="checkbox" name="remember" id="remember" value="1">
                <label for="remember">Keep me signed in</label>
            </div>

            <button type="submit">Log In</button>
        </form>
    </div>
</div>
@endsection
