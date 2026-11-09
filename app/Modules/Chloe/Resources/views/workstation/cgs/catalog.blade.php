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

    /* Block / Room filter bar */
    .cat-filter { display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end; }
    .cat-filter .cat-filter-field { min-width: 0; }
    .cat-filter label { margin: 0 0 4px; }
    .cat-filter select { width: 230px; max-width: 100%; }
    .cat-filter button, .cat-filter .cat-clear { margin: 0; }
    .cat-result { margin: 12px 0 0; font-size: 13px; color: var(--text-grey); }
    [data-cat-room][hidden], .cat-clear[hidden] { display: none !important; }
    [data-cat-room] { scroll-margin-top: 84px; }

    /* "Add seats" and "Room layout image": one grid, one label column, one control height. The controls of both rows start at the same left edge,
       and the number box, the file chooser and both buttons share --cat-control-h, centred on the label. */
    .cat-grid { --cat-control-h: 42px; display: grid; grid-template-columns: 170px minmax(0, 1fr); gap: 10px 16px; align-items: center; margin: 12px 0 6px; }
    /* the label is as tall as one control and sits on the FIRST line of its row, so it stays centred on the controls even when View / Remove wrap below */
    .cat-grid > label, .cat-grid > .cat-label { margin: 0; align-self: start; display: flex; align-items: center; min-height: var(--cat-control-h); }
    .cat-controls, .cat-controls form { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 0; min-width: 0; }
    .cat-grid input[type="number"], .cat-grid button {
        box-sizing: border-box; height: var(--cat-control-h); min-height: var(--cat-control-h); margin: 0; padding-top: 0; padding-bottom: 0; line-height: 1.2;
    }
    .cat-grid button { display: inline-flex; align-items: center; justify-content: center; padding-left: 20px; padding-right: 20px; white-space: nowrap; }
    .cat-grid input[type="number"] { width: 120px; }
    /* The file control: ONE flex box (the dashed outline, height --cat-control-h) holding a real "Choose File" button and the file name, both centred
       on the box's middle line, with equal space above and below the button. The native <input type="file"> lies invisibly over the whole box, so a
       click anywhere in it, the keyboard and the browser's own validation all still work; the script only copies the chosen name into the text. */
    .cat-grid { --cat-file-pad: 5px; --cat-file-inner-h: calc(var(--cat-control-h) - 2 * var(--cat-file-pad) - 2px); }
    .cat-file { position: relative; display: flex; align-items: center; gap: 12px; box-sizing: border-box; flex: 0 1 300px; min-width: 200px; max-width: 340px;
        height: var(--cat-control-h); padding: 0 var(--cat-file-pad); border: 1px dashed var(--border-grey); border-radius: var(--radius-sm); background-color: var(--surface-sunken); }
    .cat-file:focus-within { border-color: var(--navy); box-shadow: var(--shadow-focus); }
    .cat-file-btn { display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box; flex: none; height: var(--cat-file-inner-h); margin: 0; padding: 0 14px;
        border: 1px solid var(--border-grey); border-radius: var(--radius-sm); background: var(--surface); color: var(--text-dark); font-size: var(--text-sm); font-weight: var(--weight-medium); line-height: 1; }
    .cat-file-name { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: var(--text-sm); line-height: 1.2; color: var(--text-grey); }
    .cat-file-input { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; padding: 0; opacity: 0; cursor: pointer; border: 0; }
    .cat-controls form { flex: 0 1 auto; }
    .cat-controls form.cat-upload { flex-wrap: nowrap; }
    .cat-help { grid-column: 2; margin: -2px 0 0; font-size: 12px; color: var(--text-grey); }
    .cat-grid .field-error { grid-column: 2; margin: 0; }
    @media (max-width: 600px) {
        .cat-grid { grid-template-columns: minmax(0, 1fr); gap: 6px; }
        .cat-grid > label, .cat-grid > .cat-label { min-height: 0; }
        .cat-controls form.cat-upload { flex-wrap: wrap; }
        .cat-file { flex: 1 1 100%; max-width: none; }
        .cat-grid > .cat-controls { margin-bottom: 8px; }
        .cat-help, .cat-grid .field-error { grid-column: 1; }
    }
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

