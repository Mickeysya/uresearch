@extends('core::layouts.app')

@section('title', 'Workstation: Block ' . $block)

@section('content')
    @include('chloe::workstation._assistant-widget')

{{-- Scoped to this page -- nothing here touches a Core/shared stylesheet.
     This is the same view for every block (the block code is just a route
     parameter), so these rules apply identically whichever block a student
     is browsing. A fixed `repeat(4, 1fr)` grid, not auto-fit/flex-grow: a
     lone card in a short last row must stay the same size as the rest and
     sit at the left, not stretch to fill the row -- which is exactly what
     auto-fit and flex-grow both do, so neither works here. --}}
<style>
    .ws-room-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        justify-items: stretch;
        gap: 24px;
        width: 100%;
        margin-top: 12px;
        max-height: 1180px;
        overflow-y: auto;
    }

    @media (max-width: 1099px) {
        .ws-room-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }

    @media (max-width: 799px) {
        .ws-room-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (max-width: 519px) {
        .ws-room-grid { grid-template-columns: repeat(1, minmax(0, 1fr)); }
    }

    .ws-room-card {
        width: 100%;
        max-width: none;
        margin: 0;
        padding: 0;
        overflow: hidden;
        text-decoration: none;
        color: inherit;
        display: flex;
        flex-direction: column;
    }

    .ws-room-image {
        width: 100%;
        aspect-ratio: 4 / 3;
        object-fit: cover;
        display: block;
        background: var(--surface-sunken, #eef1f6);
    }

    .ws-room-body {
        height: 120px;
        padding: 18px;
        overflow: hidden;
    }
</style>

<div class="card-container-inline">
    {{-- Back's target is identical to Home's here (this block's only
         parent is the landing page), so there is no history to prefer
         over it. --}}
    <x-core::page-header title="Block {{ $block }}" :back="route('workstation.select')" :back-history="false">
        <a href="{{ route('workstation.select') }}"><button type="button">Home</button></a>
    </x-core::page-header>

    {{-- No width override here on purpose: layout.css already makes
         .card-container-inline > .card span the full .page-shell width, the
         same shell the page-header above sits in, so the panel's right edge
         lines up with the Home button's right edge for free. A max-width
         here previously fought that and left the panel short. --}}
    <div class="card card-wide">
        @if ($activeRequest)
            <div class="empty-state">
                You already hold Seat {{ $activeRequest->workstation->seat_code }} in {{ $activeRequest->workstation->location->name }}.
                Release it from the <a href="{{ route('workstation.select') }}">Home</a> screen before selecting another.
            </div>
        @endif

        <div class="ws-room-grid">
            @foreach ($rooms as $room)
                <a href="{{ route('workstation.seats', $room) }}" class="card ws-room-card">
                    <img src="{{ $room->photoUrl() }}" alt="{{ $room->name }}" loading="lazy" class="ws-room-image">
                    <div class="ws-room-body">
                        <b>{{ $room->name }}</b>
                        <div style="margin-top:6px;">
                            <span class="status-badge {{ $room->gender === 'female' ? 'rejected' : 'pending' }}">{{ $room->genderLabel() }}</span>
                        </div>
                        <p style="color: var(--text-grey); font-size: 13px; margin-top:6px;">
                            {{ $room->available_count }} of {{ $room->workstations_count }} seats available
                        </p>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</div>
@endsection
