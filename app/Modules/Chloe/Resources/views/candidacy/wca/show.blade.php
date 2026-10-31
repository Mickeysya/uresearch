@extends('core::layouts.app')

@section('title', 'Appeal '.$appeal->displayNo())

@include('chloe::candidacy.wca._assets')

@section('content')
    <div class="wca-page">
        <x-core::page-header title="Appeal {{ $appeal->displayNo() }}" subtitle="{{ $identity?->name }} ({{ $identity?->studentId }}) · {{ $appeal->department?->name }}" :back="$portal ? route('candidacy.status') : (auth()->user()->isStudent() ? route('candidacy.status') : route('dashboard'))" :back-history="false">
            <a href="{{ route('dashboard') }}" class="wca-home">Home</a>
        </x-core::page-header>

        @include('chloe::candidacy.wca._flash')
        @include('chloe::candidacy.wca._live', ['liveVersion' => $liveVersion, 'liveRegionUrl' => route('candidacy.appeals.region', $appeal), 'liveVersionUrl' => route('candidacy.live.version', ['appeal' => $appeal->id])])

        <div id="wca-live-region">
            @include('chloe::candidacy.wca._detail_region')
        </div>
    </div>
    @include('chloe::candidacy.wca._assistant')
@endsection
