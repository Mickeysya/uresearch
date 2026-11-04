@extends('core::layouts.app')

@section('title', 'Study Candidacy Approval Portal')

@include('chloe::candidacy.wca._assets')

@section('content')
    <div class="card-container-inline wca-page" data-doc-title="Study Candidacy Approval Portal">
        <x-core::page-header title="Study Candidacy Approval Portal">
            <a href="{{ route('candidacy.signature.show', ['return' => $returnTo]) }}"><button type="button" class="btn-secondary">My Signature</button></a>
            @if (\App\Modules\Chloe\Support\CandidacyHeaderLinks::home() !== route('candidacy.portal.queue'))
                <a href="{{ \App\Modules\Chloe\Support\CandidacyHeaderLinks::home() }}"><button type="button">Home</button></a>
            @endif
        </x-core::page-header>

        @include('chloe::candidacy.wca._flash')

        @php $mode = collect(['q', 'department', 'months', 'waiting', 'round'])->contains(fn ($k) => ($filters[$k] ?? '') !== '') ? 'results' : 'browse'; @endphp
        <div class="card card-wide cgs-top-card">
            <form method="GET" action="{{ route('candidacy.portal.queue') }}" role="search">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <div class="cgs-search-row">
                    <div class="cgs-search-wrap"><input type="search" name="q" id="wca-q" value="{{ $filters['q'] }}" placeholder="Search" aria-label="Search by appeal number, student name or student ID" autocomplete="off"></div>
                    <button type="submit">Search</button>
                </div>
                <div class="cgs-filters-row">
                    <div class="field"><label for="wca-f-dept">Department</label>
                        <select id="wca-f-dept" name="department"><option value="">All Departments</option>@foreach ($departments->groupBy('faculty') as $faculty => $group)<optgroup label="{{ $faculty }}">@foreach ($group as $d)<option value="{{ $d->id }}" @selected($filters['department'] === $d->id)>{{ $d->name }}</option>@endforeach</optgroup>@endforeach</select></div>
                    <div class="field"><label for="wca-f-months">Months Requested</label>
                        <select id="wca-f-months" name="months"><option value="">Any Months</option>@foreach (range(1, 12) as $m)<option value="{{ $m }}" @selected($filters['months'] === $m)>{{ $m }}</option>@endforeach</select></div>
                    <div class="field"><label for="wca-f-wait">Waiting At Least</label>
                        <select id="wca-f-wait" name="waiting"><option value="">Any Time</option>@foreach ([1, 3, 5, 10, 14] as $d)<option value="{{ $d }}" @selected($filters['waiting'] === $d)>{{ $d }} day(s)</option>@endforeach</select></div>
                    <div class="field"><label for="wca-f-round">Round</label>
                        <select id="wca-f-round" name="round"><option value="">Any Round</option>@foreach (range(1, 5) as $r)<option value="{{ $r }}" @selected($filters['round'] === $r)>{{ $r }}</option>@endforeach</select></div>

                    <button type="submit">Apply Filters</button>
                    @if ($mode === 'results')
                        <a href="{{ route('candidacy.portal.queue', ['tab' => $tab === 'pending' ? null : $tab]) }}" class="clear-link">Clear</a>
                    @endif
                </div>
            </form>
        </div>

        @include('chloe::candidacy.wca._live', ['liveVersion' => $liveVersion, 'liveRegionUrl' => route('candidacy.portal.region', $query), 'liveVersionUrl' => route('candidacy.portal.live.version')])

        <div id="wca-live-region">
            @include('chloe::candidacy.wca.portal._queue_region')
        </div>
    </div>
    @include('chloe::workstation._assistant-widget', ['wsaDomain' => 'candidacy'])
@endsection
