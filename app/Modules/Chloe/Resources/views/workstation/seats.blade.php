@extends('core::layouts.app')

@section('title', 'Workstation: ' . $workstationLocation->name)

@section('content')
    @include('chloe::workstation._assistant-widget')

{{-- Scoped to this page -- nothing here touches a Core/shared stylesheet.
     Every seat card must be the exact same size regardless of the seat
     icon's own dimensions or how many digits the seat number has, which an
     inline style on each card can't guarantee (a slightly taller photo, a
     wrapped line of text, would each quietly change that one card's
     height); fixed-size rules in real CSS are what make every card actually
     match, with one narrow-screen override below 700px. --}}
<style>
    .ws-seat-list { display: flex; flex-direction: column; gap: 20px; }

    .ws-seat-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        height: 178px;
        border-radius: 20px;
        background: var(--surface-sunken, #F8F9FB);
        padding: 10px 20px;
    }

    .ws-seat-image {
        width: 204px;
        height: 153px;
        object-fit: cover;
        border-radius: 8px;
        flex-shrink: 0;
        background: #fff;
    }

    .ws-seat-text {
        flex: 1;
        padding: 0 24px;
        font-weight: 600;
        font-size: 17px;
    }

    .ws-seat-select-form {
        flex-shrink: 0;
    }

    .ws-seat-button {
        width: 132px;
        height: 50px;
        margin: 0;
        padding: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    @media (max-width: 700px) {
        .ws-seat-card { height: 130px; }
        .ws-seat-image { width: 140px; height: 105px; }
    }
</style>

<div class="card-container-inline">
    <x-core::page-header title="{{ $workstationLocation->name }}" :back="route('workstation.rooms', $workstationLocation->block)" :back-history="false">
        <a href="{{ route('workstation.select') }}"><button type="button">Home</button></a>
    </x-core::page-header>

    <div class="card card-wide">
        <p style="color: var(--text-grey); font-size: 13px;">
            {{ $workstationLocation->room_code }} · Block {{ $workstationLocation->block }} ·
            <span class="status-badge {{ $workstationLocation->gender === 'female' ? 'rejected' : 'pending' }}">{{ $workstationLocation->genderLabel() }}</span>
            @if ($workstationLocation->description)
                · {{ $workstationLocation->description }}
            @endif
        </p>

        <div class="card-divider"></div>

        @if ($activeRequest && $activeRequest->workstation->workstation_location_id !== $workstationLocation->id)
            <div class="empty-state">
                You already hold Seat {{ $activeRequest->workstation->seat_code }} elsewhere. Release it from
                <a href="{{ route('workstation.select') }}">Home</a> before selecting a seat here.
            </div>
        @endif

        <p style="color: var(--text-grey); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 8px;">Select a Seat &middot; {{ $workstationLocation->name }}</p>

        @php
            // Already ordered and filtered to Available in the controller
            // query; re-sorting here is just a safeguard, cheap insurance
            // against this list ever being built differently upstream.
            $availableSeats = $availableSeats->sortBy(fn ($seat) => (int) $seat->seat_code)->values();
        @endphp

        @if ($activeRequest)
            <div class="empty-state">Release your current seat from Home before selecting another.</div>
        @elseif ($availableSeats->isEmpty())
            <div class="empty-state">No seats are available in this room right now.</div>
        @else
            <div class="ws-seat-list">
                @foreach ($availableSeats as $seat)
                    <div class="ws-seat-card">
                        <img src="{{ $workstationLocation->seatIconUrl() }}" alt="" aria-hidden="true" class="ws-seat-image">
                        <div class="ws-seat-text">Seat {{ $seat->seat_code }}</div>
                        <form method="POST" action="{{ route('workstation.register', $seat) }}"
                              class="js-seat-select-form ws-seat-select-form" data-confirm="Do you want to select Seat {{ $seat->seat_code }} in Room {{ $workstationLocation->room_code }}?">
                            @csrf
                            <button type="submit" class="ws-seat-button">Select</button>
                        </form>
                    </div>
                @endforeach
            </div>

            <script @cspNonce>
                document.querySelectorAll('.js-seat-select-form').forEach(function (form) {
                    form.addEventListener('submit', function (event) {
                        if (! confirm(form.dataset.confirm)) {
                            event.preventDefault();
                        }
                    });
                });
            </script>
        @endif

        <p style="color: var(--text-grey); font-size: 12px; margin-top: 20px;">
            Note: The images shown are for reference only and the actual seat may differ. Please refer to the seat layout displayed in each room.
        </p>
    </div>
</div>
@endsection