<div class="card-container-inline">
    <div class="card card-wide">
        <form method="GET" action="{{ route('workstation.cgs.catalog') }}" class="cat-filter" id="cat-filter" aria-label="Filter the catalogue by block and room" data-rooms="{{ json_encode($roomsForScript) }}">
            <div class="cat-filter-field">
                <label for="cat-block">Block</label>
                <select name="block" id="cat-block">
                    <option value="">All blocks</option>
                    @foreach ($blocks as $b)
                        <option value="{{ $b }}" @selected($selection['block'] === $b)>Block {{ $b }}</option>
                    @endforeach
                </select>
            </div>
            <div class="cat-filter-field">
                <label for="cat-room">Room</label>
                <select name="room" id="cat-room">
                    <option value="">All rooms</option>
                    @foreach ($roomChoices as $r)
                        <option value="{{ $r->room_code }}" @selected($selection['room'] === $r->room_code)>{{ $r->room_code }}</option>
                    @endforeach
                </select>
            </div>
            {{-- With JavaScript the filter applies at once, so there is no Apply button; without it, this one submits the same GET form to the server. --}}
            <noscript><button type="submit">Apply</button></noscript>
            <a class="cat-clear" href="{{ route('workstation.cgs.catalog') }}"{!! $selection['block'] === '' && $selection['room'] === '' ? ' hidden' : '' !!}><button type="button" class="btn-secondary">Clear</button></a>
        </form>
        <p class="cat-result" id="cat-result" aria-live="polite">Showing {{ $summary['rooms'] }} {{ $summary['rooms'] === 1 ? 'room' : 'rooms' }} ({{ $summary['seats'] }} {{ $summary['seats'] === 1 ? 'seat' : 'seats' }}).</p>
    </div>
</div>

