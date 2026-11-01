@extends('core::layouts.app')

@section('title', 'Dismissal List')

@include('chloe::candidacy.wca._assets')

@section('content')
    <div class="wca-page">
        <x-core::page-header title="Dismissal List" subtitle="Students whose study candidacy has expired. CGS exports the list; the Registry processes it; the result comes back through Dismissal Upload." :back="route('candidacy.cgs.operations')" :back-history="false">
            <a href="{{ route('dashboard') }}" class="wca-home">Home</a>
        </x-core::page-header>

        @include('chloe::candidacy.wca._flash')

        <form method="GET" action="{{ route('candidacy.cgs.dismissals') }}" class="wca-card wca-filters" role="search" id="wca-dismissal-filters">
            @if ($f['group'])<input type="hidden" name="group" value="{{ $f['group'] }}">@endif
            <div class="wca-filter-grid is-ops">
                <div class="wca-field"><label for="wca-q">Search</label><input id="wca-q" class="wca-input" type="search" name="q" value="{{ $f['q'] }}" placeholder="Student name or ID"></div>
                <div class="wca-field"><label for="wca-f-dept">Department</label>
                    <select id="wca-f-dept" class="wca-select" name="department"><option value="">All</option>@foreach ($departments->groupBy('faculty') as $faculty => $group)<optgroup label="{{ $faculty }}">@foreach ($group as $d)<option value="{{ $d->id }}" @selected($f['department'] === $d->id)>{{ $d->name }}</option>@endforeach</optgroup>@endforeach</select></div>
                <div class="wca-field"><label for="wca-f-level">Programme level</label>
                    <select id="wca-f-level" class="wca-select" name="level"><option value="">All</option><option value="master" @selected($f['level'] === 'master')>Master</option><option value="phd" @selected($f['level'] === 'phd')>PhD</option></select></div>
                <div class="wca-field"><label for="wca-f-outcome">Last appeal outcome</label>
                    <select id="wca-f-outcome" class="wca-select" name="outcome"><option value="">All</option>@foreach (\App\Modules\Chloe\Support\CandidacyDismissalList::OUTCOMES as $k => $label)<option value="{{ $k }}" @selected($f['outcome'] === $k)>{{ $label }}</option>@endforeach</select></div>
                <div class="wca-field"><label for="wca-f-exported">Exported</label>
                    <select id="wca-f-exported" class="wca-select" name="exported"><option value="">All</option><option value="yes" @selected($f['exported'] === 'yes')>Yes</option><option value="no" @selected($f['exported'] === 'no')>No</option></select></div>
            </div>
            <div class="wca-actions"><button type="submit" class="wca-btn is-primary">Apply filters</button><a class="wca-btn" href="{{ route('candidacy.cgs.dismissals') }}">Clear</a></div>
        </form>

        @include('chloe::candidacy.wca._live', ['liveVersion' => $liveVersion, 'liveRegionUrl' => route('candidacy.cgs.dismissals.region', $query), 'liveVersionUrl' => route('candidacy.cgs.dismissals.live.version')])

        <div id="wca-live-region">
            @include('chloe::candidacy.cgs._dismissals_region')
        </div>
    </div>
    @include('chloe::workstation._assistant-widget', ['wsaDomain' => 'candidacy'])
@endsection
