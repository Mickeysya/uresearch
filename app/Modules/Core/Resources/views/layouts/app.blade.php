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
    <div class="dashboard-wrapper">
        @include('core::partials.sidebar')

        <div class="main-content">
            <div class="main-content-header">
                <img src="{{ asset('images/UTP_logo.png') }}" alt="Universiti Teknologi PETRONAS" class="utp-logo">
            </div>

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
