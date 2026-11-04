@extends('core::layouts.app')

@section('title', 'Workstation: Block ' . $block)

@section('content')
    @include('chloe::workstation._assistant-widget')

{{-- Same room cards as the student Rooms page (chloe-workstation.css); CGS
     sees every room regardless of gender, and each card's counts line covers
     all three seat states. The counts are data-live-count spans, which
     _live.blade.php refreshes in place. --}}
<link rel="stylesheet" href="{{ asset('css/chloe-workstation.css') }}">

@include('chloe::workstation._live', ['liveKind' => 'cgs', 'liveStats' => true])

<div class="card-container-inline">
    <x-core::page-header title="Block {{ $block }}" :back="route('workstation.cgs.operations')">
        <a href="{{ route('workstation.cgs.operations') }}"><button type="button">Home</button></a>
    </x-core::page-header>

    <div class="card card-wide">
        <h2 class="ws-section-title">Rooms in Block {{ $block }}</h2>
        <p class="ws-section-sub">Every room, both genders. Counts update live.</p>

        <div class="ws-card-grid">
            @foreach ($rooms as $room)
                @php
                    $total = max(1, (int) $room->available_count + (int) $room->occupied_count + (int) $room->maintenance_count);
                    $percent = (int) round($room->available_count / $total * 100);
                    $tone = $room->available_count == 0 ? 'is-empty' : ($percent < 20 ? 'is-low' : '');
                @endphp
                <a href="{{ route('workstation.cgs.room', $room) }}" class="ws-photo-card" data-live-room="{{ $room->id }}">
                    <img src="{{ $room->photoUrl() }}" alt="{{ $room->name }}" loading="lazy">
                    <div class="ws-photo-body">
                        <div class="ws-photo-head">
                            <span class="ws-photo-name">{{ $room->name }}</span>
                            <span class="ws-chip">{{ $room->genderLabel() }}</span>
                        </div>
                        <svg class="ws-meter {{ $tone }}" viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true" data-tip="{{ $percent }}% of seats free"><rect class="ws-meter-track" width="100" height="6"></rect><rect class="ws-meter-fill" width="{{ $percent }}" height="6"></rect></svg>
                        <p class="ws-count-line">
                            Available: <span data-live-count="available">{{ $room->available_count }}</span> &middot; Occupied: <span data-live-count="occupied">{{ $room->occupied_count }}</span> &middot; Maintenance: <span data-live-count="maintenance">{{ $room->maintenance_count }}</span>
                        </p>
                    </div>
                </a>
            @endforeach
        </div>
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
