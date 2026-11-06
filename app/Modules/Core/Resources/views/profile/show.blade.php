@extends('core::layouts.app')

@section('title', 'Profile')

@section('content')
{{--
    The signed-in user's own record.

    LAYOUT (2026-11-06): an identity band across the top, then one section
    per job -- your record, contact details, password, recent sign-ins --
    each as the step layout the stepper forms use: the section's name and
    why-it-is-this-way on the left, its content on the right. It replaces a
    sticky identity column beside a 2x2 grid of cards whose different
    heights left holes, and whose read-only record looked exactly like the
    editable forms beside it.

    Read-only versus editable is now visible before anyone tries: the record
    is information (label over value, a lock and "Held by CGS"), contact and
    password are forms (fields, a button). Account-settings guidance is
    consistent on this -- say which fields are locked and the route to change
    them, next to them, not in a footnote.

    Most fields are read-only by design; ProfileController explains why.
--}}
@php
    $initials = \Illuminate\Support\Str::of($user->name)
        ->explode(' ')
        ->reject(fn ($p) => $p === '' || str_ends_with($p, '.'))
        ->take(2)
        ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))
        ->implode('');

    // Only the rows this user actually has. A supervisor has no matric
    // number, and an empty "Matric No. —" row is noise on their screen.
    $record = collect([
        ['label' => 'Full Name',  'value' => $user->name],
        ['label' => 'Email',      'value' => $user->email],
        ['label' => 'Matric No.', 'value' => $user->matric_no],
        ['label' => 'Programme',  'value' => $user->programme],
        ['label' => 'Department', 'value' => $user->department],
        ['label' => 'Faculty',    'value' => $user->faculty],
        ['label' => 'Supervisor', 'value' => $supervisor?->name],
    ])->filter(fn ($row) => filled($row['value']));

    // One line under the name: role, then whatever places this person.
    $meta = collect([$user->roleLabel(), $user->matric_no, $user->department])->filter()->implode(' · ');
@endphp

<x-core::page-header title="My Profile"
                     subtitle="Your record as the portal holds it. Your contact number and password are the two things you change here." />

{{-- No flash include here: layouts/app.blade.php already renders one above
     @yield('content'), and a second copy showed every message twice. --}}
