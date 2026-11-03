@extends('core::layouts.app')

@section('title', 'Workstation Catalogue')

@section('content')
<style>
    /* Same dialog look as the other Workstation modals (cgs/seat-details): a native <dialog>, centred, dimmed backdrop. */
    .reason-modal { border: none; border-radius: 14px; padding: 0; max-width: 460px; width: 92vw; background: var(--surface); color: inherit; }
    .reason-modal::backdrop { background: rgba(20, 25, 40, 0.45); }
    .reason-modal .card { border: none; box-shadow: none; margin: 0; max-width: none; max-height: 82vh; overflow-y: auto; }
    .reason-modal-head { display: flex; justify-content: space-between; align-items: center; }
    .reason-modal-close { background: none; border: none; font-size: 22px; cursor: pointer; color: var(--text-grey); }
    .reason-modal .modal-note { font-size: 13px; color: var(--text-grey); background: var(--surface-sunken, #F8F9FB); padding: 10px 12px; border-radius: 8px; margin-top: 10px; }
    .reason-modal .modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px; }
    .layout-modal { max-width: 900px; }
    .layout-scroll { overflow: auto; max-height: 70vh; border-radius: 8px; background: var(--surface-sunken, #F8F9FB); }
    .layout-scroll img { display: block; width: 100%; height: auto; }
    .cat-seat-form { display: inline-flex; gap: 6px; flex-wrap: wrap; align-items: center; }
    .cat-room-tools { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin: 10px 0 4px; }
    .cat-room-tools form { display: inline-flex; gap: 6px; align-items: center; flex-wrap: wrap; }
    .cat-table-wrap { overflow-x: auto; }
</style>

<div class="card-container-inline">
    <x-core::page-header title="Workstation Catalogue" subtitle="Manage the locations and seats behind Workstation Management." :back="route('workstation.cgs.operations')" :back-history="false">
        <a href="{{ route('workstation.cgs.operations') }}"><button type="button">Home</button></a>
    </x-core::page-header>

    <div class="card card-wide">
        <h3>Add Location</h3>
        <p style="color: var(--text-grey); font-size: 13px;">Enter the block, the room and how many seats it has. The seats are numbered automatically, continuing from Seat {{ $nextSeatNumber }}.</p>
        <div class="card-divider"></div>
        <form method="POST" action="{{ route('workstation.cgs.catalog.locations.store') }}">
            @csrf
            <label for="block">Block</label>
            <input type="text" name="block" id="block" required maxlength="10" placeholder="e.g. J2" value="{{ old('block') }}">
            @error('block') <p class="field-error">{{ $message }}</p> @enderror

            <label for="room_code">Room</label>
            <input type="text" name="room_code" id="room_code" required maxlength="30" placeholder="e.g. N2-03-01-02" value="{{ old('room_code') }}">
            @error('room_code') <p class="field-error">{{ $message }}</p> @enderror

            <label for="quantity">Number of seats</label>
            <input type="number" name="quantity" id="quantity" required min="1" step="1" inputmode="numeric" placeholder="e.g. 24" value="{{ old('quantity') }}">
            @error('quantity') <p class="field-error">{{ $message }}</p> @enderror

            <label for="gender">Gender</label>
            <select name="gender" id="gender" required>
                <option value="">Select&hellip;</option>
                <option value="male" @selected(old('gender') === 'male')>Male</option>
                <option value="female" @selected(old('gender') === 'female')>Female</option>
            </select>
            @error('gender') <p class="field-error">{{ $message }}</p> @enderror

            <label for="name">Room Label <span style="color: var(--text-grey)">(optional, defaults to the room)</span></label>
            <input type="text" name="name" id="name" maxlength="150" placeholder="e.g. PG LAB MALE 1.9" value="{{ old('name') }}">

            <label for="description">Description <span style="color: var(--text-grey)">(optional)</span></label>
            <input type="text" name="description" id="description" maxlength="255" value="{{ old('description') }}">

            <button type="submit">Add Location</button>
        </form>
    </div>
</div>

@foreach ($locations as $location)
    <div class="card-container-inline">
        <div class="card card-wide">
            <h3>{{ $location->name }} ({{ $location->workstations_count }} seats)</h3>
            <p style="color: var(--text-grey); font-size: 13px;">
                {{ $location->room_code }} · Block {{ $location->block }} ·
                <span class="status-badge {{ $location->gender === 'female' ? 'rejected' : 'pending' }}">{{ $location->genderLabel() }}</span>
            </p>

            <div class="cat-room-tools">
                <form method="POST" action="{{ route('workstation.cgs.catalog.seats.store') }}">
                    @csrf
                    <input type="hidden" name="workstation_location_id" value="{{ $location->id }}">
                    <label for="qty-{{ $location->id }}" style="margin:0;">Add seats</label>
                    <input type="number" name="quantity" id="qty-{{ $location->id }}" min="1" step="1" required inputmode="numeric" style="width:90px;" placeholder="n">
                    <button type="submit">Add</button>
                </form>
            </div>

            <div class="cat-room-tools">
                <span style="color: var(--text-grey); font-size: 13px;">Room layout image:</span>
                @if ($location->hasLayoutImage())
                    <button type="button" class="btn-secondary" data-preview-layout="{{ route('workstation.cgs.catalog.layout', $location) }}" data-room="{{ $location->name }}">View</button>
                @else
                    <span style="color: var(--text-grey); font-size: 13px;">none</span>
                @endif
                <form method="POST" action="{{ route('workstation.cgs.catalog.layout.store', $location) }}" enctype="multipart/form-data">
                    @csrf
                    <input type="hidden" name="_layout_room" value="{{ $location->id }}">
                    <input type="file" name="layout" accept="image/png,image/jpeg,image/webp" required style="max-width:230px;">
                    <button type="submit">{{ $location->hasLayoutImage() ? 'Replace' : 'Upload layout image' }}</button>
                </form>
                @if ($location->hasLayoutImage())
                    <form method="POST" action="{{ route('workstation.cgs.catalog.layout.destroy', $location) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-secondary">Remove</button>
                    </form>
                @endif
            </div>
            <p style="color: var(--text-grey); font-size: 12px; margin: 0;">PNG, JPG or WebP, up to 5 MB.</p>
            @if ($errors->has('layout') && old('_layout_room') == $location->id)
                <p class="field-error">{{ $errors->first('layout') }}</p>
            @endif

            <div class="card-divider"></div>

            @if ($location->workstations->isEmpty())
                <div class="empty-state">No seats yet.</div>
            @else
                <div class="cat-table-wrap">
                <table class="recent-activity-table">
                    <thead>
                        <tr><th>Seat</th><th>Status</th><th>Notes</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($location->workstations as $seat)
                            <tr>
                                <td>Seat {{ $seat->seat_code }}</td>
                                <td><span class="status-badge">{{ \App\Modules\Chloe\Models\Workstation::statuses()[$seat->status] ?? ucfirst($seat->status) }}</span></td>
                                <td>{{ $seat->notes ?? '—' }}</td>
                                <td>
                                    @if ($seat->status !== 'occupied')
                                        <form method="POST" action="{{ route('workstation.cgs.catalog.seats.update', $seat) }}" class="cat-seat-form">
                                            @csrf
                                            @method('PUT')
                                            <select name="status" aria-label="Status of Seat {{ $seat->seat_code }}">
                                                <option value="available" @selected($seat->status === 'available')>Available</option>
                                                <option value="reserved" @selected($seat->status === 'reserved')>Reserved</option>
                                                <option value="disabled" @selected($seat->status === 'disabled')>Under Maintenance</option>
                                            </select>
                                            <input type="text" name="notes" value="{{ $seat->notes }}" maxlength="255" placeholder="Notes" style="width:120px;" aria-label="Notes for Seat {{ $seat->seat_code }}">
                                            <button type="submit">Save</button>
                                        </form>
                                    @endif
                                    <button type="button" class="btn-secondary" data-delete-seat="{{ route('workstation.cgs.catalog.seats.destroy', $seat) }}" data-seat="{{ $seat->seat_code }}" data-room="{{ $location->name }}">Delete</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </div>
    </div>
@endforeach

<dialog id="delete-seat-modal" class="reason-modal" aria-labelledby="delete-seat-title">
    <form method="POST" id="delete-seat-form" class="card">
        @csrf
        @method('DELETE')
        <div class="reason-modal-head">
            <h3 id="delete-seat-title">Delete this seat?</h3>
            <button type="button" class="reason-modal-close" data-close-modal aria-label="Close">&times;</button>
        </div>
        <p id="delete-seat-text" style="margin-top:10px;"></p>
        <p class="modal-note">The seat disappears from every list and count. Its history is kept, and its number is never used again.</p>
        <div class="modal-actions">
            <button type="button" class="btn-secondary" data-close-modal>Cancel</button>
            <button type="submit">Delete seat</button>
        </div>
    </form>
</dialog>

<dialog id="layout-preview-modal" class="reason-modal layout-modal" aria-labelledby="layout-preview-title">
    <div class="card">
        <div class="reason-modal-head">
            <h3 id="layout-preview-title">Room layout</h3>
            <button type="button" class="reason-modal-close" data-close-modal aria-label="Close">&times;</button>
        </div>
        <div class="layout-scroll"><img id="layout-preview-img" alt="Room layout"></div>
    </div>
</dialog>

<script @cspNonce>
    (function () {
        var del = document.getElementById('delete-seat-modal');
        var prev = document.getElementById('layout-preview-modal');
        [del, prev].forEach(function (modal) {
            modal.querySelectorAll('[data-close-modal]').forEach(function (b) { b.addEventListener('click', function () { modal.close(); }); });
            modal.addEventListener('click', function (event) { if (event.target === modal) modal.close(); });
        });
        document.querySelectorAll('[data-delete-seat]').forEach(function (b) {
            b.addEventListener('click', function () {
                document.getElementById('delete-seat-form').action = b.dataset.deleteSeat;
                document.getElementById('delete-seat-text').textContent = 'Delete Seat ' + b.dataset.seat + ' in ' + b.dataset.room + '?';
                del.showModal();
            });
        });
        document.querySelectorAll('[data-preview-layout]').forEach(function (b) {
            b.addEventListener('click', function () {
                document.getElementById('layout-preview-img').src = b.dataset.previewLayout;
                document.getElementById('layout-preview-title').textContent = 'Room layout · ' + b.dataset.room;
                prev.showModal();
            });
        });
    })();
</script>
@endsection
