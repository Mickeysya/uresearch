{{-- The pool's pager. $examiners (a paginator) required. --}}
@if ($examiners->hasPages())
    <div class="examiner-pagination">
        @if ($examiners->onFirstPage())
            <span class="is-disabled">&laquo; Prev</span>
        @else
            <a href="{{ $examiners->previousPageUrl() }}">&laquo; Prev</a>
        @endif

        @for ($i = 1; $i <= $examiners->lastPage(); $i++)
            @if ($i === $examiners->currentPage())
                <span class="is-current">{{ $i }}</span>
            @else
                <a href="{{ $examiners->url($i) }}">{{ $i }}</a>
            @endif
        @endfor

        @if ($examiners->hasMorePages())
            <a href="{{ $examiners->nextPageUrl() }}">Next &raquo;</a>
        @else
            <span class="is-disabled">Next &raquo;</span>
        @endif
    </div>
@endif