<div class="prof">

    {{-- ---- Who this is ---- --}}
    <section class="prof-hero" aria-label="Your account">
        <span class="prof-avatar" aria-hidden="true">{{ $initials }}</span>

        <div class="prof-hero-text">
            <h2 class="prof-name">{{ $user->name }}</h2>
            <p class="prof-meta">{{ $meta }}</p>
            <p class="prof-email">{{ $user->email }}</p>
        </div>

        @if ($stats->isNotEmpty())
            <dl class="prof-stats">
                @foreach ($stats as $stat)
                    <div class="prof-stat">
                        <dd class="tone-{{ $stat['tone'] }}">{{ $stat['value'] }}</dd>
                        <dt>{{ $stat['label'] }}</dt>
                    </div>
                @endforeach
            </dl>
        @endif

        <div class="prof-hero-end">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn-secondary prof-signout">Sign out</button>
            </form>
            <span class="prof-since">Member since {{ $user->created_at?->format('M Y') ?? 'unknown' }}</span>
        </div>
    </section>

    {{-- ---- Your record: information, not a form ---- --}}
    <section class="prof-section" aria-labelledby="prof-record-h">
        <div class="prof-section-aside">
            <h2 id="prof-record-h">Your record</h2>
            <p class="prof-lock">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                Held by CGS
            </p>
            <p>
                Your official record, read-only here. If something is wrong,
                contact CGS: a programme or supervisor change moves deadlines and
                approval routing, so it goes through them.
            </p>
        </div>

        <dl class="prof-record">
            @foreach ($record as $row)
                <div class="prof-record-item">
                    <dt>{{ $row['label'] }}</dt>
                    <dd>{{ $row['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    {{-- ---- Contact details: the one field you own ---- --}}
    <section class="prof-section" aria-labelledby="prof-contact-h">
        <div class="prof-section-aside">
            <h2 id="prof-contact-h">Contact details</h2>
            <p>Used when CGS or your supervisor needs to reach you about an application.</p>
        </div>

        <form method="POST" action="{{ route('profile.contact') }}" class="prof-form">
            @csrf
            @method('PATCH')

            <label for="contact_no">Contact Number</label>
            <div class="prof-inline">
                <input type="tel" name="contact_no" id="contact_no"
                       value="{{ old('contact_no', $user->contact_no) }}"
                       placeholder="e.g. 012-345 6789"
                       autocomplete="tel"
                       class="@error('contact_no') is-invalid @enderror">
                <button type="submit">Save Contact Details</button>
            </div>
            @error('contact_no') <p class="field-error">{{ $message }}</p> @enderror
            <p class="prof-hint">Leave it empty to remove it.</p>
        </form>
    </section>

    {{-- ---- Password ---- --}}
    <section class="prof-section" aria-labelledby="prof-password-h">
        <div class="prof-section-aside">
            <h2 id="prof-password-h">Password</h2>
            <p>You stay signed in on this device after changing it.</p>
        </div>

        <form method="POST" action="{{ route('profile.password') }}" class="prof-form" data-password-form>
            @csrf
            @method('PUT')

            <div class="prof-trio">
                <div class="prof-field">
                    <label for="current_password">Current Password</label>
                    <input type="password" name="current_password" id="current_password" required
                           autocomplete="current-password"
                           class="@error('current_password') is-invalid @enderror">
                    @error('current_password') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="prof-field">
                    <label for="password">New Password</label>
                    <input type="password" name="password" id="password" required minlength="8"
                           autocomplete="new-password" aria-describedby="password-checks"
                           class="@error('password') is-invalid @enderror">
                    @error('password') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div class="prof-field">
                    <label for="password_confirmation">Confirm New Password</label>
                    <input type="password" name="password_confirmation" id="password_confirmation" required
                           autocomplete="new-password" aria-describedby="password-checks">
                </div>
            </div>

            {{-- Ticks as you type. A convenience: the controller's validation
                 is the rule, and without JavaScript these read as plain
                 requirements. --}}
            <ul class="prof-checks" id="password-checks" aria-live="polite">
                <li data-check="length">At least 8 characters</li>
                <li data-check="match">Both new passwords match</li>
            </ul>

            <div class="prof-actions">
                <button type="submit">Change Password</button>
            </div>
        </form>
    </section>

    {{-- ---- Recent sign-ins ---- --}}
    <section class="prof-section" aria-labelledby="prof-signins-h">
        <div class="prof-section-aside">
            <h2 id="prof-signins-h">Recent sign-ins</h2>
            <p>If you see one you do not recognise, change your password and tell CGS.</p>
            @if ($user->isAdmin())
                <p><a href="{{ route('admin.audit.index') }}" class="prof-link">Full audit log</a></p>
            @endif
        </div>

        <ul class="prof-signins">
            @forelse ($signIns as $i => $event)
                <li @class(['prof-signin', 'is-current' => $i === 0])>
                    <span class="prof-signin-when">{{ $event['at']?->format('j M Y, g:ia') ?? 'Unknown time' }}</span>
                    <span class="prof-signin-meta">{{ ucfirst($event['action']) }}@if ($event['ip']) from {{ $event['ip'] }}@endif</span>
                    @if ($i === 0)<span class="prof-signin-now">This session</span>@endif
                </li>
            @empty
                <li class="prof-empty">No sign-in activity recorded yet.</li>
            @endforelse
        </ul>
    </section>
</div>

@push('scripts')
    <script @cspNonce>
        (function () {
            var form = document.querySelector('[data-password-form]');
            if (!form) return;
            var pw = form.querySelector('#password');
            var confirm = form.querySelector('#password_confirmation');
            var length = form.querySelector('[data-check="length"]');
            var match = form.querySelector('[data-check="match"]');

            function sync() {
                length.classList.toggle('is-met', pw.value.length >= 8);
                match.classList.toggle('is-met', pw.value.length > 0 && pw.value === confirm.value);
            }

            pw.addEventListener('input', sync);
            confirm.addEventListener('input', sync);
            sync();
        })();
    </script>
@endpush
@endsection
