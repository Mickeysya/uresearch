@extends('core::layouts.app')

@section('title', 'Candidacy Insights')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/chloe-candidacy.css') }}">
    <link rel="stylesheet" href="{{ asset('css/chloe-candidacy-insights.css') }}?v=1">
@endpush

@section('content')
    <div class="wsi-page" id="wsi-page">
        <x-core::page-header title="Candidacy Insights" subtitle="Operational monitoring from the known candidacy expiry dates. A projection, not a prediction." :back="route('candidacy.cgs.operations')" :back-history="false">
            <a href="{{ route('dashboard') }}"><button type="button">Home</button></a>
        </x-core::page-header>

        <p class="wsi-meta wsi-live-row">
            <span class="wsi-live-dot" id="wsi-live-dot" aria-hidden="true"></span>
            <span id="wsi-live-text">Live &middot; updated {{ $payload['now'] }}</span>
            <button type="button" class="wsi-link" id="wsi-live-pause">Pause updates</button>
        </p>
        <p class="wsi-meta wsi-live-note" id="wsi-live-note" hidden></p>
        <div id="wsi-live-config" hidden data-version="{{ $version }}" data-version-url="{{ route('candidacy.cgs.insights.live.version') }}" data-data-url="{{ route('candidacy.cgs.insights.live.stats') }}" data-interval="15000"
             data-operations-url="{{ route('candidacy.cgs.operations') }}" data-dismissals-url="{{ route('candidacy.cgs.dismissals') }}" data-appeal-url="{{ url('/candidacy/appeals') }}"></div>
        <script type="application/json" id="wci-data">@json($payload)</script>

        <div class="wsi-filter-panel">
            <div class="wsi-filter-row">
                <div class="wsi-field"><label for="wci-f-dept">Department</label><select class="wsi-select" id="wci-f-dept" data-filter="dept"></select></div>
                <div class="wsi-field"><label for="wci-f-level">Programme level</label><select class="wsi-select" id="wci-f-level" data-filter="level"><option value="">All</option><option value="master">Master</option><option value="phd">PhD</option></select></div>
                <div class="wsi-field"><label for="wci-f-mode">Study mode</label><select class="wsi-select" id="wci-f-mode" data-filter="mode"><option value="">All</option><option value="full">Full-Time</option><option value="part">Part-Time</option></select></div>
                <div class="wsi-field"><label for="wci-f-category">Status category</label><select class="wsi-select" id="wci-f-category" data-filter="category"></select></div>
                <button type="button" class="wsi-link wsi-reset-link" id="wsi-reset-filters">Clear</button>
            </div>
        </div>

        <p class="wsi-strip-tip" id="wsi-strip-tip">Tip: click any bar to highlight it across all charts and the student list &middot; Ctrl+click to select several &middot; Faded = not highlighted</p>
        <div class="wsi-strip" id="wsi-strip" hidden>
            <div class="wsi-chip-strip" id="wsi-chip-strip"></div>
            <div class="wsi-strip-right"><button type="button" class="wsi-link" id="wsi-clear-all" hidden>Clear all</button><span class="wsi-summary" id="wsi-summary" aria-live="polite"></span></div>
        </div>

        <noscript><p class="wsi-empty">This page needs JavaScript to draw its charts.</p></noscript>
        <div class="wsi-grid">
            <div class="wsi-layer-head">
                <h2 class="wsi-layer-title">Which candidacies need attention?</h2>
                <p class="wsi-layer-desc">Active research students whose candidacy has not expired yet. Students past the expiry date are on the Dismissal List.</p>
            </div>
            <div class="wsi-span-12"><div class="wsi-kpi-row" id="wsi-kpi-row"><p class="wsi-empty">Loading&hellip;</p></div>
                <p class="wsi-kpi-key">Left border: red = urgent &middot; orange = upcoming &middot; gold = needs attention &middot; blue = information</p></div>

            @include('chloe::candidacy.cgs._insights_cards')
        </div>
    </div>
    <div class="wsi-tooltip" id="wsi-tooltip" role="tooltip" hidden></div>
    <script src="{{ asset('js/chloe-candidacy-insights.js') }}?v=1" defer></script>
    @include('chloe::workstation._assistant-widget', ['wsaDomain' => 'candidacy'])
@endsection
