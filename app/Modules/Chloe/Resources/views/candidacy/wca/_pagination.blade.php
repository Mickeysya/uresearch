{{--
    The ONE pagination bar of every candidacy list. Same markup and classes as the Workstation CGS seat list ("« Prev", numbers, "Next »").
    Needs: $page (current, already clamped), $pages, $total, $perPage, $noun (plural, e.g. "appeals"), $pageUrl (closure: page number -> URL that keeps all state).
    0 results: only "No appeals found". One page: only the "Showing ..." line. Prev / Next are greyed non-links when they cannot be used.
--}}
@php
    use App\Modules\Chloe\Support\CandidacyPagination as Pg;
    $range = Pg::range($total, $page, $perPage);
    $singular = \Illuminate\Support\Str::singular($noun);
@endphp
@if ($total === 0)
    <p class="wca-muted wca-small wca-showing">No {{ $noun }} found</p>
@else
    @if ($pages > 1)
        @foreach (['is-full' => 7, 'is-compact' => 5] as $mode => $slots)
            <nav class="cgs-pagination {{ $mode }}" aria-label="Pages">
                @if ($page <= 1)
                    <span class="is-disabled" aria-disabled="true">&laquo; Prev</span>
                @else
                    <a href="{{ $pageUrl($page - 1) }}" rel="prev">&laquo; Prev</a>
                @endif

                @foreach (Pg::window($pages, $page, $slots) as $slot)
                    @if ($slot === Pg::ELLIPSIS)
                        <span class="is-gap" aria-hidden="true">&hellip;</span>
                    @elseif ($slot === $page)
                        <span class="is-current" aria-current="page">{{ $slot }}</span>
                    @else
                        <a href="{{ $pageUrl($slot) }}">{{ $slot }}</a>
                    @endif
                @endforeach

                @if ($page >= $pages)
                    <span class="is-disabled" aria-disabled="true">Next &raquo;</span>
                @else
                    <a href="{{ $pageUrl($page + 1) }}" rel="next">Next &raquo;</a>
                @endif
            </nav>
        @endforeach
    @endif
    <p class="wca-muted wca-small wca-showing">Showing {{ $range['from'] }} to {{ $range['to'] }} of {{ $total }} {{ $total === 1 ? $singular : $noun }}</p>
@endif
