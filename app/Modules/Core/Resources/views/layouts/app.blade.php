<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'UResearch 2.0') — UResearch 2.0</title>
    <link rel="stylesheet" href="{{ asset('css/uresearch.css') }}">
    <script>
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
    <div class="top-header">
        <div class="top-header-left">
            <button type="button" id="sidebar-toggle" class="sidebar-toggle" aria-label="Collapse sidebar" aria-expanded="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                    <line x1="10" y1="4" x2="10" y2="20"></line>
                </svg>
            </button>
            <img src="{{ asset('images/UResearch_logo.png') }}" alt="UResearch 2.0" class="uresearch-logo">
        </div>
        <img src="{{ asset('images/UTP_logo.png') }}" alt="Universiti Teknologi PETRONAS" class="utp-logo">
    </div>

    <div class="dashboard-wrapper">
        @include('core::partials.sidebar')

        <div class="main-content">
            @include('core::partials.flash')
            @yield('content')
        </div>
    </div>

    <script>
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
    @stack('scripts')
</body>
</html>
