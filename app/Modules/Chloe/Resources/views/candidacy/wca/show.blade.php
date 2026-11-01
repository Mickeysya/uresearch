@extends('core::layouts.app')

@section('title', $appeal->displayNo())

@include('chloe::candidacy.wca._assets')

@section('content')
    <div class="wca-page">
        <x-core::page-header :title="$appeal->displayNo()" :subtitle="($identity?->name).' ('.($identity?->studentId).') · '.($appeal->department?->name)" :back="$back" :back-history="false">
            <a href="{{ route('dashboard') }}" class="wca-home">Home</a>
        </x-core::page-header>

        @include('chloe::candidacy.wca._flash')
        @include('chloe::candidacy.wca._live', ['liveVersion' => $liveVersion, 'liveRegionUrl' => route('candidacy.appeals.region', array_filter(['appeal' => $appeal->id, 'return' => $return])), 'liveVersionUrl' => route('candidacy.live.version', ['appeal' => $appeal->id])])

        <div id="wca-live-region">
            @include('chloe::candidacy.wca._detail_region')
        </div>
    </div>
    @include('chloe::workstation._assistant-widget', ['wsaDomain' => 'candidacy'])
@endsection