@foreach ($locations as $location)
    @php($shown = ($selection['block'] === '' || $selection['block'] === $location->block) && ($selection['room'] === '' || $selection['room'] === $location->room_code))
    <div class="card-container-inline" data-cat-room data-block="{{ $location->block }}" data-room="{{ $location->room_code }}" data-seats="{{ $location->workstations_count }}"{!! $shown ? '' : ' hidden' !!}>
        <div class="card card-wide">
            <h3>{{ $location->name }} ({{ $location->workstations_count }} seats)</h3>
            <p style="color: var(--text-grey); font-size: 13px;">
                {{ $location->room_code }} · Block {{ $location->block }} ·
                <span class="status-badge {{ $location->gender === 'female' ? 'rejected' : 'pending' }}">{{ $location->genderLabel() }}</span>
            </p>

            <div class="cat-grid">
                <label for="qty-{{ $location->id }}">Add seats</label>
                <form method="POST" action="{{ route('workstation.cgs.catalog.seats.store') }}" class="cat-controls">
                    @csrf
                    <input type="hidden" name="workstation_location_id" value="{{ $location->id }}">
                    <input type="hidden" name="filter_block" value="{{ $selection['block'] }}">
                    <input type="hidden" name="filter_room" value="{{ $selection['room'] }}">
                    <input type="number" name="quantity" id="qty-{{ $location->id }}" min="1" step="1" required inputmode="numeric" placeholder="n">
                    <button type="submit">Add</button>
                </form>

                <label for="layout-{{ $location->id }}">Room layout image</label>
                <div class="cat-controls">
                    <form method="POST" action="{{ route('workstation.cgs.catalog.layout.store', $location) }}" enctype="multipart/form-data" class="cat-upload">
                        @csrf
                        <input type="hidden" name="_layout_room" value="{{ $location->id }}">
                        <input type="hidden" name="filter_block" value="{{ $selection['block'] }}">
                        <input type="hidden" name="filter_room" value="{{ $selection['room'] }}">
                        <div class="cat-file">
                            <label class="cat-file-btn" for="layout-{{ $location->id }}">Choose File</label>
                            <span class="cat-file-name" data-file-name>No file chosen</span>
                            <input type="file" class="cat-file-input" name="layout" id="layout-{{ $location->id }}" accept="image/png,image/jpeg,image/webp" required data-file-input>
                        </div>
                        <button type="submit">{{ $location->hasLayoutImage() ? 'Replace' : 'Upload layout image' }}</button>
                    </form>
                    @if ($location->hasLayoutImage())
                        <button type="button" class="btn-secondary" data-preview-layout="{{ route('workstation.cgs.catalog.layout', $location) }}" data-room="{{ $location->name }}">View</button>
                        <form method="POST" action="{{ route('workstation.cgs.catalog.layout.destroy', $location) }}">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="filter_block" value="{{ $selection['block'] }}">
                            <input type="hidden" name="filter_room" value="{{ $selection['room'] }}">
                            <button type="submit" class="btn-secondary">Remove</button>
                        </form>
                    @endif
                </div>
                <p class="cat-help">PNG, JPG or WebP, up to 5 MB.</p>
                @if ($errors->has('layout') && old('_layout_room') == $location->id)
                    <p class="field-error">{{ $errors->first('layout') }}</p>
                @endif
            </div>

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
                                            <input type="hidden" name="filter_block" value="{{ $selection['block'] }}">
                                            <input type="hidden" name="filter_room" value="{{ $selection['room'] }}">
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
        <input type="hidden" name="filter_block" value="{{ $selection['block'] }}">
        <input type="hidden" name="filter_room" value="{{ $selection['room'] }}">
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
        // Block / Room filter, in the browser: every room is already on the page; this shows the ones that match, keeps the Room list, the result line,
        // the forms' hidden filter fields and the URL (history.replaceState) in step, and never reloads. Without this script the <noscript> Apply
        // button submits the same GET form and the server marks the other rooms hidden.
        var filter = document.getElementById('cat-filter');
        if (filter) {
            var blockSel = filter.querySelector('#cat-block');
            var roomSel = filter.querySelector('#cat-room');
            var clearLink = filter.querySelector('.cat-clear');
            var result = document.getElementById('cat-result');
            var rooms = JSON.parse(filter.getAttribute('data-rooms') || '[]');
            var sections = [].slice.call(document.querySelectorAll('[data-cat-room]'));

            var fillRooms = function (block, selected) {
                roomSel.innerHTML = '';
                var all = document.createElement('option'); all.value = ''; all.textContent = 'All rooms'; roomSel.appendChild(all);
                rooms.filter(function (r) { return !block || r.b === block; }).forEach(function (r) {
                    var o = document.createElement('option'); o.value = r.r; o.textContent = r.r; roomSel.appendChild(o);
                });
                roomSel.value = selected || '';
            };

            var apply = function (block, room, fromUser) {
                if (room) { // a room outside the block is impossible; a room on its own selects its block
                    var found = rooms.filter(function (r) { return r.r === room; })[0];
                    if (!found) { room = ''; } else if (block && found.b !== block) { room = ''; } else if (!block) { block = found.b; }
                }
                blockSel.value = block;
                fillRooms(block, room);

                var count = 0, seats = 0, first = null;
                sections.forEach(function (sec) {
                    var show = (!block || sec.getAttribute('data-block') === block) && (!room || sec.getAttribute('data-room') === room);
                    sec.hidden = !show;
                    if (show) { count++; seats += parseInt(sec.getAttribute('data-seats'), 10) || 0; first = first || sec; }
                });
                result.textContent = 'Showing ' + count + (count === 1 ? ' room' : ' rooms') + ' (' + seats + (seats === 1 ? ' seat' : ' seats') + ').';

                document.querySelectorAll('input[name="filter_block"]').forEach(function (i) { i.value = block; });
                document.querySelectorAll('input[name="filter_room"]').forEach(function (i) { i.value = room; });
                clearLink.hidden = !(block || room);

                var url = new URL(window.location.href);
                block ? url.searchParams.set('block', block) : url.searchParams.delete('block');
                room ? url.searchParams.set('room', room) : url.searchParams.delete('room');
                window.history.replaceState(null, '', url.pathname + url.search + url.hash);

                if (fromUser && first) { // bring the first visible room into view only when it is off screen
                    var top = first.getBoundingClientRect().top;
                    if (top < 0 || top > window.innerHeight) { first.scrollIntoView({ block: 'start' }); }
                }
            };

            blockSel.addEventListener('change', function () { apply(blockSel.value, '', true); });
            roomSel.addEventListener('change', function () { apply(blockSel.value, roomSel.value, true); });
            clearLink.addEventListener('click', function (event) { event.preventDefault(); apply('', '', true); });
            filter.addEventListener('submit', function (event) { event.preventDefault(); apply(blockSel.value, roomSel.value, true); });
        }
        document.querySelectorAll('[data-file-input]').forEach(function (input) {
            input.addEventListener('change', function () {
                var name = input.parentNode.querySelector('[data-file-name]');
                name.textContent = input.files && input.files.length ? input.files[0].name : 'No file chosen';
            });
        });
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
