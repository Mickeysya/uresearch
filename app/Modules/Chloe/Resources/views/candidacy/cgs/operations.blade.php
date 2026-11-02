@extends('core::layouts.app')

@section('title', 'Candidacy Management')

@include('chloe::candidacy.wca._assets')

@section('content')
    <div class="card-container-inline wca-page">
        @php
            $mode = collect(['q', 'department', 'status', 'step', 'months', 'waiting', 'round', 'group'])->contains(fn ($k) => ($f[$k] ?? '') !== '' && ($f[$k] ?? null) !== null) ? 'results' : 'browse';
            $cards = ['in_progress' => ['In Progress', 'blue'], 'returned' => ['Returned', 'gold'], 'approved' => ['Approved', 'green'], 'rejected' => ['Rejected', 'red']];
            $cardLink = fn (?string $group) => route('candidacy.cgs.operations', array_filter(array_diff_key($query, ['group' => 1, 'page' => 1]) + ['group' => $group]));
        @endphp
        <x-core::page-header title="Candidacy Management">
            <a href="{{ route('candidacy.cgs.insights') }}"><button type="button" class="btn-secondary">Insights</button></a>
            <a href="{{ route('candidacy.cgs.dismissals') }}"><button type="button">Dismissal List</button></a>
        </x-core::page-header>

        @include('chloe::candidacy.wca._flash')

        <div class="card card-wide cgs-top-card">
            <form method="GET" action="{{ route('candidacy.cgs.operations') }}" role="search">
                @if ($f['group'])<input type="hidden" name="group" value="{{ $f['group'] }}">@endif
                <div class="cgs-search-row">
                    <div class="cgs-search-wrap">
                        <input type="search" name="q" id="wca-q" value="{{ $f['q'] }}" placeholder="Search" aria-label="Search by appeal number, student name or student ID" autocomplete="off">
                    </div>
                    <button type="submit">Search</button>
                </div>

                <div class="cgs-filters-row">
                    <div class="field"><label for="wca-f-dept">Department</label>
                        <select id="wca-f-dept" name="department"><option value="">All Departments</option>@foreach ($departments->groupBy('faculty') as $faculty => $group)<optgroup label="{{ $faculty }}">@foreach ($group as $d)<option value="{{ $d->id }}" @selected($f['department'] === $d->id)>{{ $d->name }}</option>@endforeach</optgroup>@endforeach</select></div>
                    <div class="field"><label for="wca-f-status">Status</label>
                        <select id="wca-f-status" name="status"><option value="">Any Status</option>@foreach (['with_supervisor' => 'With Supervisor', 'with_chair' => 'With Department Chair', 'with_cgs' => 'With CGS', 'with_dean' => 'With Dean', 'returned' => 'Returned to student', 'approved' => 'Approved', 'rejected' => 'Rejected (final)', 'closed_expired' => 'Closed expired'] as $k => $label)<option value="{{ $k }}" @selected($f['status'] === $k)>{{ $label }}</option>@endforeach</select></div>
                    <div class="field"><label for="wca-f-step">Current Step</label>
                        <select id="wca-f-step" name="step"><option value="">Any Step</option>@foreach (\App\Modules\Chloe\Support\CandidacyAppealPresenter::STAGES as $k => $label)<option value="{{ $k }}" @selected($f['step'] === $k)>{{ $label }}</option>@endforeach</select></div>
                    <div class="field"><label for="wca-f-months">Months Requested</label>
                        <select id="wca-f-months" name="months"><option value="">Any Months</option>@foreach (range(1, 12) as $m)<option value="{{ $m }}" @selected($f['months'] === $m)>{{ $m }}</option>@endforeach</select></div>
                    <div class="field"><label for="wca-f-wait">Waiting At Least</label>
                        <select id="wca-f-wait" name="waiting"><option value="">Any Time</option>@foreach ([1, 3, 5, 10, 14] as $d)<option value="{{ $d }}" @selected($f['waiting'] === $d)>{{ $d }} day(s)</option>@endforeach</select></div>
                    <div class="field"><label for="wca-f-round">Round</label>
                        <select id="wca-f-round" name="round"><option value="">Any Round</option>@foreach (range(1, 5) as $r)<option value="{{ $r }}" @selected($f['round'] === $r)>{{ $r }}</option>@endforeach</select></div>

                    <button type="submit">Apply Filters</button>

                    @if ($mode === 'results')
                        <a href="{{ route('candidacy.cgs.operations') }}" class="clear-link">Clear</a>
                    @endif
                </div>
            </form>

            <div class="cgs-stats-row" role="group" aria-label="Appeals by status">
                @foreach ($cards as $key => [$label, $accent])
                    @php $active = $f['group'] === $key; @endphp
                    <a href="{{ $active ? $cardLink(null) : $cardLink($key) }}" class="{{ $active ? 'is-active' : '' }}" aria-pressed="{{ $active ? 'true' : 'false' }}">
                        <div class="stat-card accent-{{ $accent }}"><div class="stat-number" data-live-stat="{{ $key }}">{{ $counts[$key] }}</div><div class="stat-label">{{ $label }}</div></div>
                    </a>
                @endforeach
            </div>
        </div>

        @include('chloe::candidacy.wca._live', ['liveVersion' => $liveVersion, 'liveRegionUrl' => route('candidacy.cgs.operations.region', $query), 'liveVersionUrl' => route('candidacy.cgs.live.version'), 'liveStatsUrl' => route('candidacy.cgs.live.stats', $query)])

        <div id="wca-live-region">
            @include('chloe::candidacy.cgs._operations_region')
        </div>
    </div>
    @include('chloe::workstation._assistant-widget', ['wsaDomain' => 'candidacy'])
@endsection
