{{--
    Page links for any LengthAwarePaginator, in this app's own chrome.

    Not Laravel's built-in views: those ship Tailwind and Bootstrap markup,
    and this project has neither. Three pages of numbers around the current
    one, so a 5,000-page queue does not render 5,000 links.

    Expects: $paginator. Optional: $label (what is being paged over).
--}}
@if ($paginator->hasPages())
    @php
        $window = 2;
        $last = $paginator->lastPage();
        $current = $paginator->currentPage();
        $from = max(1, $current - $window);
        $to = min($last, $current + $window);
    @endphp

    <nav class="pager" aria-label="{{ $label ?? 'Pages' }}">
        @if ($paginator->onFirstPage())
            <span class="pager-link is-disabled" aria-disabled="true">Previous</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="pager-link" rel="prev">Previous</a>
        @endif

        @if ($from > 1)
            <a href="{{ $paginator->url(1) }}" class="pager-link">1</a>
            @if ($from > 2)<span class="pager-gap">&hellip;</span>@endif
        @endif

        @for ($page = $from; $page <= $to; $page++)
            @if ($page === $current)
                <span class="pager-link is-current" aria-current="page">{{ $page }}</span>
            @else
                <a href="{{ $paginator->url($page) }}" class="pager-link">{{ $page }}</a>
            @endif
        @endfor

        @if ($to < $last)
            @if ($to < $last - 1)<span class="pager-gap">&hellip;</span>@endif
            <a href="{{ $paginator->url($last) }}" class="pager-link">{{ $last }}</a>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="pager-link" rel="next">Next</a>
        @else
            <span class="pager-link is-disabled" aria-disabled="true">Next</span>
        @endif
    </nav>
@endif
