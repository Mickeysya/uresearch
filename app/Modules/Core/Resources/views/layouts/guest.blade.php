<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>UResearch</title>
    <link rel="icon" type="image/png" href="{{ asset('images/uresearch-logo.png') }}">
    {{-- Four sheets, in cascade order — see the header comment in each.
         uresearch.css is Norhanis' original and is never edited in place;
         the other three are the additions, split out of it so no one file
         is thousands of lines. ORDER MATTERS: later sheets override earlier
         ones exactly as they did when this was a single file.

         ?v=<file mtime> so a change always reaches the browser instead of
         silently serving a stale cached copy. --}}
    @foreach (['uresearch', 'layout', 'sidebar', 'dashboard'] as $sheet)
        <link rel="stylesheet" href="{{ asset("css/{$sheet}.css") }}?v={{ @filemtime(public_path("css/{$sheet}.css")) ?: 1 }}">
    @endforeach
</head>
<body>
    <div class="top-header">
        <img src="{{ asset('images/UResearch_logo-text.png') }}" alt="UResearch 2.0" class="uresearch-logo">
        <img src="{{ asset('images/UTP_logo.png') }}" alt="Universiti Teknologi PETRONAS" class="utp-logo">
    </div>

    @yield('content')
</body>
</html>
