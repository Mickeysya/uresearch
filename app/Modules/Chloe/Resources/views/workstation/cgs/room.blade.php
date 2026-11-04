@extends('core::layouts.app')

@section('title', 'Workstation: ' . $workstationLocation->name)

@section('content')
    @include('chloe::workstation._assistant-widget')

<link rel="stylesheet" href="{{ asset('css/chloe-workstation.css') }}">

{{-- Scoped to this page -- nothing here touches a Core/shared stylesheet.
     The seat card itself is the shared _seat-card partial (see its own
     doc) -- this page's <style> only covers the list wrapper, same
     "page owns its own CSS" convention as every other Workstation view. --}}
<style>
    /* Seat tiles (.cgs-seat-list / .cgs-seat-card): public/css/chloe-workstation.css. */
</style>

@include('chloe::workstation._live', ['liveKind' => 'cgs'])

<div class="card-container-inline">
    <x-core::page-header title="{{ $workstationLocation->name }}" :back="route('workstation.cgs.block', $workstationLocation->block)">
        <a href="{{ route('workstation.cgs.operations') }}"><button type="button">Home</button></a>
    </x-core::page-header>

    <div class="card card-wide" data-live-region>
        @php $counts = $seats->countBy('status'); @endphp
        <div class="ws-room-summary">
            <figure>
                <img src="{{ $workstationLocation->seatIconUrl() }}" alt="Typical seat in {{ $workstationLocation->name }}" loading="lazy">
                <figcaption>Reference photo of a seat in this room.</figcaption>
            </figure>
            <div>
                <dl class="ws-facts">
                    <div><dt>Block</dt><dd>{{ $workstationLocation->block }}</dd></div>
                    <div><dt>Seats</dt><dd>{{ $seats->count() }}</dd></div>
                    <div data-tip="Free to assign"><dt>Available</dt><dd>{{ $counts['available'] ?? 0 }}</dd></div>
                    <div><dt>Occupied</dt><dd>{{ $counts['occupied'] ?? 0 }}</dd></div>
                    <div data-tip="Out of use until CGS reopens them"><dt>Maintenance</dt><dd>{{ $counts['disabled'] ?? 0 }}</dd></div>
                </dl>
                <p class="ws-room-desc">
                    <span class="ws-chip" data-tip="Only {{ strtolower($workstationLocation->genderLabel()) }} students can be given a seat here">{{ $workstationLocation->genderLabel() }}</span>
                    @if ($workstationLocation->description)
                        &nbsp;{{ $workstationLocation->description }}
                    @endif
                </p>
            </div>
        </div>

        <h2 class="ws-section-title">Seats</h2>
        <p class="ws-section-sub">Open a seat to assign, release, or manage its locker key.</p>

        @if ($seats->isEmpty())
            <div class="empty-state">This room has no seats in the catalogue yet.</div>
        @else
            <div class="cgs-seat-list">
                @foreach ($seats as $row)
                    @include('chloe::workstation.cgs._seat-card', ['row' => $row])
                @endforeach
            </div>
        @endif
    </div>
</div>

<script @cspNonce>
    document.querySelectorAll('[data-browser-back]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (window.history.length > 1 && document.referrer) {
                event.preventDefault();
                window.history.back();
            }
        });
    });
</script>
@endsection
