@extends('core::layouts.app')

@section('title', 'Dismissal List')

@include('chloe::candidacy.wca._assets')

@section('content')
    <div class="card-container-inline wca-page">
        @php
            $mode = collect(['q', 'department', 'level', 'outcome', 'exported', 'group'])->contains(fn ($k) => ($f[$k] ?? '') !== '') ? 'results' : 'browse';
        @endphp
        <x-core::page-header title="Dismissal List" :back="route('candidacy.cgs.operations')" :back-history="false">
            <a href="{{ route('candidacy.cgs.operations') }}"><button type="button">Home</button></a>
            <a href="{{ route('candidacy.cgs.dismissal-upload') }}"><button type="button">Dismissal Upload</button></a>
        </x-core::page-header>

        @include('chloe::candidacy.wca._flash')

        <div class="card card-wide cgs-top-card">
            <form method="GET" action="{{ route('candidacy.cgs.dismissals') }}" role="search" id="wca-dismissal-filters">
                @if ($f['group'])<input type="hidden" name="group" value="{{ $f['group'] }}">@endif
                <div class="cgs-search-row">
                    <div class="cgs-search-wrap"><input type="search" name="q" id="wca-q" value="{{ $f['q'] }}" placeholder="Search" aria-label="Search by student name or student ID" autocomplete="off"></div>
                    <button type="submit">Search</button>
                </div>
                <div class="cgs-filters-row">
                    <div class="field"><label for="wca-f-dept">Department</label>
                        <select id="wca-f-dept" name="department"><option value="">All Departments</option>@foreach ($departments->groupBy('faculty') as $faculty => $group)<optgroup label="{{ $faculty }}">@foreach ($group as $d)<option value="{{ $d->id }}" @selected($f['department'] === $d->id)>{{ $d->name }}</option>@endforeach</optgroup>@endforeach</select></div>
                    <div class="field"><label for="wca-f-level">Programme Level</label>
                        <select id="wca-f-level" name="level"><option value="">Any Level</option><option value="master" @selected($f['level'] === 'master')>Master</option><option value="phd" @selected($f['level'] === 'phd')>PhD</option></select></div>
                    <div class="field"><label for="wca-f-outcome">Last Appeal Outcome</label>
                        <select id="wca-f-outcome" name="outcome"><option value="">Any Outcome</option>@foreach (\App\Modules\Chloe\Support\CandidacyDismissalList::OUTCOMES as $k => $label)<option value="{{ $k }}" @selected($f['outcome'] === $k)>{{ $label }}</option>@endforeach</select></div>
                    <div class="field"><label for="wca-f-exported">Exported</label>
                        <select id="wca-f-exported" name="exported"><option value="">Any</option><option value="yes" @selected($f['exported'] === 'yes')>Yes</option><option value="no" @selected($f['exported'] === 'no')>No</option></select></div>

                    <button type="submit">Apply Filters</button>
                    @if ($mode === 'results')
                        <a href="{{ route('candidacy.cgs.dismissals') }}" class="clear-link">Clear</a>
                    @endif
                </div>
            </form>

            <div id="wca-live-cards">@include('chloe::candidacy.cgs._dismissals_cards')</div>
        </div>

        @include('chloe::candidacy.wca._live', ['liveVersion' => $liveVersion, 'liveRegionUrl' => route('candidacy.cgs.dismissals.region', $query), 'liveVersionUrl' => route('candidacy.cgs.dismissals.live.version')])

        <div id="wca-live-region">
            @include('chloe::candidacy.cgs._dismissals_table')
        </div>
    </div>
    @include('chloe::workstation._assistant-widget', ['wsaDomain' => 'candidacy'])
@endsection
