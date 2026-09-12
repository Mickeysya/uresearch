@extends('core::layouts.app')

@section('title', 'Profile')

@section('content')
{{--
    The signed-in user's own record.

    LAYOUT: identity card on the left, task-grouped cards on the right — the
    shape almost every account screen uses (GitHub, Stripe, LinkedIn), where
    the profile card is the page's visual anchor and everything else is
    grouped by what you came to do. It also fills a wide screen: the earlier
    version capped itself at 1080px and left a third of a 1700px monitor
    empty.

    No tabs. With two groups, hiding one behind a tab costs a click and buys
    nothing; the sub-navigation pattern earns its keep at five or six.

    Built from the dashboards' pieces — .prof-card is .sdash-card's shape and
    the palette is Norhanis' :root — but this page scrolls normally, because a
    form whose submit button sits inside a nested scroller is a form people
    do not finish.

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
@endphp

<div class="prof">
    {{-- No flash include here: layouts/app.blade.php already renders one
         above @yield('content'), and a second copy showed every "Contact
         details updated." twice. --}}

    <div class="prof-layout">

        {{-- ---- Left: the identity card, the page's anchor ---- --}}
        <aside class="prof-aside">
            <section class="prof-card prof-identity">
                <span class="prof-avatar" aria-hidden="true">{{ $initials }}</span>

                <h2 class="prof-name">{{ $user->name }}</h2>
                <p class="prof-email">{{ $user->email }}</p>

                <p class="prof-badges">
                    <span class="prof-badge">{{ $user->roleLabel() }}</span>
                    @if ($user->matric_no)
                        <span class="prof-badge prof-badge-quiet">{{ $user->matric_no }}</span>
                    @endif
                </p>

                @if ($stats->isNotEmpty())
                    <dl class="prof-stats">
                        @foreach ($stats as $stat)
                            <div class="prof-stat">
                                <dt>{{ $stat['label'] }}</dt>
                                <dd class="tone-{{ $stat['tone'] }}">{{ $stat['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif

                <div class="prof-identity-foot">
                    <span class="prof-since">
                        Member since {{ $user->created_at?->format('M Y') ?? '—' }}
                    </span>

                    <form method="POST" action="{{ route('logout') }}" class="prof-signout-form">
                        @csrf
                        <button type="submit" class="prof-signout">Sign out</button>
                    </form>
                </div>
            </section>
        </aside>

        {{-- ---- Right: what you came here to do ---- --}}
        <div class="prof-main">

            <section class="prof-card">
                <header class="prof-card-head">
                    <h3>Your Record</h3>
                    <span class="prof-card-note">Held by CGS</span>
                </header>

                <dl class="prof-record">
                    @foreach ($record as $row)
                        <div class="prof-record-row">
                            <dt>{{ $row['label'] }}</dt>
                            <dd>{{ $row['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>

                <footer class="prof-card-foot">
                    <span class="prof-foot-icon" aria-hidden="true">
                        @include('core::dashboard.partials.icon', ['name' => 'info'])
                    </span>
                    <span>
                        These are your official records and cannot be edited here.
                        If something is wrong, contact CGS — changing a programme or
                        supervisor affects deadlines and approval routing, so it goes
                        through them.
                    </span>
                </footer>
            </section>

            <section class="prof-card">
                <header class="prof-card-head">
                    <h3>Contact Details</h3>
                    <span class="prof-card-note">You can change this</span>
                </header>

                <form method="POST" action="{{ route('profile.contact') }}" class="prof-form">
                    @csrf
                    @method('PATCH')

                    <label for="contact_no">Contact Number</label>
                    <input type="tel" name="contact_no" id="contact_no"
                           value="{{ old('contact_no', $user->contact_no) }}"
                           placeholder="e.g. 012-345 6789"
                           autocomplete="tel"
                           class="@error('contact_no') is-invalid @enderror">
                    @error('contact_no') <p class="field-error">{{ $message }}</p> @enderror

                    <p class="prof-hint">
                        Used when CGS or your supervisor needs to reach you about an
                        application. Leave it empty to remove it.
                    </p>

                    <div class="prof-actions">
                        <button type="submit">Save Contact Details</button>
                    </div>
                </form>
            </section>

            <section class="prof-card">
                <header class="prof-card-head">
                    <h3>Change Password</h3>
                </header>

                <form method="POST" action="{{ route('profile.password') }}" class="prof-form">
                    @csrf
                    @method('PUT')

                    <div class="prof-field-row">
                        <div class="prof-field">
                            <label for="current_password">Current Password</label>
                            <input type="password" name="current_password" id="current_password" required
                                   autocomplete="current-password"
                                   class="@error('current_password') is-invalid @enderror">
                            @error('current_password') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="prof-field-row prof-field-row-split">
                        <div class="prof-field">
                            <label for="password">New Password</label>
                            <input type="password" name="password" id="password" required
                                   autocomplete="new-password"
                                   class="@error('password') is-invalid @enderror">
                            @error('password') <p class="field-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="prof-field">
                            <label for="password_confirmation">Confirm New Password</label>
                            <input type="password" name="password_confirmation" id="password_confirmation" required
                                   autocomplete="new-password">
                        </div>
                    </div>

                    <p class="prof-hint">At least 8 characters. You stay signed in on this device.</p>

                    <div class="prof-actions">
                        <button type="submit">Change Password</button>
                    </div>
                </form>
            </section>

            <section class="prof-card">
                <header class="prof-card-head">
                    <h3>Recent Sign-ins</h3>
                    @if ($user->isAdmin())
                        <a href="{{ route('admin.audit.index') }}" class="prof-card-link">Full audit log</a>
                    @endif
                </header>

                <ul class="prof-signins">
                    @forelse ($signIns as $i => $event)
                        <li class="prof-signin {{ $i === 0 ? 'is-current' : '' }}">
                            <span class="prof-signin-dot" aria-hidden="true"></span>
                            <span class="prof-signin-main">
                                <span class="prof-signin-when">
                                    {{ $event['at']?->format('j M Y, g:ia') ?? 'Unknown time' }}
                                    @if ($i === 0)<span class="prof-signin-now">This session</span>@endif
                                </span>
                                <span class="prof-signin-meta">
                                    {{ ucfirst($event['action']) }}@if ($event['ip']) · {{ $event['ip'] }}@endif
                                </span>
                            </span>
                        </li>
                    @empty
                        <li class="prof-empty">No sign-in activity recorded yet.</li>
                    @endforelse
                </ul>

                <footer class="prof-card-foot">
                    <span class="prof-foot-icon" aria-hidden="true">
                        @include('core::dashboard.partials.icon', ['name' => 'info'])
                    </span>
                    <span>
                        If you see a sign-in you do not recognise, change your password
                        and tell CGS.
                    </span>
                </footer>
            </section>

        </div>
    </div>
</div>
@endsection
