@props(['title', 'subtitle' => null, 'back' => null, 'backHistory' => true])

{{--
    The top of every screen: title, one line of context, and the page's
    actions on the right.

        <x-core::page-header title="Examiner List"
                             subtitle="The examiners a Chair can put on a panel.">
            <a href="..." class="btn">Add an examiner</a>
        </x-core::page-header>

    When the context line needs Blade of its own -- a count, a condition, a
    link -- pass it as a slot instead of an attribute:

        <x-core::page-header title="Documents">
            <x-slot:subtitle>
                {{ $documents->count() }} {{ Str::plural('file', $documents->count()) }}
            </x-slot:subtitle>

            <form ...>...</form>
        </x-core::page-header>

    The default slot is the actions and is optional. Below 720px the whole
    thing stacks and the actions go full width, so there is no second layout
    to keep in step.

    `back` is optional and additive -- every existing caller leaves it out
    and renders exactly as before. Pass a URL to put a plain-text "← Back"
    link above the title, left-aligned with it and top-aligned with the
    actions slot (not a slot itself: it has to sit INSIDE page-header-text,
    above the <h2>, which no slot here reaches). `backHistory` (default
    true) makes the caller responsible for wiring `[data-browser-back]` to
    real browser-history navigation with addEventListener (the CSP has no
    'unsafe-inline', so this component cannot attach that handler itself
    without shipping a script on every page that uses page-header at all,
    `back` or not); pass `:back-history="false"` when `back` is always the
    right target and there is no history-preserving case to prefer.
--}}
@php
    // A DETAIL page with no `back` of its own still gets one. Approvers open
    // a travel request, a staff application or an examiner record from a
    // queue, a dashboard or an email, and those pages left them with nothing
    // to click but the sidebar. Detail = a route named *.show / *.review /
    // *.read / *.memo. The target is the page they came from when that was
    // this site, else the dashboard; data-browser-back (handled once, in
    // core::partials.back-link) prefers real history so a filtered queue
    // comes back as it was left.
    if (! $back && auth()->check() && preg_match('/\.(show|review|read|memo)$/', (string) request()->route()?->getName())) {
        $previous = url()->previous();
        $sameSite = str_starts_with($previous, url('/')) && $previous !== url()->current();
        $back = $sameSite ? $previous : route('dashboard');
        $backHistory = true;
    }
@endphp

<header {{ $attributes->merge(['class' => 'page-header']) }}>
    <div class="page-header-text">
        @if ($back)
            <a href="{{ $back }}" class="page-header-back" @if ($backHistory) data-browser-back @endif>&larr; Back</a>
        @endif

        <h2>{{ $title }}</h2>

        @if ($subtitle)
            <p>{{ $subtitle }}</p>
        @endif
    </div>

    @if (! $slot->isEmpty())
        <div class="page-header-actions">{{ $slot }}</div>
    @endif
</header>
