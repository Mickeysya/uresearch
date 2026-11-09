{{-- The pool's list tabs. $route (route name), $type (current tab), $typeCounts required. --}}
<nav class="exam-tabs" aria-label="Examiner lists">
    @foreach ([
        '' => 'All Examiners',
        \App\Modules\Hani\Models\Examiner::TYPE_INTERNAL => 'Internal',
        \App\Modules\Hani\Models\Examiner::TYPE_EXTERNAL => 'External',
    ] as $tabValue => $tabLabel)
        {{-- Switching list drops the filters on purpose: a department or a
             status picked on one list rarely means anything on the other. --}}
        <a href="{{ route($route, array_filter(['type' => $tabValue])) }}"
           class="exam-tab @if($type === $tabValue) is-current @endif"
           @if($type === $tabValue) aria-current="page" @endif>
            {{ $tabLabel }}<span class="exam-tab-count">{{ $typeCounts[$tabValue] }}</span>
        </a>
    @endforeach
</nav>
