@extends('core::layouts.app')

@section('title', 'Workstation: Block ' . $block)

@section('content')
    @include('chloe::workstation._assistant-widget')

{{-- Scoped to this page -- nothing here touches a Core/shared stylesheet.
     Same fixed 4-column grid as the student Room page (workstation/rooms.
     blade.php); the only real difference from that page is CGS sees every
     room regardless of gender, and each card's counts line covers all
     three seat states instead of just "available of total". --}}
<style>
    .ws-room-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        justify-items: stretch;
        gap: 24px;
        width: 100%;
        margin-top: 12px;
    }
    @media (max-width: 1099px) { .ws-room-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (max-width: 799px) { .ws-room-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 519px) { .ws-room-grid { grid-template-columns: repeat(1, minmax(0, 1fr)); } }

    .ws-room-card { width: 100%; max-width: none; margin: 0; padding: 0; overflow: hidden; text-decoration: none; color: inherit; display: flex; flex-direction: column; }
    .ws-room-image { width: 100%; aspect-ratio: 4 / 3; object-fit: cover; display: block; background: var(--surface-sunken, #eef1f6); }
    .ws-room-body { height: 130px; padding: 18px; overflow: hidden; }
</style>

<div class="card-container-inline">
    <x-core::page-header title="Block {{ $block }}" :back="route('workstation.cgs.operations')">
        <a href="{{ route('workstation.cgs.operations') }}"><button type="button">Home</button></a>
    </x-core::page-header>

    <div class="card card-wide">
        <div class="ws-room-grid">
            @foreach ($rooms as $room)
                <a href="{{ route('workstation.cgs.room', $room) }}" class="card ws-room-card">
                    <img src="{{ $room->photoUrl() }}" alt="{{ $room->name }}" loading="lazy" class="ws-room-image">
                    <div class="ws-room-body">
                        <b>{{ $room->name }}</b>
                        <div style="margin-top:6px;">
                            <span class="status-badge {{ $room->gender === 'female' ? 'rejected' : 'pending' }}">{{ $room->genderLabel() }}</span>
                        </div>
                        <p style="color: var(--text-grey); font-size: 12.5px; margin-top:6px;">
                            Available: {{ $room->available_count }} &middot; Occupied: {{ $room->occupied_count }} &middot; Maintenance: {{ $room->maintenance_count }}
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
