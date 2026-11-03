@extends('core::layouts.app')

@section('title', 'Track My Applications')

{{--
    Track My Applications -- every module's applications, for one student.

    Module-neutral by construction: nothing here names a module. The tabs
    are the categories modules declare about themselves
    (ModuleRegistry::categoryFor(), config/tracking.php); each card's
    stepper is that application's own chain; the footer and any extra
    button come from the module's optional DescribesApplicationProgress.
    Everything is assembled in Services\ApplicationTracker.

    The tab, the status filter and the page are in the query string, so a
    refresh or back/forward lands on the same view. Styles: tracking.css.
--}}

@section('content')
@php
    // A link to this page with the current tab and status, changing only
    // what $with says (null drops it, so 'all' never clutters the URL).
    $url = fn (array $with) => route('applications.index', array_filter(array_merge([
        'tab' => $tab === 'all' ? null : $tab,
        'status' => $status === 'all' ? null : $status,
    ], $with), fn ($v) => $v !== null && $v !== 'all'));
@endphp
<div class="trk">
    <x-core::page-header title="Track My Applications"
                         subtitle="Every application you have filed, grouped by type, and where each one has reached.">
        @if ($startable)
            <details class="trk-new">
                <summary class="btn">
                    <span aria-hidden="true">+</span> New application
                </summary>
                <ul class="trk-new-menu">
                    @foreach ($startable as $module)
                        <li><a href="{{ $module['url'] }}">{{ $module['label'] }}</a></li>
                    @endforeach
                </ul>
            </details>
        @endif
    </x-core::page-header>

    @if ($total === 0)
        <div class="empty-state trk-empty">
            <p><b>You haven't filed any applications yet.</b></p>
            <p>Start one with <b>New application</b> above, or from <b>My Application</b> in the sidebar.</p>
        </div>
    @else
        <nav class="trk-tabs" aria-label="Application types">
            <ul>
                @foreach ($tabs as $t)
                    <li>
                        <a href="{{ $url(['tab' => $t['key'] === 'all' ? null : $t['key'], 'status' => $status === 'all' ? null : $status]) }}"
                           class="trk-tab {{ $t['key'] === $tab ? 'is-active' : '' }}"
                           @if ($t['key'] === $tab) aria-current="page" @endif>
                            {{ $t['label'] }}
                            <span class="trk-count" aria-label="{{ $t['count'] }} {{ Str::plural('application', $t['count']) }}">{{ $t['count'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <nav class="trk-filters" aria-label="Filter by status">
            <ul>
                @foreach ($statuses as $s)
                    <li>
                        <a href="{{ $url(['status' => $s['key'] === 'all' ? null : $s['key']]) }}"
                           class="trk-chip trk-chip-{{ $s['key'] }} {{ $s['key'] === $status ? 'is-active' : '' }}"
                           @if ($s['key'] === $status) aria-current="page" @endif>
                            <span class="trk-chip-dot" aria-hidden="true"></span>
                            {{ $s['label'] }}
                            <span class="trk-chip-count">{{ $s['count'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <p class="trk-showing" aria-live="polite">
            @if ($cards->lastPage() > 1)
                Showing <b>{{ $cards->firstItem() }}–{{ $cards->lastItem() }}</b> of {{ $cards->total() }} {{ Str::plural('application', $cards->total()) }}
            @else
                Showing <b>{{ $cards->total() }}</b> of {{ $tabTotal }} {{ Str::plural('application', $tabTotal) }}
            @endif
        </p>

        @forelse ($cards as $card)
            <x-core::track-card :card="$card" />
        @empty
            <div class="empty-state trk-empty">
                <p><b>No applications match this filter.</b></p>
                <p><a href="{{ $url(['status' => null]) }}">Show all statuses</a></p>
            </div>
        @endforelse

        {{-- Hand-rolled, like the notifications feed: Laravel's default
             pagination views are Tailwind markup, and this app has none. --}}
        @if ($cards->hasPages())
            <nav class="notif-pager" aria-label="Pagination">
                @if ($cards->onFirstPage())
                    <span class="notif-page-link is-disabled">Newer</span>
                @else
                    <a href="{{ $cards->previousPageUrl() }}" class="notif-page-link" rel="prev">Newer</a>
                @endif

                <span class="notif-page-count">Page {{ $cards->currentPage() }} of {{ $cards->lastPage() }}</span>

                @if ($cards->hasMorePages())
                    <a href="{{ $cards->nextPageUrl() }}" class="notif-page-link" rel="next">Older</a>
                @else
                    <span class="notif-page-link is-disabled">Older</span>
                @endif
            </nav>
        @endif
    @endif
</div>
@endsection
