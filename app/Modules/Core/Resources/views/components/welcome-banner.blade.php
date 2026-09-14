{{--
    The rounded navy banner at the top of the student dashboard.

    ┌──────────────────────────────────────────────────────────────┐
    │  Welcome Back, Aisyah 👋                        🎓📚🪴        │
    │  Here's an overview of your academic and ...                 │
    └──────────────────────────────────────────────────────────────┘

    EVERYTHING IS A PROP — change it at the call site, no CSS needed:

        <x-core::welcome-banner />                         all defaults
        <x-core::welcome-banner greeting="Good morning" />
        <x-core::welcome-banner name="Dr. Aisyah" emoji="🌟" />
        <x-core::welcome-banner subtitle="Three things need your attention." />
        <x-core::welcome-banner :art="false" />            text only, no artwork

    To change the DEFAULTS for every page at once, edit the @props block below.
    To change the COLOURS, edit the custom properties on .dash-welcome in
    public/css/uresearch.css — they are all declared together at the top of
    that rule, so retuning the banner is a one-line change.

    To swap the illustration for a real image instead of this SVG, replace the
    <svg> block at the bottom with:
        <img src="{{ asset('images/your-art.png') }}" alt="" class="dash-welcome-art">
--}}

@props([
    /* The line before the name. */
    'greeting' => 'Welcome Back',

    /* Defaults to the signed-in user's FIRST name, as in the design.
       Pass a string to override: name="Ahmad Danial" */
    'name' => null,

    /* Set to '' (empty string) to drop the emoji entirely. */
    'emoji' => '👋',

    /* The grey-blue line under the greeting. */
    'subtitle' => "Here's an overview of your academic and application activities.",

    /* Which artwork to draw on the right, by name. Resolves to the partial
       core::partials.banner-art-<name>:

           'graduate'  mortarboard, books, plant   (student dashboard)
           'desk'      monitor, clock, books, plant (CGS dashboard)
           false       no artwork at all

       Add a new one by dropping banner-art-<name>.blade.php into
       Resources/views/partials -- nothing here needs changing. */
    'art' => 'graduate',
])

@php
    // true still means the original artwork, so existing call sites keep working.
    $artName = $art === true ? 'graduate' : $art;

    // First name only — the design reads "Welcome Back, Aisyah", not the full
    // "Aisyah Binti Ahmad". Falls back to the whole string if there is no space.
    // Honorifics first: nearly every staff account is recorded with one
    // ("Puan Waheeda", "En Zulkifly", "Prof. Dr. Hafiz Osman"), and taking
    // the literal first word would greet them as "Puan" or "Prof".
    $titles = ['dr', 'dr.', 'prof', 'prof.', 'madya', 'ir', 'ir.', 'puan', 'pn', 'pn.',
               'encik', 'en', 'en.', 'cik', 'tuan', 'datin', 'dato', "dato'", 'datuk',
               'mr', 'mr.', 'mrs', 'mrs.', 'ms', 'ms.', 'miss'];

    $displayName = $name ?? \Illuminate\Support\Str::of((string) (auth()->user()?->name ?? ''))
        ->trim()
        ->explode(' ')
        ->reject(fn (string $part) => $part === '')
        ->skipWhile(fn (string $part) => in_array(mb_strtolower($part), $titles, true))
        ->first()
        ?? \Illuminate\Support\Str::of((string) (auth()->user()?->name ?? ''))->trim()->explode(' ')->first();

    // Built here rather than with an inline @if in the markup: Blade's
    // directive regex is anchored with \B, so an `@endif@if` written back to
    // back silently fails to compile the second directive.
    $heading = $greeting.($displayName ? ', '.$displayName : '');
@endphp

<div {{ $attributes->merge(['class' => 'dash-welcome']) }}>
    <div class="dash-welcome-text">
        {{-- Deliberately all on one line: a newline between the name and the
             emoji renders as a real space, which is what pushed the emoji away
             from the name. Spacing comes from margin-left alone.
             `}}@if` and `>@endif` are safe adjacencies; `@endif@if` is not. --}}
        <h2 class="dash-welcome-title">{{ $heading }}@if ($emoji !== '')<span class="dash-welcome-emoji" aria-hidden="true">{{ $emoji }}</span>@endif</h2>

        @if ($subtitle !== '')
            <p class="dash-welcome-sub">{{ $subtitle }}</p>
        @endif
    </div>

    @if ($art)
        {{-- Decorative only, so it is hidden from screen readers. Drawn as
             inline SVG rather than shipped as a PNG: it stays crisp at any
             size, costs no extra request, and its colours can be retuned
             here without opening an image editor. --}}
        @include('core::partials.banner-art-'.$artName)
    @endif
</div>
