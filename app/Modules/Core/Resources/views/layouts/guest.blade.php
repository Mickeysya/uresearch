<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>UResearch</title>
    <link rel="icon" type="image/png" href="{{ asset('images/uresearch-logo.png') }}">
    @include('core::partials.stylesheets')
</head>
<body>
    <div class="top-header">
        <img src="{{ asset('images/UResearch_logo-text.png') }}" alt="UResearch 2.0" class="uresearch-logo">
        <img src="{{ asset('images/UTP_logo.png') }}" alt="Universiti Teknologi PETRONAS" class="utp-logo">
    </div>

    @yield('content')
</body>
</html>
