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

    /* The same dialog look as the other Workstation modals (cgs/seat-details): a native <dialog>, centred, dimmed backdrop. */
    .reason-modal { border: none; border-radius: 14px; padding: 0; max-width: 460px; width: 92vw; background: var(--surface); color: inherit; }
    .reason-modal::backdrop { background: rgba(20, 25, 40, 0.45); }
    .reason-modal .card { border: none; box-shadow: none; margin: 0; max-width: none; max-height: 82vh; overflow-y: auto; }
    .reason-modal-head { display: flex; justify-content: space-between; align-items: center; }
    .reason-modal-close { background: none; border: none; font-size: 22px; cursor: pointer; color: var(--text-grey); }
    .reason-modal .modal-note { font-size: 13px; color: var(--text-grey); background: var(--surface-sunken, #F8F9FB); padding: 10px 12px; border-radius: 8px; margin-top: 10px; }
    .reason-modal .modal-facts { margin: 12px 0 0; display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; font-size: 14px; }
    .reason-modal .modal-facts dt { color: var(--text-grey); }
    .reason-modal .modal-facts dd { margin: 0; font-weight: 600; }
    .reason-modal .modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px; }
    .layout-modal { max-width: 900px; }
    .layout-scroll { overflow: auto; max-height: 70vh; border-radius: 8px; background: var(--surface-sunken, #F8F9FB); }
    .layout-scroll img { display: block; width: 100%; height: auto; cursor: zoom-in; }
    .layout-scroll.is-zoomed img { width: 220%; max-width: none; cursor: zoom-out; }
    .ws-layout-link { background: none; border: none; padding: 0; color: var(--accent, #0b5cab); font-weight: 600; font-size: 13px; cursor: pointer; text-decoration: underline; }

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
            @if ($workstationLocation->hasLayoutImage())
                · <button type="button" class="ws-layout-link" id="open-room-layout">View room layout</button>
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
                              class="js-seat-select-form ws-seat-select-form" data-seat="{{ $seat->seat_code }}">
                            @csrf
                            <button type="submit" class="ws-seat-button">Select</button>
                        </form>
                    </div>
                @endforeach
            </div>

            <dialog id="select-seat-modal" class="reason-modal" aria-labelledby="select-seat-title">
                <div class="card">
                    <div class="reason-modal-head">
                        <h3 id="select-seat-title">Select this seat?</h3>
                        <button type="button" class="reason-modal-close" data-close-modal aria-label="Close">&times;</button>
                    </div>
                    <dl class="modal-facts">
                        <dt>Seat</dt><dd id="select-seat-number"></dd>
                        <dt>Block</dt><dd>{{ $workstationLocation->block }}</dd>
                        <dt>Room</dt><dd>{{ $workstationLocation->room_code }}</dd>
                    </dl>
                    <p class="modal-note">The seat is yours as soon as you confirm. By confirming you accept the <a href="{{ route('workstation.terms') }}" target="_blank" rel="noopener">Postgraduate Workstation Term of Use</a>.</p>
                    <div class="modal-actions">
                        <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
                        <button type="button" id="select-seat-confirm">Confirm</button>
                    </div>
                </div>
            </dialog>

            <script @cspNonce>
                (function () {
                    var modal = document.getElementById('select-seat-modal');
                    var pending = null;
                    var confirmButton = document.getElementById('select-seat-confirm');

                    document.querySelectorAll('.js-seat-select-form').forEach(function (form) {
                        form.addEventListener('submit', function (event) {
                            if (form.dataset.confirmed === '1') return;
                            event.preventDefault();
                            pending = form;
                            document.getElementById('select-seat-number').textContent = 'Seat ' + form.dataset.seat;
                            confirmButton.disabled = false;
                            modal.showModal();
                        });
                    });

                    confirmButton.addEventListener('click', function () {
                        if (! pending) return;
                        confirmButton.disabled = true; // one press only
                        pending.dataset.confirmed = '1';
                        pending.submit();
                    });

                    modal.querySelectorAll('[data-close-modal]').forEach(function (b) { b.addEventListener('click', function () { modal.close(); }); });
                    modal.addEventListener('click', function (event) { if (event.target === modal) modal.close(); }); // backdrop click cancels
                })();
            </script>
        @endif

        <p style="color: var(--text-grey); font-size: 12px; margin-top: 20px;">
            Note: The images shown are for reference only and the actual seat may differ. Please refer to the seat layout displayed in each room.
        </p>
    </div>
</div>

@if ($workstationLocation->hasLayoutImage())
    <dialog id="room-layout-modal" class="reason-modal layout-modal" aria-labelledby="room-layout-title">
        <div class="card">
            <div class="reason-modal-head">
                <h3 id="room-layout-title">Room layout &middot; {{ $workstationLocation->name }}</h3>
                <button type="button" class="reason-modal-close" data-close-layout aria-label="Close">&times;</button>
            </div>
            <div class="layout-scroll" id="room-layout-scroll">
                <img src="{{ route('workstation.room-layout', $workstationLocation) }}" alt="Layout of {{ $workstationLocation->name }}" loading="lazy">
            </div>
            <p class="modal-note">Tap or click the image to zoom; scroll to move around.</p>
        </div>
    </dialog>
    <script @cspNonce>
        (function () {
            var modal = document.getElementById('room-layout-modal');
            var scroll = document.getElementById('room-layout-scroll');
            document.getElementById('open-room-layout').addEventListener('click', function () { modal.showModal(); });
            modal.querySelector('[data-close-layout]').addEventListener('click', function () { modal.close(); });
            modal.addEventListener('click', function (event) { if (event.target === modal) modal.close(); });
            scroll.addEventListener('click', function () { scroll.classList.toggle('is-zoomed'); });
        })();
    </script>
@endif
@endsection
