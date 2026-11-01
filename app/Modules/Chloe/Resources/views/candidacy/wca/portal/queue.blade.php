@extends('core::layouts.app')

@section('title', 'Study Candidacy Approval Portal')

@include('chloe::candidacy.wca._assets')

@section('content')
    <div class="wca-page" data-doc-title="Study Candidacy Approval Portal">
        <x-core::page-header title="Study Candidacy Approval Portal" subtitle="Study Candidacy Appeals you are named on.">
            <a href="{{ route('dashboard') }}" class="wca-home">Home</a>
        </x-core::page-header>

        @include('chloe::candidacy.wca._flash')

        <form method="GET" action="{{ route('candidacy.portal.queue') }}" class="wca-card wca-filters" role="search">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="wca-filter-grid">
                <div class="wca-field"><label for="wca-q">Search</label><input id="wca-q" class="wca-input" type="search" name="q" value="{{ $filters['q'] }}" placeholder="Appeal no., student name or ID"></div>
                <div class="wca-field"><label for="wca-f-dept">Department</label>
                    <select id="wca-f-dept" class="wca-select" name="department"><option value="">All</option>@foreach ($departments->groupBy('faculty') as $faculty => $group)<optgroup label="{{ $faculty }}">@foreach ($group as $d)<option value="{{ $d->id }}" @selected($filters['department'] === $d->id)>{{ $d->name }}</option>@endforeach</optgroup>@endforeach</select></div>
                <div class="wca-field"><label for="wca-f-months">Months requested</label>
                    <select id="wca-f-months" class="wca-select" name="months"><option value="">All</option>@foreach (range(1, 12) as $m)<option value="{{ $m }}" @selected($filters['months'] === $m)>{{ $m }}</option>@endforeach</select></div>
                <div class="wca-field"><label for="wca-f-wait">Waiting at least</label>
                    <select id="wca-f-wait" class="wca-select" name="waiting"><option value="">Any time</option>@foreach ([1, 3, 5, 10, 14] as $d)<option value="{{ $d }}" @selected($filters['waiting'] === $d)>{{ $d }} day(s)</option>@endforeach</select></div>
                <div class="wca-field"><label for="wca-f-round">Round</label>
                    <select id="wca-f-round" class="wca-select" name="round"><option value="">All</option>@foreach (range(1, 5) as $r)<option value="{{ $r }}" @selected($filters['round'] === $r)>{{ $r }}</option>@endforeach</select></div>
            </div>
            <div class="wca-actions"><button type="submit" class="wca-btn is-primary">Apply filters</button><a class="wca-btn" href="{{ route('candidacy.portal.queue', ['tab' => $tab === 'pending' ? null : $tab]) }}">Clear</a><a class="wca-btn" href="{{ route('candidacy.signature.show', ['return' => $returnTo]) }}">My Signature</a></div>
        </form>

        @include('chloe::candidacy.wca._live', ['liveVersion' => $liveVersion, 'liveRegionUrl' => route('candidacy.portal.region', $query), 'liveVersionUrl' => route('candidacy.portal.live.version')])

        <div id="wca-live-region">
            @include('chloe::candidacy.wca.portal._queue_region')
        </div>
    </div>
    @include('chloe::workstation._assistant-widget', ['wsaStatic' => 'The Study Candidacy assistant is coming soon.'])
@endsection
