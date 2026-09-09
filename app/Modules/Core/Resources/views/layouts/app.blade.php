<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'UResearch 2.0') — UResearch 2.0</title>
    <link rel="stylesheet" href="{{ asset('css/uresearch.css') }}">
    @stack('head')
</head>
<body>
    <div class="top-header">
        <img src="{{ asset('images/UResearch_logo.png') }}" alt="UResearch 2.0" class="uresearch-logo">
        <img src="{{ asset('images/UTP_logo.png') }}" alt="Universiti Teknologi PETRONAS" class="utp-logo">
    </div>

    <div class="dashboard-wrapper">
        @include('core::partials.sidebar')

        <div class="main-content">
            @include('core::partials.flash')
            @yield('content')
        </div>
    </div>

    @stack('scripts')
</body>
</html>
