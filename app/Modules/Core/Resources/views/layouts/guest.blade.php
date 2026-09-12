<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>UResearch</title>
    <link rel="icon" type="image/png" href="{{ asset('images/uresearch-logo.png') }}">
    {{-- ?v=<file mtime> so a stylesheet change always reaches the browser.
         Without it the URL never changes, the browser reuses its cached copy,
         and edits appear to have done nothing until a manual hard refresh. --}}
    <link rel="stylesheet" href="{{ asset('css/uresearch.css') }}?v={{ @filemtime(public_path('css/uresearch.css')) ?: 1 }}">
</head>
<body>
    <div class="top-header">
        <img src="{{ asset('images/UResearch_logo-text.png') }}" alt="UResearch 2.0" class="uresearch-logo">
        <img src="{{ asset('images/UTP_logo.png') }}" alt="Universiti Teknologi PETRONAS" class="utp-logo">
    </div>

    @yield('content')
</body>
</html>
