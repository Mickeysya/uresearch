@extends('core::layouts.app')

@section('title', 'Workstation: ' . $workstationLocation->name)

@section('content')
    @include('chloe::workstation._assistant-widget')

<link rel="stylesheet" href="{{ asset('css/chloe-workstation.css') }}">

<style>
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
</style>

<div class="card-container-inline">
    <x-core::page-header title="{{ $workstationLocation->name }}" :back="route('workstation.rooms', $workstationLocation->block)" :back-history="false">
        <a href="{{ route('workstation.select') }}"><button type="button">Home</button></a>
    </x-core::page-header>

    <div class="card card-wide">
        @php
            // Already ordered and filtered to Available in the controller
            // query; re-sorting here is just a safeguard, cheap insurance
            // against this list ever being built differently upstream.
            $availableSeats = $availableSeats->sortBy(fn ($seat) => (int) $seat->seat_code)->values();
        @endphp

        <div class="ws-room-summary">
            <figure>
                <img src="{{ $workstationLocation->seatIconUrl() }}" alt="Typical seat in {{ $workstationLocation->name }}" loading="lazy">
                <figcaption>Reference photo. The actual seat may differ.</figcaption>
            </figure>
            <div>
                <dl class="ws-facts">
                    <div><dt>Block</dt><dd>{{ $workstationLocation->block }}</dd></div>
                    <div><dt>Room</dt><dd>{{ $workstationLocation->room_code }}</dd></div>
                    <div><dt>Open seats</dt><dd>{{ $availableSeats->count() }}</dd></div>
                </dl>
                <p class="ws-room-desc">
                    <span class="ws-chip">{{ $workstationLocation->genderLabel() }}</span>
                    @if ($workstationLocation->description)
                        &nbsp;{{ $workstationLocation->description }}
                    @endif
                </p>
                @if ($workstationLocation->hasLayoutImage())
                    <button type="button" class="ws-link-button" id="open-room-layout">View room layout</button>
                @endif
            </div>
        </div>

        @if ($activeRequest && $activeRequest->workstation->workstation_location_id !== $workstationLocation->id)
            <div class="ws-notice" role="status">
                <span>
                    You already hold Seat {{ $activeRequest->workstation->seat_code }} elsewhere. Release it from
                    <a href="{{ route('workstation.select') }}">Home</a> before selecting a seat here.
                </span>
            </div>
        @endif

        <h2 class="ws-section-title">Select a seat</h2>
        <p class="ws-section-sub">Every seat listed here is available now.</p>

        @if ($activeRequest)
            <div class="empty-state">Release your current seat from Home before selecting another.</div>
        @elseif ($availableSeats->isEmpty())
            <div class="empty-state">No seats are available in this room right now.</div>
        @else
            <div class="ws-seat-grid">
                @foreach ($availableSeats as $seat)
                    <div class="ws-seat-tile">
                        <div>
                            <div class="ws-seat-num">Seat {{ $seat->seat_code }}</div>
                            <div class="ws-seat-state">Available</div>
                        </div>
                        <form method="POST" action="{{ route('workstation.register', $seat) }}"
                              class="js-seat-select-form" data-seat="{{ $seat->seat_code }}">
                            @csrf
                            <button type="submit" class="btn-secondary" aria-label="Select Seat {{ $seat->seat_code }}">Select</button>
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

        <p class="ws-footnote">
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
