<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>UResearch</title>
    <link rel="icon" type="image/png" href="{{ asset('images/uresearch-logo.png') }}">
    @include('core::partials.stylesheets')
    @include('core::partials.theme-init')
    <script @cspNonce>
        // Applied before the body paints, straight from localStorage, so a
        // collapsed sidebar never flashes open-then-closed on page load --
        // this is a full page reload every navigation, not an SPA.
        (function () {
            try {
                if (localStorage.getItem('sidebar:collapsed') === '1') {
                    document.documentElement.classList.add('sidebar-collapsed');
                }
            } catch (e) {}
        })();
    </script>
    @stack('head')
</head>
<body>
    <div class="dashboard-wrapper">
        @include('core::partials.sidebar')

        <div class="main-content">
            <div class="main-content-header">
                @include('core::partials.theme-toggle')
                <img src="{{ asset('images/UTP_logo.png') }}" alt="Universiti Teknologi PETRONAS" class="utp-logo">
            </div>

            {{-- THE PAGE SHELL. Every screen is capped and centred here, not
                 in each view: a queue page did not wrap itself in
                 .card-container-inline and so ran the full width of the
                 content area while every card page sat at --page-max, which
                 is two different pages side by side in the same app. Doing it
                 in the layout means a view cannot forget. --}}
            <div class="page-shell">
                @include('core::partials.flash')
                @yield('content')
            </div>
        </div>
    </div>

    <script @cspNonce>
        (function () {
            var toggle = document.getElementById('sidebar-toggle');
            if (! toggle) return;

            toggle.addEventListener('click', function () {
                var collapsed = document.documentElement.classList.toggle('sidebar-collapsed');
                toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                toggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');

                try {
                    localStorage.setItem('sidebar:collapsed', collapsed ? '1' : '0');
                } catch (e) {}
            });

            toggle.setAttribute('aria-expanded', document.documentElement.classList.contains('sidebar-collapsed') ? 'false' : 'true');
        })();
    </script>
    {{-- A calendar panel over every <input type="date">. Included once, at
         the layout level, so a module gets it without knowing it exists --
         see the partial for why it enhances the native input rather than
         replacing it. --}}

    @stack('scripts')
</body>
</html>
