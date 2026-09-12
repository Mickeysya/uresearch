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

    /* false hides the graduation-cap / books / plant artwork. */
    'art' => true,
])

@php
    // First name only — the design reads "Welcome Back, Aisyah", not the full
    // "Aisyah Binti Ahmad". Falls back to the whole string if there is no space.
    $displayName = $name ?? \Illuminate\Support\Str::of((string) (auth()->user()?->name ?? ''))
        ->trim()
        ->explode(' ')
        ->first();

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
        <svg class="dash-welcome-art" viewBox="0 0 240 140" role="presentation" aria-hidden="true" focusable="false">
            {{-- soft glow under the objects --}}
            <ellipse cx="120" cy="124" rx="104" ry="22" fill="#FFFFFF" opacity="0.05"/>

            {{-- sparkles --}}
            <g fill="#FFFFFF">
                <path d="M24 33 L26.4 38.6 L32 41 L26.4 43.4 L24 49 L21.6 43.4 L16 41 L21.6 38.6 Z" opacity="0.55"/>
                <path d="M66 17 L67.7 21.3 L72 23 L67.7 24.7 L66 29 L64.3 24.7 L60 23 L64.3 21.3 Z" opacity="0.38"/>
                <path d="M158 28 L160 32.8 L165 35 L160 37.2 L158 42 L156 37.2 L151 35 L156 32.8 Z" opacity="0.45"/>
                <path d="M228 52 L229.7 56.3 L234 58 L229.7 59.7 L228 64 L226.3 59.7 L222 58 L226.3 56.3 Z" opacity="0.32"/>
                <circle cx="46" cy="74" r="2" opacity="0.35"/>
                <circle cx="196" cy="20" r="1.8" opacity="0.4"/>
                <circle cx="16" cy="92" r="1.6" opacity="0.3"/>
            </g>

            {{-- pencil, upper left --}}
            <g transform="rotate(-38 44 48)">
                <rect x="28" y="44" width="30" height="7" rx="1.5" fill="#E8B04B"/>
                <rect x="28" y="44" width="30" height="2.6" rx="1.3" fill="#F3C874"/>
                <path d="M58 44 L66 47.5 L58 51 Z" fill="#F2F0E8"/>
                <path d="M63.6 46 L66 47.5 L63.6 49 Z" fill="#3A4A66"/>
                <rect x="24" y="44" width="4.5" height="7" rx="1.5" fill="#D9748A"/>
            </g>

            {{-- book stack --}}
            <g>
                {{-- bottom book, orange --}}
                <rect x="40" y="112" width="112" height="18" rx="3.5" fill="#E8945A"/>
                <rect x="40" y="112" width="112" height="5" rx="2.5" fill="#F2AC7B"/>
                <rect x="49" y="121" width="94" height="2.6" rx="1.3" fill="#FFFFFF" opacity="0.45"/>

                {{-- middle book, cream --}}
                <rect x="47" y="96" width="98" height="16" rx="3" fill="#F2F0E8"/>
                <rect x="47" y="96" width="98" height="4.5" rx="2.25" fill="#FFFFFF"/>
                <rect x="56" y="104" width="80" height="2.6" rx="1.3" fill="#C9CEDB" opacity="0.75"/>

                {{-- top book, blue --}}
                <rect x="53" y="81" width="86" height="15" rx="3" fill="#5B8FD4"/>
                <rect x="53" y="81" width="86" height="4" rx="2" fill="#7CA8E2"/>
                <rect x="61" y="88" width="70" height="2.6" rx="1.3" fill="#FFFFFF" opacity="0.4"/>
            </g>

            {{-- graduation cap, sitting on the stack --}}
            <g>
                {{-- crown (the part that goes on the head), drawn first so the
                     board below overlaps its top edge --}}
                <path d="M80 81 L80 66 Q96 72 112 66 L112 81 Q96 86 80 81 Z" fill="#1E2A44"/>
                {{-- mortarboard: 100 wide x 40 tall, a 2.5:1 rhombus rather
                     than the flat 3.6:1 sliver it was --}}
                <path d="M96 42 L146 62 L96 82 L46 62 Z" fill="#2B3A5C"/>
                <path d="M96 42 L146 62 L96 73 L46 62 Z" fill="#35466B"/>
                <circle cx="96" cy="62" r="3.5" fill="#E8B04B"/>
                {{-- tassel --}}
                <path d="M96 62 Q134 62 136 72 L136 87" stroke="#E8B04B" stroke-width="2.4" fill="none" stroke-linecap="round"/>
                <circle cx="136" cy="87" r="3" fill="#F3C874"/>
                <path d="M132 88 L140 88 L137.5 102 L134.5 102 Z" fill="#E8B04B"/>
            </g>

            {{-- potted plant --}}
            <g>
                {{-- leaves first, so the pot rim overlaps where they enter it --}}
                <path d="M200 100 Q188 76 194 48 Q210 72 205 100 Z" fill="#4FA86B"/>
                <path d="M203 100 Q220 78 238 70 Q230 94 209 104 Z" fill="#6FC489"/>
                <path d="M198 100 Q182 82 164 78 Q176 98 195 104 Z" fill="#3E8F58"/>
                <path d="M201 96 Q204 78 213 64 Q214 84 205 98 Z" fill="#5FB87C"/>
                {{-- pot --}}
                <path d="M176 106 L224 106 L219 134 L181 134 Z" fill="#E8EDF5"/>
                <path d="M176 106 L224 106 L223 112 L177 112 Z" fill="#D5DDEA"/>
                <rect x="172" y="98" width="56" height="10" rx="3" fill="#F4F7FB"/>
            </g>
        </svg>
    @endif
</div>
