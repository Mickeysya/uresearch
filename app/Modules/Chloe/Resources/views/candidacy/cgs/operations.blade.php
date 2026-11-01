@extends('core::layouts.app')

@section('title', 'Candidacy Management')

@include('chloe::candidacy.wca._assets')

@section('content')
    <div class="wca-page">
        <x-core::page-header title="Candidacy Management" subtitle="Operations: every study candidacy appeal.">
            <a href="{{ route('dashboard') }}" class="wca-home">Home</a>
        </x-core::page-header>

        @include('chloe::candidacy.wca._flash')

        <form method="GET" action="{{ route('candidacy.cgs.operations') }}" class="wca-card wca-filters" role="search">
            @if ($f['group'])<input type="hidden" name="group" value="{{ $f['group'] }}">@endif
            <div class="wca-filter-grid is-ops">
                <div class="wca-field"><label for="wca-q">Search</label><input id="wca-q" class="wca-input" type="search" name="q" value="{{ $f['q'] }}" placeholder="Appeal no., student name or ID"></div>
                <div class="wca-field"><label for="wca-f-dept">Department</label>
                    <select id="wca-f-dept" class="wca-select" name="department"><option value="">All</option>@foreach ($departments->groupBy('faculty') as $faculty => $group)<optgroup label="{{ $faculty }}">@foreach ($group as $d)<option value="{{ $d->id }}" @selected($f['department'] === $d->id)>{{ $d->name }}</option>@endforeach</optgroup>@endforeach</select></div>
                <div class="wca-field"><label for="wca-f-status">Status</label>
                    <select id="wca-f-status" class="wca-select" name="status"><option value="">All</option>@foreach (['with_supervisor' => 'With Supervisor', 'with_chair' => 'With Department Chair', 'with_cgs' => 'With CGS', 'with_dean' => 'With Dean', 'returned' => 'Returned to student', 'approved' => 'Approved', 'rejected' => 'Rejected (final)', 'closed_expired' => 'Closed expired'] as $k => $label)<option value="{{ $k }}" @selected($f['status'] === $k)>{{ $label }}</option>@endforeach</select></div>
                <div class="wca-field"><label for="wca-f-step">Current step</label>
                    <select id="wca-f-step" class="wca-select" name="step"><option value="">All</option>@foreach (\App\Modules\Chloe\Support\CandidacyAppealPresenter::STAGES as $k => $label)<option value="{{ $k }}" @selected($f['step'] === $k)>{{ $label }}</option>@endforeach</select></div>
                <div class="wca-field"><label for="wca-f-months">Months requested</label>
                    <select id="wca-f-months" class="wca-select" name="months"><option value="">All</option>@foreach (range(1, 12) as $m)<option value="{{ $m }}" @selected($f['months'] === $m)>{{ $m }}</option>@endforeach</select></div>
                <div class="wca-field"><label for="wca-f-wait">Waiting at least</label>
                    <select id="wca-f-wait" class="wca-select" name="waiting"><option value="">Any time</option>@foreach ([1, 3, 5, 10, 14] as $d)<option value="{{ $d }}" @selected($f['waiting'] === $d)>{{ $d }} day(s)</option>@endforeach</select></div>
                <div class="wca-field"><label for="wca-f-round">Round</label>
                    <select id="wca-f-round" class="wca-select" name="round"><option value="">All</option>@foreach (range(1, 5) as $r)<option value="{{ $r }}" @selected($f['round'] === $r)>{{ $r }}</option>@endforeach</select></div>
            </div>
            <div class="wca-actions"><button type="submit" class="wca-btn is-primary">Apply filters</button><a class="wca-btn" href="{{ route('candidacy.cgs.operations') }}">Clear</a><a class="wca-btn" href="{{ route('candidacy.cgs.dismissals') }}">Review the dismissal list</a></div>
        </form>

        @include('chloe::candidacy.wca._live', ['liveVersion' => $liveVersion, 'liveRegionUrl' => route('candidacy.cgs.operations.region', $query), 'liveVersionUrl' => route('candidacy.cgs.live.version'), 'liveStatsUrl' => route('candidacy.cgs.live.stats', $query)])

        <div id="wca-live-region">
            @include('chloe::candidacy.cgs._operations_region')
        </div>
    </div>
    @include('chloe::workstation._assistant-widget', ['wsaStatic' => 'The Study Candidacy assistant is coming soon.'])
@endsection
