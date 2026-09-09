<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Log In') — UResearch 2.0</title>
    <link rel="stylesheet" href="{{ asset('css/uresearch.css') }}">
</head>
<body>
    <div class="top-header">
        <img src="{{ asset('images/UResearch_logo.png') }}" alt="UResearch 2.0" class="uresearch-logo">
        <img src="{{ asset('images/UTP_logo.png') }}" alt="Universiti Teknologi PETRONAS" class="utp-logo">
    </div>

    @yield('content')
</body>
</html>
