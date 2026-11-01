@extends('core::layouts.app')

@section('title', 'My Study Candidacy')

@include('chloe::candidacy.wca._assets')

@section('content')
    <div class="wca-page">
        <x-core::page-header title="My Study Candidacy" subtitle="Your candidacy, your extension allowance and your appeals.">
            <a href="{{ route('dashboard') }}" class="wca-home">Home</a>
        </x-core::page-header>

        @include('chloe::candidacy.wca._flash')
        @include('chloe::candidacy.wca._live', ['liveVersion' => $liveVersion, 'liveRegionUrl' => route('candidacy.status.region'), 'liveVersionUrl' => route('candidacy.live.version')])

        <div id="wca-live-region">
            @include('chloe::candidacy.wca._status_region')
        </div>
    </div>
    @include('chloe::workstation._assistant-widget', ['wsaStatic' => 'The Study Candidacy assistant is coming soon.'])
@endsection
