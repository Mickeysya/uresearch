@extends('core::layouts.guest')

{{-- The sign-in in front of an examiner's re-viva link. Names nothing about
     the candidate, the examiner or the round. Every form posts back to the
     signed URL itself: a signature only holds for its own path. No scripts. --}}
@section('content')
<div class="card-container">
    <div class="card">
        <h2>Examiner sign-in</h2>
        <p class="fstep-hint">Centre for Graduate Studies, Universiti Teknologi PETRONAS</p>
        <div class="card-divider"></div>

        @if (session('status'))
            <p class="message-success">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p class="message-error">{{ $errors->first() }}</p>
        @endif

        @if ($awaitingCode)
            <form method="POST" action="{{ $action }}">
                @csrf
                <input type="hidden" name="step" value="code">
                <label for="code">6-digit code</label>
                <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus autocomplete="one-time-code">
                <button type="submit">Sign in</button>
            </form>
            <form method="POST" action="{{ $action }}" style="margin-top: 12px">
                @csrf
                <input type="hidden" name="step" value="restart">
                <button type="submit" class="btn-secondary">Use a different email or get a new code</button>
            </form>
        @else
            <p>To open the documents, enter the email address your letter was sent to. We will email you a one-time code.</p>
            <form method="POST" action="{{ $action }}">
                @csrf
                <input type="hidden" name="step" value="email">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
                <button type="submit">Email me a code</button>
            </form>
        @endif
    </div>
</div>
@endsection
