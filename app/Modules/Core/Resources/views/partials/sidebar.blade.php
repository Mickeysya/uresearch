{{--
    This is a thin, role-agnostic shell. The parts that actually differ by
    role are two separate partials switched on $user->isStudent() --
    sidebar-student-nav (Track My Applications, Attendance, My Application)
    for students, sidebar-approver-nav (whatever queues ModuleRegistry says
    this role owns) for every one of the other twelve roles. Neither
    partial, nor this file, hardcodes a specific role or module: a new
    module's stage appears in the right role's queue, and a new submittable
    module appears under My Application, purely by registering it.

    Dashboard, Notification, Documents, Calendar, Help and Support, and the
    profile footer are the same for every role and live directly below.
--}}
@php
    $user = auth()->user();

    $myApplicationOpen = false;
    foreach (($submittable ?? []) as $module) {
        if (request()->routeIs($module->createRoute())) {
            $myApplicationOpen = true;
        }
    }

    $initials = $user
        ? collect(preg_split('/\s+/', trim($user->name)))
            ->filter()
            ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->take(2)
            ->implode('')
        : '';
@endphp
<div class="sidebar" id="app-sidebar">
    <div class="sidebar-header">
        <button type="button" id="sidebar-toggle" class="sidebar-brand" aria-label="Collapse sidebar" aria-expanded="true">
            <img src="{{ asset('images/uresearch-logo.png') }}" alt="" class="sidebar-logo-icon">
            <span class="logo sidebar-logo-text"><span>U</span>Research</span>
        </button>
    </div>

    <nav class="sidebar-nav">
        <a href="{{ route('dashboard') }}" class="nav-item @if(request()->routeIs('dashboard')) active @endif" title="Dashboard">
            <span class="nav-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/></svg>
            </span>
            <span class="nav-label">Dashboard</span>
        </a>

        @auth
            @if ($user->isStudent())
                @include('core::partials.sidebar-student-nav', ['submittable' => $submittable, 'myApplicationOpen' => $myApplicationOpen])

            @elseif ($user->isAdmin())
                {{-- The administrator's oversight and administration sections.
                     Document Repository sits with the shared items below. --}}
                @include('core::partials.sidebar-admin-nav')

            @elseif ($user->isCgs())
                {{-- CGS places module-declared links inside its own trees (the
                     attendance CSV upload belongs under Attendance Monitoring,
                     not loose under "Actions"), so it takes $extraLinks and
                     renders whatever it did not place itself. --}}
                @include('core::partials.sidebar-cgs-nav', ['queues' => $queues, 'extraLinks' => $extraLinks])

            @else
                @include('core::partials.sidebar-approver-nav', ['queues' => $queues])

                @if (! empty($extraLinks))
                    <div class="nav-section-label"><span class="nav-label">Actions</span></div>
                    @foreach ($extraLinks as $link)
                        <a href="{{ route($link['route'], $link['params'] ?? []) }}" class="nav-item nav-item-flat" title="{{ $link['label'] }}">
                            <span class="nav-label">{{ $link['label'] }}</span>
                        </a>
                    @endforeach
                @endif
            @endif
        @endauth

        <div class="nav-divider"></div>

        <a href="{{ route('notifications.index') }}" class="nav-item @if(request()->routeIs('notifications.*')) active @endif" title="Notification">
            <span class="nav-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            </span>
            <span class="nav-label">Notification</span>
            @if (($unreadCount ?? 0) > 0)
                <span class="nav-badge" aria-label="{{ $unreadCount }} unread">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
            @endif
        </a>

        {{-- Students and approvers get Documents here, between Notification
             and Calendar. CGS does not: its design places Documents between
             Students and Reports and Analytics, so sidebar-cgs-nav renders
             its own in that position. --}}
        {{-- The admin has no personal Documents item: its equivalent is the
             central Document Repository, which the design places after
             Calendar rather than here. --}}
        @if (! auth()->user()?->isCgs() && ! auth()->user()?->isAdmin())
            <a href="{{ route('documents.index') }}" class="nav-item @if(request()->routeIs('documents.index')) active @endif" title="Documents">
                <span class="nav-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
                </span>
                <span class="nav-label">Documents</span>
            </a>
        @endif

        <a href="{{ route('calendar.index') }}" class="nav-item @if(request()->routeIs('calendar.*')) active @endif" title="Calendar">
            <span class="nav-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><line x1="16" y1="3" x2="16" y2="7"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </span>
            <span class="nav-label">Calendar</span>
        </a>

        @auth
            @if ($user->isAdmin())
                <a href="{{ route('admin.documents.index') }}" class="nav-item @if(request()->routeIs('admin.documents.*')) active @endif" title="Document Repository">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><line x1="8" y1="13" x2="16" y2="13"/></svg>
                    </span>
                    <span class="nav-label">Document Repository</span>
                </a>
            @endif
        @endauth

        @auth
            @if ($user->isCgs() && ! $user->isAdmin())
                <a href="{{ route('settings.index') }}" class="nav-item @if(request()->routeIs('settings.*')) active @endif" title="Settings">
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    </span>
                    <span class="nav-label">Settings</span>
                </a>
            @endif
        @endauth

        <a href="{{ route('help.index') }}" class="nav-item @if(request()->routeIs('help.*')) active @endif" title="Help and Support">
            <span class="nav-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 2-3 4"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </span>
            <span class="nav-label">Help and Support</span>
        </a>
    </nav>

    @auth
        <div class="sidebar-footer">
            {{--
                The identity card. Three lines, and what the third one says
                depends on who is looking:

                  student   their email
                  CGS       their department
                  admin     "Admin Access", and the card shows the institution
                            rather than the person -- an administrator acts for
                            the Centre, not as themselves.

                Collapsed, only the avatar and the sign-out icon survive; the
                text has nowhere to go at 64px wide.
            --}}
            @php
                $isAdminCard = $user->isAdmin();

                $cardName = $isAdminCard ? 'Universiti Teknologi PETRONAS' : $user->name;
                $cardRole = $isAdminCard ? 'Graduate Centre of Studies' : $user->roleLabel();
                $cardMeta = match (true) {
                    $isAdminCard => 'Admin Access',
                    $user->isStudent() => $user->email,
                    default => $user->department ?: $user->email,
                };
            @endphp

            <div class="sidebar-card">
                <a href="{{ route('profile.show') }}" class="sidebar-profile" title="{{ $user->name }} — view profile">
                    @if ($isAdminCard)
                        <img src="{{ asset('images/UTP_logo.png') }}" alt="" class="avatar avatar-crest">
                    @else
                        <span class="avatar">{{ $initials }}</span>
                    @endif

                    <span class="profile-text">
                        <span class="profile-name">{{ $cardName }}</span>
                        <span class="profile-role">{{ $cardRole }}</span>
                        <span class="profile-meta {{ $isAdminCard ? 'is-admin' : '' }}">{{ $cardMeta }}</span>
                    </span>
                </a>

                {{-- The click is bound in the script below, not with an inline
                     onclick: the Content-Security-Policy admits inline script
                     only by nonce, and a nonce cannot allow-list an event
                     handler attribute. Signing out must be a POST, so the
                     anchor submits the hidden form. --}}
                <a href="{{ route('logout') }}" class="sidebar-signout" title="Sign out" data-logout>
                    <span class="nav-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    </span>
                    <span class="nav-label">Sign out</span>
                </a>
            </div>

            <form id="logout-form" method="POST" action="{{ route('logout') }}" style="display:none">
                @csrf
            </form>
        </div>
    @endauth
</div>

<script @cspNonce>
    // Plain <details>/<summary> can't be animated smoothly across browsers
    // (its content just snaps open/shut), so the Attendance / My Application
    // trees are a button + panel instead, with the open/closed state carried
    // by an "open" class rather than the details element's own toggle.
    (function () {
        document.querySelectorAll('#app-sidebar .nav-tree-trigger').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                var tree = trigger.closest('.nav-tree');
                var open = tree.classList.toggle('open');
                trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });

        // Sign out is a POST (it changes state and carries the CSRF token),
        // so the link submits the hidden form rather than following its href.
        var signOut = document.querySelector('[data-logout]');
        var form = document.getElementById('logout-form');

        if (signOut && form) {
            signOut.addEventListener('click', function (event) {
                event.preventDefault();
                form.submit();
            });
        }
    })();
</script>
